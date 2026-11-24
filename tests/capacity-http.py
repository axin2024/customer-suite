"""Read the customer and ticket worklists against an isolated capacity demo."""

import argparse
import concurrent.futures
import dataclasses
import http.cookiejar
import json
import math
import os
import pathlib
import re
import time
import urllib.error
import urllib.parse
import urllib.request
from collections import Counter, defaultdict


@dataclasses.dataclass(frozen=True)
class Check:
    name: str
    role: str
    path: str
    status: int = 200
    prefix: str = ""
    forbidden: str = ""
    field: str = ""


class Session:
    def __init__(self, base, email, password):
        self.base = base
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
        )
        status, initial = self.request("/api.php?resource=session")
        if status != 200:
            raise RuntimeError(f"Session start returned HTTP {status}")
        status, body = self.request(
            "/api.php?resource=session",
            json.dumps({"email": email, "password": password}).encode("utf-8"),
            {"Content-Type": "application/json", "X-CSRF-Token": json.loads(initial)["csrf"]},
        )
        if status != 200 or json.loads(body)["user"]["email"] != email:
            raise RuntimeError(f"Demo login failed with HTTP {status}")

    def request(self, path, data=None, headers=None):
        request = urllib.request.Request(self.base + path, data, headers or {})
        try:
            with self.opener.open(request, timeout=5) as response:
                return response.status, response.read().decode("utf-8-sig")
        except urllib.error.HTTPError as error:
            with error:
                return error.code, error.read().decode("utf-8-sig")


def api(resource, company, **filters):
    return "/api.php?" + urllib.parse.urlencode({"resource": resource, "company": company, **filters})


def prepare(manifest, sample_session):
    a, c = manifest["cohorts"]["a"], manifest["cohorts"]["c"]
    ac, cc = a["company"], c["company"]
    tickets = api("tickets", cc, queue=c["queue"])
    status, body = sample_session.request(tickets)
    if status != 200 or not json.loads(body).get("next_cursor"):
        raise RuntimeError("The service user's first ticket page needs a real next cursor")
    cursor = json.loads(body)["next_cursor"]
    return [
        Check("a_accounts_api", "a", api("accounts", ac), prefix=a["account_prefix"], field="name", forbidden=c["account_prefix"]),
        Check("a_accounts_html", "a", f"/?company={ac}&view=accounts", prefix=a["account_prefix"], forbidden=c["account_prefix"]),
        Check("a_tickets_api", "manager", api("tickets", ac, queue=a["queue"]), prefix=a["ticket_prefix"], field="title", forbidden=c["ticket_prefix"]),
        Check("c_tickets_api", "c", tickets, prefix=c["ticket_prefix"], field="title", forbidden=a["ticket_prefix"]),
        Check("c_tickets_api_page_two", "c", api("tickets", cc, queue=c["queue"], cursor=cursor),
              prefix=c["ticket_prefix"], field="title", forbidden=a["ticket_prefix"]),
        Check("c_tickets_html", "c", f"/?company={cc}&view=tickets&queue={c['queue']}", prefix=c["ticket_prefix"], forbidden=a["ticket_prefix"]),
        Check("deny_other_company", "c", api("accounts", ac), 404, forbidden=a["account_prefix"]),
        Check("deny_foreign_ticket", "c", api("tickets", cc, id=a["tickets"]["sample_id"]), 404, forbidden=a["ticket_prefix"]),
        Check("deny_foreign_export", "c", f"/export.php?company={cc}&report={manifest['report_id']}&scope_company={ac}", 404,
              forbidden="DEMO-AGREEMENT-A"),
        Check("deny_foreign_attachment", "c", f"/download.php?company={ac}&handover={manifest['handover_id']}", 404, forbidden="演示签收记录"),
    ]


def validate(check, status, body):
    if (check.forbidden and check.forbidden in body) or (check.status == 404 and 200 <= status < 300):
        return "scope_leak"
    if status != check.status:
        return f"http_{status}_expected_{check.status}"
    if check.prefix and check.prefix not in body:
        return "missing_expected_rows"
    if check.field:
        rows = json.loads(body)["items"]
        if not rows or not all(row[check.field].startswith(check.prefix) for row in rows):
            return "scope_leak_or_empty_page"
    return None


def run_worker(checks, sessions, deadline, offset):
    times = defaultdict(list)
    errors = Counter()
    index = offset
    while time.monotonic() < deadline:
        check = checks[index % len(checks)]
        index += 1
        start = time.perf_counter()
        try:
            status, body = sessions[check.role].request(check.path)
            elapsed = (time.perf_counter() - start) * 1000
            error = validate(check, status, body)
            if error is None:
                times[check.name].append(elapsed)
            else:
                errors[f"{check.name}:{error}"] += 1
        except (OSError, ValueError, KeyError) as error:
            errors[f"{check.name}:{type(error).__name__}"] += 1
    return times, errors


def window(pool, checks, clients, seconds):
    times = defaultdict(list)
    errors = Counter()
    start = time.monotonic()
    jobs = [pool.submit(run_worker, checks, client, start + seconds, index) for index, client in enumerate(clients)]
    for job in concurrent.futures.as_completed(jobs):
        sample, failures = job.result()
        for case, values in sample.items():
            times[case].extend(values)
        errors.update(failures)
    return times, errors, round(time.monotonic() - start, 3)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("manifest", type=pathlib.Path)
    parser.add_argument("--url", default="http://127.0.0.1:8080")
    parser.add_argument("--clients", type=int, default=8)
    parser.add_argument("--warmup", type=int, default=8)
    parser.add_argument("--seconds", type=int, default=30)
    options = parser.parse_args()
    if not re.fullmatch(r"http://127\.0\.0\.1:[0-9]{2,5}", options.url):
        parser.error("use the loopback address of the isolated demo")
    if not 1 <= options.clients <= 32 or options.warmup < 1 or options.seconds < 1:
        parser.error("require 1–32 clients, and positive warmup and measurement windows")
    password = os.environ["CRM_DEMO_PASSWORD"]
    demo = json.loads(options.manifest.read_text(encoding="utf-8-sig"))
    if demo["sample_counts"] != {"customers": 10000, "tickets": 50000}:
        raise RuntimeError("The required fixture must have 10,000 customers and 50,000 tickets")
    emails = {role: cohort["user"] for role, cohort in demo["cohorts"].items()}
    emails["manager"] = demo["manager"]
    clients = [{role: Session(options.url, email, password) for role, email in emails.items()} for _ in range(options.clients)]
    checks = prepare(demo, clients[0]["c"])
    with concurrent.futures.ThreadPoolExecutor(max_workers=options.clients) as pool:
        _, warmup_errors, warmup = window(pool, checks, clients, options.warmup)
        times, errors, measured = window(pool, checks, clients, options.seconds)
    report = {}
    for check in checks:
        values = sorted(times[check.name])
        report[check.name] = {"samples": len(values), "status": check.status,
                              "p95_ms": round(values[math.ceil(len(values) * .95) - 1], 3) if values else None}
    passed = not errors and not warmup_errors and all(row["samples"] >= 20 and row["p95_ms"] <= 2000 for row in report.values())
    print(json.dumps({"started_fixture": demo["recorded_at"], "engine": demo["engine"], "dataset": demo["sample_counts"],
                      "clients": options.clients, "warmup_seconds": warmup, "measurement_seconds": measured,
                      "cases": report, "warmup_errors": warmup_errors, "errors": errors, "passed": passed}, indent=2))
    return 0 if passed else 1


if __name__ == "__main__":
    raise SystemExit(main())
