CREATE TABLE IF NOT EXISTS accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    parent_company_id BIGINT UNSIGNED,
    parent_id BIGINT UNSIGNED,
    owner_id BIGINT UNSIGNED NOT NULL,
    merged_into_id BIGINT UNSIGNED,
    name VARCHAR(160) NOT NULL,
    registration_ref VARCHAR(100),
    state ENUM('active','frozen','merged') NOT NULL DEFAULT 'active',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY account_company_id (company_id, id),
    KEY accounts_worklist (company_id, state, updated_at DESC, id DESC),
    KEY accounts_owner_worklist (company_id, owner_id, state, updated_at DESC, id DESC),
    KEY account_identity (company_id, registration_ref, state),
    CHECK ((parent_company_id IS NULL) = (parent_id IS NULL)),
    FOREIGN KEY (company_id) REFERENCES companies (id),
    FOREIGN KEY (parent_company_id, parent_id) REFERENCES accounts (company_id, id),
    FOREIGN KEY (company_id, merged_into_id) REFERENCES accounts (company_id, id),
    FOREIGN KEY (owner_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS account_aliases (
    company_id BIGINT UNSIGNED NOT NULL,
    source VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    external_id VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    original_account_id BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(160) NOT NULL,
    original_owner_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (company_id, source, external_id),
    KEY alias_target (company_id, account_id),
    FOREIGN KEY (company_id, account_id) REFERENCES accounts (company_id, id),
    FOREIGN KEY (company_id, original_account_id) REFERENCES accounts (company_id, id),
    FOREIGN KEY (original_owner_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS contacts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    original_account_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    job_title VARCHAR(100) NOT NULL,
    email VARCHAR(254),
    phone VARCHAR(30),
    version INT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY contact_company_id (company_id, id),
    KEY contact_account (company_id, account_id, id),
    FOREIGN KEY (company_id, account_id) REFERENCES accounts (company_id, id),
    FOREIGN KEY (company_id, original_account_id) REFERENCES accounts (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS contact_permissions (
    company_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    purpose ENUM('export','marketing','success','service') NOT NULL,
    channel ENUM('email','phone') NOT NULL,
    allowed BOOLEAN NOT NULL,
    evidence VARCHAR(160) NOT NULL,
    changed_by BIGINT UNSIGNED NOT NULL,
    changed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (company_id, contact_id, purpose, channel),
    FOREIGN KEY (company_id, contact_id) REFERENCES contacts (company_id, id),
    FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS campaigns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(48) NOT NULL,
    name VARCHAR(160) NOT NULL,
    channel VARCHAR(48) NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY campaign_company_id (company_id, id),
    UNIQUE KEY campaign_code (company_id, code),
    FOREIGN KEY (company_id) REFERENCES companies (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS leads (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    owner_id BIGINT UNSIGNED,
    account_id BIGINT UNSIGNED,
    name VARCHAR(160) NOT NULL,
    email VARCHAR(254),
    phone VARCHAR(30),
    state ENUM('open','assigned','qualified','converted','closed') NOT NULL DEFAULT 'open',
    next_follow_up DATE,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY lead_company_id (company_id, id),
    KEY leads_worklist (company_id, state, updated_at DESC, id DESC),
    KEY leads_owner_worklist (company_id, owner_id, state, updated_at DESC, id DESC),
    KEY leads_followup (company_id, owner_id, state, next_follow_up, id),
    FOREIGN KEY (company_id) REFERENCES companies (id),
    FOREIGN KEY (owner_id) REFERENCES users (id),
    FOREIGN KEY (company_id, account_id) REFERENCES accounts (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS lead_intake_locks (
    company_id BIGINT UNSIGNED PRIMARY KEY,
    FOREIGN KEY (company_id) REFERENCES companies (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS lead_identity_keys (
    company_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('email','phone') NOT NULL,
    key_digest BINARY(32) NOT NULL,
    lead_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (company_id, kind, key_digest),
    KEY identity_lead (company_id, lead_id),
    FOREIGN KEY (company_id, lead_id) REFERENCES leads (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS lead_touches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    lead_id BIGINT UNSIGNED NOT NULL,
    campaign_id BIGINT UNSIGNED NOT NULL,
    source VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    external_id VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    imported_by BIGINT UNSIGNED NOT NULL,
    imported_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY touch_external_id (company_id, source, external_id),
    KEY touch_attribution (company_id, lead_id, occurred_at, id),
    KEY touch_campaign (company_id, campaign_id, occurred_at, id),
    FOREIGN KEY (company_id, lead_id) REFERENCES leads (company_id, id),
    FOREIGN KEY (company_id, campaign_id) REFERENCES campaigns (company_id, id),
    FOREIGN KEY (imported_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
