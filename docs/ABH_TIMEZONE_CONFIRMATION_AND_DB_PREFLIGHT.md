# ABH / PST — timezone confirmation and database date semantics

Status: **UTC candidate offline only; NO Salesforce schema upload or record writes.**

The ABH operator verified in the PrestaShop back office on 2026-11-10
that its currently selected store timezone is **Europe/Madrid**.
This is an observed configuration value; it is not proof of which timezone
was used historically to write customer/order date_upd records.

The bridge stays multi-shop and merchant neutral: every shop is resolved with
ShopTimezoneResolver and an explicit shop ID. Europe/Madrid is not hardcoded
into production logic. Staged UTC mapping does not run in existing worker.

## Read-only database preflight, not yet executed

Before using source_updated_utc as Record Modified or choosing Partial Refresh:

1. Verify the PrestaShop database and shop IDs in scope; check PS_TIMEZONE
   for each shop in the back office; if multishop, verify any overrides.
2. Confirm how PrestaShop version, PHP, database session, modules and historical
   imports wrote date_upd. This is a storage-time semantics review, not just
   checking the current interface timezone.
3. Compare a few RECENT non-sensitive date_upd values with their known
   audit event UTC times, without exporting customer names/emails/order details
   or database connection credentials into GitHub.
4. For any historical records around March/October DST changes, establish
   absolute instants from reliable log/audit evidence; ambiguous wall-clock
   timestamps must be quarantined, not guessed.
5. Approve monotonic ordering/replay protection and UTC field semantics before
   activating an Ingestion API stream; data minimization, retention/erasure,
   consent, primary keys and DLO-to-DMO mapping still need sign-off.

### Safe SQL metadata example (read-only, adjust PrestaShop table prefix)

    SELECT @@session.time_zone AS session_timezone,
           @@global.time_zone AS global_timezone,
           @@system_time_zone AS system_timezone,
           UTC_TIMESTAMP() AS database_utc_now,
           NOW() AS database_session_now;

This query reveals the current database session clock only. It does **not**
prove the timezone of past PrestaShop date_upd writes. Do not use the current
DB timezone to reinterpret historical values automatically.

## What is prepared

- v0.2 source schema remains 22 fields, review only.
- v0.3 candidate has 24 fields, 12 per object, including mandatory ISO UTC
  source_updated_utc values. No final approval or upload.
- StagedUtcRecordMapper + StagedUtcPayloadBuilder now model the full offline
  journey from normalized records to JSON data envelope using synthetic data.
  They fail closed on unknown timezone, DST overlap/gap, missing UTC field,
  bad UTC formats, duplicate identities, cross-shop references and large batches.
- Existing IngestionPayloadBuilder, PrestaShop live worker, OAuth config and
  Salesforce connector PST are left unchanged.

Next ABH data owner signoff: field dictionary (including consent source flags),
retention/erasure, source_key uniqueness, Profile/Other categories, Data Space
default, Record Modified/refresh strategy and DLO-to-DMO relationship mappings.
