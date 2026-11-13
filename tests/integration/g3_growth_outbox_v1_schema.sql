-- G3 DRAFT V1 · DISPOSABLE CI SANDBOX ONLY.
-- No PrestaShop module upgrade, merchant DB connection or real marketing sends.
-- SQL is intentionally not shipped in the installable module.
CREATE TABLE g3_growth_outbox_v1 (
  accepted_sequence BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
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
  status VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'blocked',
  PRIMARY KEY (accepted_sequence),
  UNIQUE KEY uq_growth_event_v1 (source_namespace, dedupe_fingerprint),
  KEY idx_growth_entity_v1 (source_namespace, shop_id, entity_type, entity_id),
  CONSTRAINT chk_growth_outbox_blocked_v1 CHECK (status = 'blocked')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE g3_growth_entity_ledger_v1 (
  source_namespace VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  shop_id INT UNSIGNED NOT NULL,
  entity_type VARCHAR(16) CHARACTER SET ascii NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  latest_accepted_sequence BIGINT UNSIGNED NOT NULL,
  status VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'blocked',
  PRIMARY KEY (source_namespace, shop_id, entity_type, entity_id),
  KEY idx_growth_ledger_sequence_v1 (latest_accepted_sequence),
  CONSTRAINT fk_growth_ledger_outbox_v1 FOREIGN KEY (latest_accepted_sequence)
      REFERENCES g3_growth_outbox_v1 (accepted_sequence) ON DELETE RESTRICT,
  CONSTRAINT chk_growth_ledger_blocked_v1 CHECK (status = 'blocked')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
