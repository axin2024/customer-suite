CREATE TABLE IF NOT EXISTS handovers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    agreement_id BIGINT UNSIGNED NOT NULL,
    agreement_revision INT UNSIGNED NOT NULL,
    owner_id BIGINT UNSIGNED NOT NULL,
    owner_revision INT UNSIGNED NOT NULL DEFAULT 1,
    scope_revision INT UNSIGNED NOT NULL DEFAULT 1,
    state ENUM('planning','executing','accepted') NOT NULL DEFAULT 'planning',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY handover_company_id (company_id, id),
    UNIQUE KEY handover_agreement_revision (company_id, agreement_id, agreement_revision),
    UNIQUE KEY handover_acceptance_reference (company_id, id, agreement_id, agreement_revision),
    KEY handover_worklist (company_id, state, updated_at DESC, id DESC),
    KEY handover_owner_worklist (company_id, owner_id, state, updated_at DESC, id DESC),
    FOREIGN KEY (company_id, agreement_id, agreement_revision) REFERENCES agreement_versions (company_id, agreement_id, revision),
    FOREIGN KEY (owner_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS handover_scopes (
    company_id BIGINT UNSIGNED NOT NULL,
    handover_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    scope_summary VARCHAR(2000) NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    contact_name VARCHAR(120) NOT NULL,
    contact_version INT UNSIGNED NOT NULL,
    contact_title VARCHAR(120) NOT NULL,
    changed_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (company_id, handover_id, revision),
    FOREIGN KEY (company_id, handover_id) REFERENCES handovers (company_id, id),
    FOREIGN KEY (company_id, contact_id) REFERENCES contacts (company_id, id),
    FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS handover_confirmations (
    company_id BIGINT UNSIGNED NOT NULL,
    handover_id BIGINT UNSIGNED NOT NULL,
    scope_revision INT UNSIGNED NOT NULL,
    side ENUM('sales','delivery') NOT NULL,
    owner_revision INT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    confirmed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (company_id, handover_id, scope_revision, side, owner_revision),
    CHECK ((side = 'sales' AND owner_revision = 0) OR (side = 'delivery' AND owner_revision > 0)),
    FOREIGN KEY (company_id, handover_id, scope_revision) REFERENCES handover_scopes (company_id, handover_id, revision),
    FOREIGN KEY (actor_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS delivery_milestones (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    handover_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    due_on DATE NOT NULL,
    state ENUM('scheduled','delayed','completed') NOT NULL DEFAULT 'scheduled',
    completed_at DATETIME(6),
    UNIQUE KEY milestone_company_handover_id (company_id, handover_id, id),
    KEY milestone_due (company_id, handover_id, state, due_on, id),
    FOREIGN KEY (company_id, handover_id) REFERENCES handovers (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS delivery_delays (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    handover_id BIGINT UNSIGNED NOT NULL,
    milestone_id BIGINT UNSIGNED NOT NULL,
    old_due_on DATE NOT NULL,
    new_due_on DATE NOT NULL,
    impact VARCHAR(800) NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY delay_company_handover_id (company_id, handover_id, id),
    KEY delay_milestone (company_id, handover_id, milestone_id, id),
    CHECK (new_due_on > old_due_on),
    FOREIGN KEY (company_id, handover_id, milestone_id) REFERENCES delivery_milestones (company_id, handover_id, id),
    FOREIGN KEY (actor_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS delivery_issues (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    handover_id BIGINT UNSIGNED NOT NULL,
    delay_id BIGINT UNSIGNED,
    title VARCHAR(240) NOT NULL,
    resolution VARCHAR(400),
    resolved_by BIGINT UNSIGNED,
    resolved_at DATETIME(6),
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY delivery_issue_list (company_id, handover_id, resolved_at, id),
    FOREIGN KEY (company_id, handover_id) REFERENCES handovers (company_id, id),
    FOREIGN KEY (company_id, handover_id, delay_id) REFERENCES delivery_delays (company_id, handover_id, id),
    FOREIGN KEY (created_by) REFERENCES users (id),
    FOREIGN KEY (resolved_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS delivery_acceptances (
    company_id BIGINT UNSIGNED NOT NULL,
    handover_id BIGINT UNSIGNED NOT NULL,
    agreement_id BIGINT UNSIGNED NOT NULL,
    agreement_revision INT UNSIGNED NOT NULL,
    scope_revision INT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    evidence_reference VARCHAR(160) NOT NULL,
    confirmation_at DATETIME(6) NOT NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    file_name VARCHAR(180) NOT NULL,
    media_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    byte_length INT UNSIGNED NOT NULL,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    body MEDIUMBLOB NOT NULL,
    PRIMARY KEY (company_id, handover_id),
    CHECK (byte_length BETWEEN 1 AND 2097152),
    FOREIGN KEY (company_id, handover_id, agreement_id, agreement_revision)
        REFERENCES handovers (company_id, id, agreement_id, agreement_revision),
    FOREIGN KEY (company_id, handover_id, scope_revision) REFERENCES handover_scopes (company_id, handover_id, revision),
    FOREIGN KEY (company_id, contact_id) REFERENCES contacts (company_id, id),
    FOREIGN KEY (recorded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
