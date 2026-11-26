ALTER TABLE memberships MODIFY role ENUM('manager','marketing','sales','delivery','service','success','approver','meter','finance','subscriber') NOT NULL;

CREATE TABLE IF NOT EXISTS subscription_viewers (
    company_id BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    granted_by BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (company_id,subscription_id,user_id),
    FOREIGN KEY (company_id,subscription_id) REFERENCES subscriptions(company_id,id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (granted_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subscription_usage (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED NOT NULL,
    source VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    metric VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    units INT UNSIGNED NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    local_date DATE NOT NULL,
    billing_period CHAR(7) CHARACTER SET ascii NOT NULL,
    digest CHAR(64) CHARACTER SET ascii NOT NULL,
    received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY usage_business_event (company_id,source,event_id),
    UNIQUE KEY usage_company_id (company_id,id),
    KEY usage_period (company_id,subscription_id,billing_period,metric,local_date),
    FOREIGN KEY (company_id,subscription_id) REFERENCES subscriptions(company_id,id),
    CHECK (units > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS usage_request_keys (
    company_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    digest CHAR(64) CHARACTER SET ascii NOT NULL,
    PRIMARY KEY (company_id,actor_id,request_key),
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS usage_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subscription_id BIGINT UNSIGNED NOT NULL,
    usage_id BIGINT UNSIGNED,
    digest CHAR(64) CHARACTER SET ascii NOT NULL,
    payload JSON NOT NULL,
    disposition ENUM('accepted','duplicate','conflict') NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY usage_attempt_identity (company_id,actor_id,request_key,digest),
    KEY usage_inbox (company_id,subscription_id,disposition,id),
    FOREIGN KEY (company_id,actor_id,request_key) REFERENCES usage_request_keys(company_id,actor_id,request_key),
    FOREIGN KEY (company_id,subscription_id) REFERENCES subscriptions(company_id,id),
    FOREIGN KEY (company_id,usage_id) REFERENCES subscription_usage(company_id,id)
) ENGINE=InnoDB;
