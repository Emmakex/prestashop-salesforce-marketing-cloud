# BC / reusable PrestaShop — date quality, quarantine and fresh-data fallback

Status: **review-only**. BC PrestaShop 1.7.8.11 BO preflight passes: shop 1,
Europe/Madrid, customer/orders date_add and date_upd DATETIME. The technical
preflight **does not** prove historical UTC provenance or authorize ingestion.
Do not launch backfill, schema upload, Data Streams or any Marketing Cloud send.

## Business decision: retain valuable history; segment by permitted use

**Updated BC decision (2026-11-12):** The merchant explicitly wants to use
historical customer and order data. **Do not discard older or guest records
merely for their age, opt-in flags, or unverified source_update timestamps.**
Prioritize segmentation and fitness for a specific purpose. Quality and
eligibility are separate dimensions, not a binary "send/do not send" pool.

- Retain source history in PrestaShop. Prepare a scoped, purpose-specific
  initial history import only after source-data quality, legal basis,
  minimization, retention and Salesforce mapping have been reviewed.
- Segments: (1) usable customer profile; (2) order/transactional history;
  (3) verified marketing-eligible audience; (4) legitimate non-marketing
  contacts; (5) guest customers linked to purchases; (6) review/quarantine
  for invalid, duplicate, erased/suppressed, inconsistent or unverified data.
  A record may be useful for analytics without being eligible for marketing.
- Historical timestamps are **a distinct gate**. A valid DATETIME is not
  proof of an absolute UTC instant. Never manufacture source_updated_utc
  or use unverified date_upd as Record Modified. For records lacking
  per-row UTC provenance, consider a separately approved initial snapshot
  with a clearly named observed-at UTC timestamp, a defined refresh/
  version-ordering policy, and incremental capture of subsequent updates.
  This is a future design option, not permission to ingest today.
- Keep original customer/order IDs under a stable merchant + shop namespace;
  deduplicate without collapsing two merchants or guest and registered
  customers on email alone. Preserve cross-object references.
- Old/new status is an analytical segment (recency, frequency, monetary
  value, category, repeat purchase), **not** an automatic exclusion rule.
- PrestaShop newsletter/partner flags are raw source indicators. Campaign
  eligibility requires documented legal basis/consent evidence as applicable,
  unsubscribe/suppression/erasure checks and correct channel/purpose.
- Do not use production credentials or real customer data in PRs or CI.
  No schema upload, Data Stream, backfill or Marketing Cloud send is enabled.

See `docs/BC_HISTORICAL_SEGMENTATION_POLICY.md` for the cohort/mapping
contract and evidence gaps.

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
2. If historical source_updated_utc cannot be proven, do not discard
   useful customer/order history. Keep it in scope for a separately approved
   snapshot or timestamp strategy that does not invent a historical instant;
   validate a trustworthy incremental UTC capture for subsequent changes.
3. Design idempotent, merchant + shop scoped source_key, event ordering and
   durable replay checkpoints for new changes. Consider that customer updates
   to existing customers must still be observed.
4. Review required v0.3 source_updated_utc versus a separate
   snapshot_observed_utc field, and Partial/Full refresh BEFORE creating
   immutable Data Streams.
5. Require source schema version/hash, data-owner signoff, GDPR
   purpose/minimization/consent/suppression/retention/erasure and DLO/DMO
   mapping. Only then authorize a separate synthetic test. No live sends.

**Current state**: BC operator ran the bounded date SQL audit (500 customers
and 500 orders, no anomaly flags in that sample) and a customer segmentation
sample. Neither proves the full population's UTC provenance or campaign
consent. CI and this quality-gate class are offline; no activation occurred.
