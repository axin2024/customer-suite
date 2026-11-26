CREATE TABLE IF NOT EXISTS subscription_statements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED NOT NULL,
    billing_period CHAR(7) CHARACTER SET ascii NOT NULL,
    currency CHAR(3) CHARACTER SET ascii NOT NULL,
    revision INT UNSIGNED NOT NULL,
    state ENUM('draft','locked') NOT NULL,
    total_minor BIGINT NOT NULL,
    digest CHAR(64) CHARACTER SET ascii NOT NULL,
    prepared_by BIGINT UNSIGNED NOT NULL,
    locked_by BIGINT UNSIGNED,
    locked_at DATETIME(6),
    UNIQUE KEY monthly_account (company_id,subscription_id,billing_period),
    UNIQUE KEY statement_company_id (company_id,id),
    FOREIGN KEY (company_id,subscription_id) REFERENCES subscriptions(company_id,id),
    FOREIGN KEY (prepared_by) REFERENCES users(id),
    FOREIGN KEY (locked_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subscription_statement_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    statement_id BIGINT UNSIGNED NOT NULL,
    origin_period CHAR(7) CHARACTER SET ascii NOT NULL,
    origin_key VARCHAR(64) CHARACTER SET ascii NOT NULL,
    kind ENUM('fixed','overage','adjustment') NOT NULL,
    usage_id BIGINT UNSIGNED,
    plan_id BIGINT UNSIGNED NOT NULL,
    rate_rev INT UNSIGNED NOT NULL,
    quantity BIGINT NOT NULL,
    amount_minor BIGINT NOT NULL,
    detail JSON NOT NULL,
    UNIQUE KEY statement_line_key (company_id,statement_id,origin_period,origin_key),
    UNIQUE KEY statement_line_company_id (company_id,id),
    FOREIGN KEY (company_id,statement_id) REFERENCES subscription_statements(company_id,id),
    FOREIGN KEY (company_id,usage_id) REFERENCES subscription_usage(company_id,id),
    FOREIGN KEY (company_id,plan_id,rate_rev) REFERENCES subscription_rates(company_id,plan_id,revision)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subscription_usage_credits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    usage_id BIGINT UNSIGNED NOT NULL,
    units INT UNSIGNED NOT NULL,
    evidence VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason VARCHAR(400) NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY credit_reference (company_id,evidence),
    KEY credit_event (company_id,usage_id),
    FOREIGN KEY (company_id,usage_id) REFERENCES subscription_usage(company_id,id),
    FOREIGN KEY (actor_id) REFERENCES users(id),
    CHECK (units>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
