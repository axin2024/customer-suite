CREATE TABLE IF NOT EXISTS subscription_disputes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    statement_id BIGINT UNSIGNED NOT NULL,
    usage_id BIGINT UNSIGNED NOT NULL,
    units INT UNSIGNED NOT NULL,
    disputed_minor BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(400) NOT NULL,
    raised_by BIGINT UNSIGNED NOT NULL,
    state ENUM('open','held','credited','denied') NOT NULL DEFAULT 'open',
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    decided_by BIGINT UNSIGNED,
    decision_reason VARCHAR(400),
    credit_id BIGINT UNSIGNED,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY disputed_event (company_id,statement_id,usage_id),
    KEY disputed_worklist (company_id,state,id),
    FOREIGN KEY (company_id,statement_id) REFERENCES subscription_statements(company_id,id),
    FOREIGN KEY (company_id,usage_id) REFERENCES subscription_usage(company_id,id),
    FOREIGN KEY (credit_id) REFERENCES subscription_usage_credits(id),
    FOREIGN KEY (raised_by) REFERENCES users(id),
    FOREIGN KEY (decided_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS statement_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    statement_id BIGINT UNSIGNED NOT NULL,
    receipt_no VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    received_on DATE NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY external_payment (company_id,receipt_no),
    KEY statement_receipts (company_id,statement_id,id),
    FOREIGN KEY (company_id,statement_id) REFERENCES subscription_statements(company_id,id),
    FOREIGN KEY (actor_id) REFERENCES users(id),
    CHECK(amount_minor>0)
) ENGINE=InnoDB;
