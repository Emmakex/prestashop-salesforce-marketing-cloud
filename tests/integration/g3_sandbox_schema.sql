-- CI SANDBOX ONLY. DO NOT EXECUTE IN A PRESTASHOP DATABASE.
-- The test runner refuses any connection other than g3_ci on 127.0.0.1.
CREATE TABLE g3_journal (
 accepted_sequence BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 source_namespace VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 shop_id INT UNSIGNED NOT NULL,
 entity_type VARCHAR(16) CHARACTER SET ascii NOT NULL,
 entity_id BIGINT UNSIGNED NOT NULL,
 dedupe_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 original_event_type VARCHAR(32) CHARACTER SET ascii NOT NULL,
 captured_at_utc CHAR(24) CHARACTER SET ascii NOT NULL,
 captured_at_precision VARCHAR(12) CHARACTER SET ascii NOT NULL,
 clock_source VARCHAR(40) CHARACTER SET ascii NOT NULL,
 clock_sync_verified TINYINT(1) NOT NULL DEFAULT 0,
 source_modified_provenance VARCHAR(32) CHARACTER SET ascii NOT NULL,
 UNIQUE KEY uq_g3_tenant_event (source_namespace, dedupe_fingerprint),
 KEY idx_g3_entity (source_namespace, shop_id, entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE g3_outbox (
 outbox_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 journal_sequence BIGINT UNSIGNED NOT NULL,
 status VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'blocked',
 UNIQUE KEY uq_g3_outbox_event (journal_sequence),
 CONSTRAINT fk_g3_outbox FOREIGN KEY (journal_sequence)
   REFERENCES g3_journal (accepted_sequence) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE g3_ledger (
 source_namespace VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 shop_id INT UNSIGNED NOT NULL,
 entity_type VARCHAR(16) CHARACTER SET ascii NOT NULL,
 entity_id BIGINT UNSIGNED NOT NULL,
 latest_accepted_sequence BIGINT UNSIGNED NOT NULL,
 status VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'blocked',
 PRIMARY KEY (source_namespace, shop_id, entity_type, entity_id),
 CONSTRAINT fk_g3_ledger FOREIGN KEY (latest_accepted_sequence)
   REFERENCES g3_journal (accepted_sequence) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
