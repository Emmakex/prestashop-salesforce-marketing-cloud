-- G3 / ABH Barcelona Confort: audit of storage-time anomalies, not absolute clock proof.
-- READ ONLY, AGGREGATE ONLY. Production shop 1, existing prefix bc_.
-- Versioned against PrestaShop 1.7.8.11; no inserts, updates, exports, logs.
-- Returns a timezone-configuration row set and TWO aggregate rows only.
--
-- The first SELECT exposes non-secret PS_TIMEZONE settings by shop scope.
-- A global or current PS_TIMEZONE is NOT evidence of historical write zone.
-- Current DB session clock may differ from PHP application clock.
--
-- Core PrestaShop 1.7.8.11 ObjectModel::add/update sets date_upd/date_add
-- from PHP date('Y-m-d H:i:s'); config/config.inc.php sets PHP timezone
-- from PS_TIMEZONE in the normal bootstrap. Plugins, CLI, SQL imports,
-- bulk jobs, timezone changes and override paths may differ.
--
-- DST interval boundaries below are Europe/Madrid in 2025 and 2026,
-- DIAGNOSTIC ONLY. A row in the March 02:xx gap or October 02:xx overlap
-- cannot be converted into a trusted UTC instant by this query.
-- A zero count DOES NOT prove provenance or validate a UTC stream.
-- Do not publish v0.3, run data ingestion, or send marketing based on this SQL.

SELECT c.name AS config_key,
       CASE
         WHEN c.value IN ('Europe/Madrid', 'UTC') THEN c.value
         WHEN c.value IS NULL OR TRIM(c.value) = '' THEN 'EMPTY_REVIEW'
         ELSE 'OTHER_ZONE_REVIEW'
       END AS safe_value,
       COALESCE(CAST(c.id_shop_group AS CHAR), 'GLOBAL') AS scope_shop_group,
       COALESCE(CAST(c.id_shop AS CHAR), 'GLOBAL') AS scope_shop
FROM bc_configuration AS c
WHERE c.name = 'PS_TIMEZONE'
ORDER BY c.id_shop_group, c.id_shop;

-- Only counts, not customer IDs, order IDs, emails or individual dates.
SELECT 'bc_customer' AS source_object,
       COUNT(*) AS total_shop_1_rows,
       TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS db_session_minus_utc_minutes,
       COALESCE(SUM(CASE WHEN (date_add IS NULL) THEN 1 ELSE 0 END), 0) AS date_add_null,
       COALESCE(SUM(CASE WHEN (date_upd IS NULL) THEN 1 ELSE 0 END), 0) AS date_upd_null,
       COALESCE(SUM(CASE WHEN (CAST(date_add AS CHAR) = '0000-00-00 00:00:00') THEN 1 ELSE 0 END), 0) AS date_add_zero_string,
       COALESCE(SUM(CASE WHEN (CAST(date_upd AS CHAR) = '0000-00-00 00:00:00') THEN 1 ELSE 0 END), 0) AS date_upd_zero_string,
       COALESCE(SUM(CASE WHEN (date_add IS NOT NULL AND date_upd IS NOT NULL AND date_upd < date_add) THEN 1 ELSE 0 END), 0) AS updated_before_created,
       COALESCE(SUM(CASE WHEN (date_upd > DATE_ADD(NOW(), INTERVAL 15 MINUTE)) THEN 1 ELSE 0 END), 0) AS updated_future_session_15min,
       COALESCE(SUM(CASE WHEN (date_upd > DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)) THEN 1 ELSE 0 END), 0) AS updated_future_utc_15min,
       COALESCE(SUM(CASE WHEN (date_upd >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND date_upd <= DATE_ADD(NOW(), INTERVAL 15 MINUTE)) THEN 1 ELSE 0 END), 0) AS updated_last_7d_session,
       COALESCE(SUM(CASE WHEN (date_upd >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY) AND date_upd <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)) THEN 1 ELSE 0 END), 0) AS updated_last_7d_utc,
       COALESCE(SUM(CASE WHEN ((date_upd >= '2025-03-30 02:00:00' AND date_upd < '2025-03-30 03:00:00') OR (date_upd >= '2026-03-29 02:00:00' AND date_upd < '2026-03-29 03:00:00')) THEN 1 ELSE 0 END), 0) AS spring_gap_2025_2026,
       COALESCE(SUM(CASE WHEN ((date_upd >= '2025-10-26 02:00:00' AND date_upd < '2025-10-26 03:00:00') OR (date_upd >= '2026-10-25 02:00:00' AND date_upd < '2026-10-25 03:00:00')) THEN 1 ELSE 0 END), 0) AS autumn_overlap_2025_2026
FROM bc_customer
WHERE id_shop = 1
UNION ALL
SELECT 'bc_orders' AS source_object,
       COUNT(*) AS total_shop_1_rows,
       TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS db_session_minus_utc_minutes,
       COALESCE(SUM(CASE WHEN (date_add IS NULL) THEN 1 ELSE 0 END), 0) AS date_add_null,
       COALESCE(SUM(CASE WHEN (date_upd IS NULL) THEN 1 ELSE 0 END), 0) AS date_upd_null,
       COALESCE(SUM(CASE WHEN (CAST(date_add AS CHAR) = '0000-00-00 00:00:00') THEN 1 ELSE 0 END), 0) AS date_add_zero_string,
       COALESCE(SUM(CASE WHEN (CAST(date_upd AS CHAR) = '0000-00-00 00:00:00') THEN 1 ELSE 0 END), 0) AS date_upd_zero_string,
       COALESCE(SUM(CASE WHEN (date_add IS NOT NULL AND date_upd IS NOT NULL AND date_upd < date_add) THEN 1 ELSE 0 END), 0) AS updated_before_created,
       COALESCE(SUM(CASE WHEN (date_upd > DATE_ADD(NOW(), INTERVAL 15 MINUTE)) THEN 1 ELSE 0 END), 0) AS updated_future_session_15min,
       COALESCE(SUM(CASE WHEN (date_upd > DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)) THEN 1 ELSE 0 END), 0) AS updated_future_utc_15min,
       COALESCE(SUM(CASE WHEN (date_upd >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND date_upd <= DATE_ADD(NOW(), INTERVAL 15 MINUTE)) THEN 1 ELSE 0 END), 0) AS updated_last_7d_session,
       COALESCE(SUM(CASE WHEN (date_upd >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY) AND date_upd <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)) THEN 1 ELSE 0 END), 0) AS updated_last_7d_utc,
       COALESCE(SUM(CASE WHEN ((date_upd >= '2025-03-30 02:00:00' AND date_upd < '2025-03-30 03:00:00') OR (date_upd >= '2026-03-29 02:00:00' AND date_upd < '2026-03-29 03:00:00')) THEN 1 ELSE 0 END), 0) AS spring_gap_2025_2026,
       COALESCE(SUM(CASE WHEN ((date_upd >= '2025-10-26 02:00:00' AND date_upd < '2025-10-26 03:00:00') OR (date_upd >= '2026-10-25 02:00:00' AND date_upd < '2026-10-25 03:00:00')) THEN 1 ELSE 0 END), 0) AS autumn_overlap_2025_2026
FROM bc_orders
WHERE id_shop = 1;
