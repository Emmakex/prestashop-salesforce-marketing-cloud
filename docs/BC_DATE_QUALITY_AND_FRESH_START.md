# BC / reusable PrestaShop — date quality, quarantine and fresh-data fallback

Status: **review-only**. BC PrestaShop 1.7.8.11 BO preflight passes: shop 1,
Europe/Madrid, customer/orders date_add and date_upd DATETIME. The technical
preflight **does not** prove historical UTC provenance or authorize ingestion.
Do not launch backfill, schema upload, Data Streams or any Marketing Cloud send.

## Business decision: clean data is more important than full history

- Reject the assumption that a syntactically valid DATETIME is historically
  correct. Current PS_TIMEZONE, SQL SYSTEM/CET and PHP timezone are not evidence
  for each past write or import.
- Prefer **a fresh-data cutover**, after independently verifying the exact write
  path for new customer/order updates and setting an explicit UTC start instant.
  The cutover is NOT inferred from the calendar, DB now or module installation.
  New events before verified cutover remain unverified.
- Historical rows may be included ONLY if their absolute instant is corroborated
  by a trustworthy independent UTC event/audit log, or through a separately
  approved migration with equivalent provenance. Never guess ambiguous DST
  fall-back hours; reject DST spring gaps.
- If most history is unreliable, **do not block the project on repairing it**:
  quarantine the old timestamps, approve a clean starting point, validate fresh
  incremental records, and only then consider whether a lawful, consent-safe
  snapshot of current customer state is required. A fresh snapshot timestamp
  (observed_at_utc) is NOT the same as PrestaShop source_updated_utc and requires
  a distinct, explicitly reviewed schema/Record Modified policy before use.
- Existing customers not modified after cutover are not silently treated as
  newly updated. Orders and customer references must remain consistent; no
  orphan/cross-shop relationships or cross-merchant deduplication.
- No marketing eligibility from newsletter_flag / partner_optin_flag alone.
  Record-level consent, suppression, legal basis and erasure must be approved
  separately.

## Offline deterministic quality gate

`TimestampQualityAudit::inspect(samples, shopIanaTimezone, policy)` accepts
**date-only** samples, maximum 500 per invocation. It returns aggregate counts
only (no IDs, emails, SQL rows, URLs or raw dates); no DB/network/worker code.

- `eligible_evidenced`: converted local time equals reliable independent
  absolute UTC evidence for that record. The caller must justify the evidence.
- `eligible_after_verified_cutover`: converted time on/after the explicitly
  approved UTC cutover AND the caller passes `write_path_verified=true`.
  The toggle must never be set merely because the current timezone is valid.
- `unverified_history`: valid local date but lacks trusted provenance.
- Quarantine categories: missing, invalid, DST gap, DST overlap, obviously
  future, and conflicting/malformed UTC evidence. All quarantined records stay
  outside the candidate batch.
- Every report has `record_ingestion_allowed=false`,
  `historical_backfill_allowed=false`, `schema_upload_allowed=false`,
  and `marketing_sends_allowed=false`, regardless of counts.
- The report is **sample-scoped**, not a reliable population error rate:
  last 500 IDs can be biased, and SQL summaries do not prove historical TZ.

## Read-only BC diagnostic query (manual, aggregate output only)

Execute in the **authorized BC source database** through its existing SQL
administration interface, not in Salesforce or CI. Verify table prefix
`bc_` first. This query returns only counts from the latest 500 customer
and order IDs; no row contents or personal details are emitted. The date
cutoff displayed is an observation metric, **not** an approved cutover.

```sql
SELECT 'customer' AS source_object, COUNT(*) AS sampled,
       COALESCE(SUM(date_upd IS NULL OR CAST(date_upd AS CHAR) = '0000-00-00 00:00:00'),0) AS missing_updated,
       COALESCE(SUM(date_add IS NULL OR CAST(date_add AS CHAR) = '0000-00-00 00:00:00'),0) AS missing_created,
       COALESCE(SUM(date_upd < date_add),0) AS updated_before_created,
       COALESCE(SUM(date_upd > DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY)),0) AS obviously_future,
       COALESCE(SUM(date_upd >= '2026-03-29 02:00:00' AND date_upd < '2026-03-29 03:00:00'),0) AS gap_march_2026,
       COALESCE(SUM(date_upd >= '2026-10-25 02:00:00' AND date_upd < '2026-10-25 03:00:00'),0) AS overlap_oct_2026,
       COALESCE(SUM(date_upd >= '2026-11-01 00:00:00'),0) AS apparently_recent
FROM (SELECT date_add, date_upd FROM bc_customer ORDER BY id_customer DESC LIMIT 500) AS recent_customers
UNION ALL
SELECT 'orders', COUNT(*),
       COALESCE(SUM(date_upd IS NULL OR CAST(date_upd AS CHAR) = '0000-00-00 00:00:00'),0),
       COALESCE(SUM(date_add IS NULL OR CAST(date_add AS CHAR) = '0000-00-00 00:00:00'),0),
       COALESCE(SUM(date_upd < date_add),0),
       COALESCE(SUM(date_upd > DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY)),0),
       COALESCE(SUM(date_upd >= '2026-03-29 02:00:00' AND date_upd < '2026-03-29 03:00:00'),0),
       COALESCE(SUM(date_upd >= '2026-10-25 02:00:00' AND date_upd < '2026-10-25 03:00:00'),0),
       COALESCE(SUM(date_upd >= '2026-11-01 00:00:00'),0)
FROM (SELECT date_add, date_upd FROM bc_orders ORDER BY id_order DESC LIMIT 500) AS recent_orders;
```

A row may be "apparently_recent" and still wrong: freshness alone is not
proof. The March/October metrics are specific to the two 2026 Madrid DST
transitions; older years require their own review. A zero count is NOT
proof that older rows have no DST problems.

## Required certification after these aggregate results

1. Confirm the actual clock semantics of current PrestaShop 1.7.8.11
   customer/order writes and custom import modules against a trusted UTC
   audit source (without copying personal data into the repo).
2. If provenance is not established, disable historical sync. Propose an
   auditable, explicit UTC cutover no earlier than the point when writes are
   verified. Recheck new records after cutover; do not declare success just
   from an empty or clean-looking sample.
3. Design idempotent, merchant + shop scoped source_key, event ordering and
   durable replay checkpoints for new changes. Consider that customer updates
   to existing customers must still be observed.
4. Review required v0.3 source_updated_utc versus a separate
   snapshot_observed_utc field, and Partial/Full refresh BEFORE creating
   immutable Data Streams.
5. Require source schema version/hash, data-owner signoff, GDPR
   purpose/minimization/consent/suppression/retention/erasure and DLO/DMO
   mapping. Only then authorize a separate synthetic test. No live sends.

**Current state**: No real BC aggregate audit was run by CI or this code.
This branch adds a decision/control mechanism, not a production activation.
