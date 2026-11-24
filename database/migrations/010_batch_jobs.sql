CREATE TABLE IF NOT EXISTS batch_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    kind ENUM('lead.reassign','outreach.process') NOT NULL,
    parameters JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY batch_company_id (company_id, id),
    KEY batch_requester (company_id, requested_by, id DESC),
    FOREIGN KEY (company_id) REFERENCES companies (id),
    FOREIGN KEY (requested_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS batch_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    batch_id BIGINT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    selected_version INT UNSIGNED NOT NULL,
    subject_version INT UNSIGNED NOT NULL,
    renewal_id BIGINT UNSIGNED,
    state ENUM('pending','completed','rejected','retryable','waiting_external') NOT NULL DEFAULT 'pending',
    reason VARCHAR(255),
    result JSON,
    receipt_seed CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY batch_subject (company_id, batch_id, subject_id),
    UNIQUE KEY batch_position (company_id, batch_id, position),
    KEY batch_claim (company_id, state, id, batch_id),
    KEY batch_outreach (company_id, subject_id, state, renewal_id),
    FOREIGN KEY (company_id, batch_id) REFERENCES batch_jobs (company_id, id),
    FOREIGN KEY (company_id, renewal_id) REFERENCES renewals (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
