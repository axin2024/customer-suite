CREATE TABLE IF NOT EXISTS subscription_plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    UNIQUE KEY plan_company_id (company_id,id),
    UNIQUE KEY plan_code (company_id,code),
    FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subscription_rates (
    company_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    effective_on DATE NOT NULL,
    name VARCHAR(160) NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    monthly_minor BIGINT UNSIGNED NOT NULL,
    service_level ENUM('standard','priority','critical') NOT NULL,
    published_by BIGINT UNSIGNED NOT NULL,
    published_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (company_id,plan_id,revision),
    UNIQUE KEY effective_price (company_id,plan_id,effective_on),
    FOREIGN KEY (company_id,plan_id) REFERENCES subscription_plans(company_id,id),
    FOREIGN KEY (published_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subscription_rate_metrics (
    company_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    metric VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    included_units BIGINT UNSIGNED NOT NULL,
    overage_minor BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (company_id,plan_id,revision,metric),
    FOREIGN KEY (company_id,plan_id,revision) REFERENCES subscription_rates(company_id,plan_id,revision)
) ENGINE=InnoDB;
