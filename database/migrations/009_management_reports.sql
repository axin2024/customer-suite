CREATE TABLE IF NOT EXISTS opportunity_amount_history (
    company_id BIGINT UNSIGNED NOT NULL,
    opportunity_id BIGINT UNSIGNED NOT NULL,
    opportunity_version INT UNSIGNED NOT NULL,
    value_minor BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (company_id, opportunity_id, opportunity_version),
    KEY amount_at_time (company_id, opportunity_id, recorded_at DESC, opportunity_version DESC),
    CHECK (value_minor <= 9000000000000),
    FOREIGN KEY (company_id, opportunity_id) REFERENCES opportunities (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT IGNORE INTO opportunity_amount_history (company_id,opportunity_id,opportunity_version,value_minor,recorded_at)
SELECT o.company_id,o.id,1,o.value_minor,h.occurred_at
FROM opportunities o JOIN stage_history h ON h.company_id = o.company_id AND h.opportunity_id = o.id AND h.opportunity_version = 1
WHERE NOT EXISTS (
    SELECT 1 FROM audit_events e WHERE e.company_id = o.company_id AND e.kind = 'opportunity'
        AND e.object_id = o.id AND e.action = 'opportunity.products'
);

INSERT IGNORE INTO opportunity_amount_history (company_id,opportunity_id,opportunity_version,value_minor,recorded_at)
SELECT e.company_id,e.object_id,e.version,CAST(e.details->>'$.value_minor' AS UNSIGNED),e.occurred_at
FROM audit_events e JOIN opportunities o ON o.company_id = e.company_id AND o.id = e.object_id
WHERE e.kind = 'opportunity' AND e.action = 'opportunity.products' AND JSON_TYPE(e.details->'$.value_minor') = 'INTEGER';

CREATE TABLE IF NOT EXISTS report_fx_sets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    rate_date DATE NOT NULL,
    source VARCHAR(160) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY report_fx_company_id (company_id, id),
    FOREIGN KEY (company_id) REFERENCES companies (id),
    FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS report_fx_rates (
    company_id BIGINT UNSIGNED NOT NULL,
    set_id BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    rate DECIMAL(12,8) NOT NULL,
    PRIMARY KEY (company_id, set_id, currency),
    CHECK (rate > 0),
    FOREIGN KEY (company_id, set_id) REFERENCES report_fx_sets (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS report_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    anchor_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(120) NOT NULL,
    as_of DATETIME(6) NOT NULL,
    cohort_start DATE,
    fx_set_id BIGINT UNSIGNED NOT NULL,
    captured_by BIGINT UNSIGNED NOT NULL,
    captured_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY report_anchor_time (anchor_id, id DESC),
    FOREIGN KEY (anchor_id, fx_set_id) REFERENCES report_fx_sets (company_id, id),
    FOREIGN KEY (captured_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS report_companies (
    report_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (report_id, company_id),
    KEY reports_for_company (company_id, report_id DESC),
    FOREIGN KEY (report_id) REFERENCES report_snapshots (id),
    FOREIGN KEY (company_id) REFERENCES companies (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS report_rows (
    report_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('opportunity','agreement') NOT NULL,
    object_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    source_version INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    stage ENUM('discovery','qualified','proposal','negotiation','won','lost'),
    stage_event_id BIGINT UNSIGNED,
    furthest_step TINYINT UNSIGNED,
    amount_version INT UNSIGNED,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    value_minor BIGINT UNSIGNED,
    fx_rate DECIMAL(12,8),
    cny_minor DECIMAL(20,0),
    first_touch_id BIGINT UNSIGNED,
    first_channel VARCHAR(48),
    recent_touch_id BIGINT UNSIGNED,
    recent_channel VARCHAR(48),
    PRIMARY KEY (report_id, company_id, kind, object_id),
    CHECK (furthest_step BETWEEN 1 AND 5),
    CHECK (cny_minor >= 0),
    FOREIGN KEY (report_id, company_id) REFERENCES report_companies (report_id, company_id),
    FOREIGN KEY (company_id, account_id) REFERENCES accounts (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
