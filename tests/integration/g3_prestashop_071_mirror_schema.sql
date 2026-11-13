-- G3 disposable CI fixture only. Mirrors real PrestaShop 0.7.1 table columns.
-- NEVER run in merchant databases, and never ship as a module migration.
CREATE TABLE g3_growth_journal (
 accepted_sequence BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 source_namespace VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 shop_id INT UNSIGNED NOT NULL,
 entity_type VARCHAR(16) NOT NULL,
 entity_id BIGINT UNSIGNED NOT NULL,
 dedupe_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 original_event_type VARCHAR(32) NOT NULL,
 captured_at_utc CHAR(24) NOT NULL,
 captured_at_precision VARCHAR(12) NOT NULL,
 clock_source VARCHAR(40) NOT NULL,
 clock_sync_verified TINYINT(1) NOT NULL DEFAULT 0,
 source_modified_provenance VARCHAR(32) NOT NULL,
 legacy_queue_id BIGINT UNSIGNED DEFAULT NULL,
 PRIMARY KEY (accepted_sequence),
 UNIQUE KEY uq_g3_growth_tenant_dedupe (source_namespace, dedupe_fingerprint),
 UNIQUE KEY uq_g3_growth_queue_link (legacy_queue_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Exact 0.7.1 column/key contract, with test-only g3_ prefix.
CREATE TABLE g3_sfmc_queue (
 id_sfmc_queue BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 event_type VARCHAR(64) NOT NULL,
 entity_type VARCHAR(32) NOT NULL,
 entity_id VARCHAR(64) NOT NULL,
 dedupe_key VARCHAR(191) NOT NULL,
 payload LONGTEXT NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'pending',
 attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 available_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL,
 processed_at DATETIME NULL,
 last_error TEXT NULL,
 lock_token VARCHAR(64) NULL,
 locked_at DATETIME NULL,
 PRIMARY KEY (id_sfmc_queue),
 UNIQUE KEY uniq_sfmc_dedupe (dedupe_key),
 KEY idx_sfmc_queue_ready (status, available_at),
 KEY idx_sfmc_queue_lock (lock_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE g3_sfmc_customer_sync (
 id_sfmc_customer_sync BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 id_shop INT UNSIGNED NOT NULL,
 id_customer INT UNSIGNED NOT NULL,
 contact_key VARCHAR(191) NOT NULL,
 payload_hash CHAR(40) NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'pending',
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY (id_sfmc_customer_sync),
 UNIQUE KEY uniq_sfmc_customer_shop (id_shop, id_customer),
 UNIQUE KEY uniq_sfmc_customer_contact (contact_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE g3_sfmc_order_sync (
 id_sfmc_order_sync BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 id_shop INT UNSIGNED NOT NULL,
 id_order INT UNSIGNED NOT NULL,
 id_cart INT UNSIGNED NOT NULL DEFAULT 0,
 contact_key VARCHAR(191) NOT NULL DEFAULT '',
 payload_hash CHAR(40) NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'pending',
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY (id_sfmc_order_sync),
 UNIQUE KEY uniq_sfmc_order_shop (id_shop, id_order),
 KEY idx_sfmc_order_cart (id_shop, id_cart),
 KEY idx_sfmc_order_contact (contact_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
