CREATE TABLE review_delegations (
    company_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('quote','agreement') NOT NULL,
    object_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    granted_by BIGINT UNSIGNED NOT NULL,
    delegate_id BIGINT UNSIGNED NOT NULL,
    delegation_version INT UNSIGNED NOT NULL DEFAULT 1,
    expires_at DATETIME(6) NOT NULL,
    state ENUM('assigned','revoked','used','superseded') NOT NULL DEFAULT 'assigned',
    PRIMARY KEY (company_id,kind,object_id,revision),
    FOREIGN KEY (company_id) REFERENCES companies (id),
    FOREIGN KEY (granted_by) REFERENCES users (id),
    FOREIGN KEY (delegate_id) REFERENCES users (id),
    CHECK (delegate_id <> granted_by)
) ENGINE=InnoDB;
