CREATE TABLE IF NOT EXISTS service_calendars (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    timezone VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    weekly_hours JSON NOT NULL,
    exception_hours JSON NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY calendar_company_id (company_id, id),
    UNIQUE KEY calendar_revision (company_id, code, revision),
    FOREIGN KEY (company_id) REFERENCES companies (id),
    FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS service_policies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    service_level ENUM('standard','priority','critical') NOT NULL,
    revision INT UNSIGNED NOT NULL,
    effective_from DATETIME(6) NOT NULL,
    calendar_id BIGINT UNSIGNED NOT NULL,
    priority_limits JSON NOT NULL,
    pause_for_customer BOOLEAN NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY policy_company_id (company_id, id),
    UNIQUE KEY policy_revision (company_id, service_level, revision),
    UNIQUE KEY policy_effective (company_id, service_level, effective_from),
    FOREIGN KEY (company_id, calendar_id) REFERENCES service_calendars (company_id, id),
    FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS service_queues (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(120) NOT NULL,
    UNIQUE KEY service_queue_company_id (company_id, id),
    UNIQUE KEY service_queue_code (company_id, code),
    FOREIGN KEY (company_id) REFERENCES companies (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS service_routes (
    company_id BIGINT UNSIGNED NOT NULL,
    category ENUM('incident','request','billing','onboarding') NOT NULL,
    queue_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (company_id, category),
    FOREIGN KEY (company_id, queue_id) REFERENCES service_queues (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS service_contract_grants (
    company_id BIGINT UNSIGNED NOT NULL,
    agreement_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    granted_by BIGINT UNSIGNED NOT NULL,
    granted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (company_id, agreement_id, user_id),
    KEY service_grant_user (company_id, user_id, agreement_id),
    FOREIGN KEY (company_id, agreement_id) REFERENCES agreements (company_id, id),
    FOREIGN KEY (user_id) REFERENCES users (id),
    FOREIGN KEY (granted_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS service_tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    agreement_id BIGINT UNSIGNED NOT NULL,
    agreement_revision INT UNSIGNED NOT NULL,
    queue_id BIGINT UNSIGNED,
    owner_id BIGINT UNSIGNED,
    requested_by BIGINT UNSIGNED NOT NULL,
    policy_id BIGINT UNSIGNED,
    title VARCHAR(180) NOT NULL,
    description VARCHAR(3000) NOT NULL,
    category ENUM('incident','request','billing','onboarding') NOT NULL,
    priority ENUM('P1','P2','P3','P4') NOT NULL,
    state ENUM('review','open','waiting','resolved','closed') NOT NULL,
    review_reason ENUM('expired','not_started','ended','missing_policy','unrouted'),
    wait_reason VARCHAR(400),
    close_reason VARCHAR(400),
    escalation_level TINYINT UNSIGNED NOT NULL DEFAULT 0,
    received_at DATETIME(6) NOT NULL,
    last_event_at DATETIME(6) NOT NULL,
    responded_at DATETIME(6),
    resolved_at DATETIME(6),
    closed_at DATETIME(6),
    version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY service_ticket_company_id (company_id, id),
    KEY service_ticket_worklist (company_id, state, updated_at DESC, id DESC),
    KEY service_ticket_queue_worklist (company_id, state, queue_id, updated_at DESC, id DESC),
    KEY service_ticket_owner_worklist (company_id, owner_id, state, updated_at DESC, id DESC),
    KEY service_ticket_agreement (company_id, agreement_id, state, updated_at DESC, id DESC),
    KEY service_ticket_account (company_id, account_id, state, updated_at DESC, id DESC),
    FOREIGN KEY (company_id, account_id) REFERENCES accounts (company_id, id),
    FOREIGN KEY (company_id, agreement_id, agreement_revision) REFERENCES agreement_versions (company_id, agreement_id, revision),
    FOREIGN KEY (company_id, queue_id) REFERENCES service_queues (company_id, id),
    FOREIGN KEY (company_id, policy_id) REFERENCES service_policies (company_id, id),
    FOREIGN KEY (owner_id) REFERENCES users (id),
    FOREIGN KEY (requested_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS service_events (
    company_id BIGINT UNSIGNED NOT NULL,
    source VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    external_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    result JSON,
    ticket_id BIGINT UNSIGNED,
    received_by BIGINT UNSIGNED NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (company_id, source, external_id),
    UNIQUE KEY service_intake_ticket (company_id, ticket_id),
    FOREIGN KEY (company_id) REFERENCES companies (id),
    FOREIGN KEY (company_id, ticket_id) REFERENCES service_tickets (company_id, id),
    FOREIGN KEY (received_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS sla_clocks (
    company_id BIGINT UNSIGNED NOT NULL,
    ticket_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('response','resolution') NOT NULL,
    target_micros BIGINT UNSIGNED NOT NULL,
    elapsed_micros BIGINT UNSIGNED NOT NULL DEFAULT 0,
    running_since DATETIME(6),
    deadline_at DATETIME(6),
    stopped_at DATETIME(6),
    PRIMARY KEY (company_id, ticket_id, kind),
    KEY sla_due (company_id, deadline_at, ticket_id, kind),
    CHECK (target_micros > 0),
    FOREIGN KEY (company_id, ticket_id) REFERENCES service_tickets (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS service_escalations (
    company_id BIGINT UNSIGNED NOT NULL,
    ticket_id BIGINT UNSIGNED NOT NULL,
    clock_kind ENUM('response','resolution') NOT NULL,
    missed_deadline DATETIME(6) NOT NULL,
    detected_at DATETIME(6) NOT NULL,
    detected_by BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (company_id, ticket_id),
    FOREIGN KEY (company_id, ticket_id, clock_kind) REFERENCES sla_clocks (company_id, ticket_id, kind),
    FOREIGN KEY (detected_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ticket_notes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    ticket_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    body VARCHAR(2000) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY service_ticket_notes (company_id, ticket_id, id),
    FOREIGN KEY (company_id, ticket_id) REFERENCES service_tickets (company_id, id),
    FOREIGN KEY (actor_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
