CREATE TABLE IF NOT EXISTS quote_policies (
    company_id BIGINT UNSIGNED PRIMARY KEY,
    self_approval_bps SMALLINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    CHECK (self_approval_bps <= 10000),
    FOREIGN KEY (company_id) REFERENCES companies (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS quotes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    opportunity_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    state ENUM('draft','pending','approved','rejected','contracted') NOT NULL DEFAULT 'draft',
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY quote_company_id (company_id, id),
    UNIQUE KEY quote_opportunity (company_id, opportunity_id),
    KEY quote_worklist (company_id, state, updated_at DESC, id DESC),
    FOREIGN KEY (company_id, opportunity_id) REFERENCES opportunities (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS quote_revisions (
    company_id BIGINT UNSIGNED NOT NULL,
    quote_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    gross_minor BIGINT UNSIGNED NOT NULL,
    discount_bps SMALLINT UNSIGNED NOT NULL,
    net_minor BIGINT UNSIGNED NOT NULL,
    expires_on DATE NOT NULL,
    terms VARCHAR(2000) NOT NULL,
    prepared_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (company_id, quote_id, revision),
    CHECK (discount_bps <= 10000),
    CHECK (net_minor > 0 AND net_minor <= gross_minor AND gross_minor <= 9000000000000),
    FOREIGN KEY (company_id, quote_id) REFERENCES quotes (company_id, id),
    FOREIGN KEY (prepared_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS quote_lines (
    company_id BIGINT UNSIGNED NOT NULL,
    quote_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    product_code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    product_version VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    description VARCHAR(160) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_minor BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (company_id, quote_id, revision, line_no),
    CHECK (quantity BETWEEN 1 AND 1000000),
    CHECK (unit_minor <= 9000000000000),
    FOREIGN KEY (company_id, quote_id, revision) REFERENCES quote_revisions (company_id, quote_id, revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS quote_review (
    company_id BIGINT UNSIGNED NOT NULL,
    quote_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    submitted_by BIGINT UNSIGNED NOT NULL,
    self_approval_bps SMALLINT UNSIGNED NOT NULL,
    policy_version INT UNSIGNED NOT NULL,
    submitted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    decision ENUM('automatic','approved','rejected'),
    decided_by BIGINT UNSIGNED,
    reason VARCHAR(400),
    decided_at DATETIME(6),
    PRIMARY KEY (company_id, quote_id, revision),
    CHECK (self_approval_bps <= 10000),
    CHECK ((decision IS NULL AND decided_by IS NULL AND decided_at IS NULL)
        OR (decision IS NOT NULL AND decided_by IS NOT NULL AND decided_at IS NOT NULL)),
    FOREIGN KEY (company_id, quote_id, revision) REFERENCES quote_revisions (company_id, quote_id, revision),
    FOREIGN KEY (submitted_by) REFERENCES users (id),
    FOREIGN KEY (decided_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS agreements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    legal_name VARCHAR(180) NOT NULL,
    legal_registration_ref VARCHAR(64),
    reference_no VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_company_id BIGINT UNSIGNED NOT NULL,
    quote_id BIGINT UNSIGNED NOT NULL,
    quote_revision INT UNSIGNED NOT NULL,
    owner_id BIGINT UNSIGNED NOT NULL,
    state ENUM('signed','fulfilling','renewing','ended') NOT NULL DEFAULT 'signed',
    active_revision INT UNSIGNED NOT NULL DEFAULT 1,
    latest_revision INT UNSIGNED NOT NULL DEFAULT 1,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY agreement_company_id (company_id, id),
    UNIQUE KEY agreement_reference (company_id, reference_no),
    UNIQUE KEY agreement_quote_allocation (source_company_id, quote_id, company_id),
    KEY agreement_account (company_id, account_id, id),
    KEY agreement_worklist (company_id, state, updated_at DESC, id DESC),
    KEY agreement_owner_worklist (company_id, owner_id, state, updated_at DESC, id DESC),
    CHECK (active_revision <= latest_revision),
    FOREIGN KEY (company_id, account_id) REFERENCES accounts (company_id, id),
    FOREIGN KEY (source_company_id, quote_id, quote_revision) REFERENCES quote_revisions (company_id, quote_id, revision),
    FOREIGN KEY (owner_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS agreement_versions (
    company_id BIGINT UNSIGNED NOT NULL,
    agreement_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    based_on INT UNSIGNED,
    decision ENUM('pending','signed','rejected') NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    value_minor BIGINT UNSIGNED NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    service_level ENUM('standard','priority','critical') NOT NULL,
    terms VARCHAR(2000) NOT NULL,
    change_reason VARCHAR(400),
    prepared_by BIGINT UNSIGNED NOT NULL,
    evidence_ref VARCHAR(160),
    decided_by BIGINT UNSIGNED,
    decision_reason VARCHAR(400),
    decided_at DATETIME(6),
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (company_id, agreement_id, revision),
    KEY agreement_expiry (company_id, decision, ends_on, agreement_id, revision),
    CHECK (value_minor > 0 AND value_minor <= 9000000000000),
    CHECK (starts_on <= ends_on),
    CHECK (based_on IS NULL OR based_on < revision),
    CHECK ((decision = 'pending' AND decided_by IS NULL AND decided_at IS NULL)
        OR (decision <> 'pending' AND decided_by IS NOT NULL AND decided_at IS NOT NULL)),
    CHECK (decision <> 'signed' OR evidence_ref IS NOT NULL),
    FOREIGN KEY (company_id, agreement_id) REFERENCES agreements (company_id, id),
    FOREIGN KEY (company_id, agreement_id, based_on) REFERENCES agreement_versions (company_id, agreement_id, revision),
    FOREIGN KEY (prepared_by) REFERENCES users (id),
    FOREIGN KEY (decided_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
