CREATE TABLE IF NOT EXISTS login_throttle (
    bucket_digest BINARY(32) PRIMARY KEY,
    window_start DATETIME NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL,
    KEY throttle_expiry (window_start)
) ENGINE=InnoDB;
