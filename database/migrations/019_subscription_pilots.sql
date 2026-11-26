CREATE TABLE IF NOT EXISTS subscription_pilots (
    company_id BIGINT UNSIGNED PRIMARY KEY,
    state ENUM('setup','active','draining') NOT NULL DEFAULT 'setup',
    version INT UNSIGNED NOT NULL DEFAULT 0,
    last_agreement_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subscription_contract_checks (
    company_id BIGINT UNSIGNED NOT NULL,
    agreement_id BIGINT UNSIGNED NOT NULL,
    checked_version INT UNSIGNED NOT NULL,
    issue VARCHAR(64) CHARACTER SET ascii,
    PRIMARY KEY (company_id,agreement_id),
    FOREIGN KEY (company_id,agreement_id) REFERENCES agreements(company_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS usage_conflict_reviews (
    company_id BIGINT UNSIGNED NOT NULL,
    attempt_id BIGINT UNSIGNED NOT NULL,
    reviewer_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(400) NOT NULL,
    reviewed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (company_id,attempt_id),
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (attempt_id) REFERENCES usage_attempts(id),
    FOREIGN KEY (reviewer_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
