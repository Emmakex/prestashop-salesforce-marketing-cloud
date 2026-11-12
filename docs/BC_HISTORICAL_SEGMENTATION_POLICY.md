# BC / PST — historical first, purpose-limited segmentation (review only)

Decision on 2026-11-12: **use historical customers and orders when they are fit for a
permitted purpose; do not throw away good data simply because it is old.**
This overrides the earlier preference for a new-data-only start. A verified UTC
cutover remains necessary for trustworthy incremental *timestamp semantics*,
but it is not a blanket exclusion of pre-cutover customers or transactions.

## Separate three questions per record

1. **Identity and data quality** — valid source key scoped to merchant + shop,
   stable customer/order relationship, usable fields, duplicates resolved,
   invalid email flagged, correct currency/state, no cross-shop leakage.
2. **Purpose and legal eligibility** — explicit purpose (analytics/transactional
   service/marketing), applicable legal basis, minimization, privacy notice,
   retention limit, rights/erasure, suppression and communication preference.
   `newsletter_flag` and `partner_optin_flag` are indicators, not durable
   consent proof. Customers without marketing permission must not be discarded
   from all other lawful uses merely for that reason.
3. **Temporal reliability** — local `date_add`/`date_upd` are text-like local
   wall-clock values until origin/timezone policy is verified. Treat UTC
   provenance separately from identity or marketing eligibility. Never invent
   `source_updated_utc` for old records or equate import time to update time.

## Cohorts and Salesforce access decisions

| Cohort | Historical import candidate? | Marketing candidate? | Requirement |
| --- | --- | --- | --- |
| Registered profile, valid, lawful purpose | Yes | Only if independently eligible | Purpose-minimal identity & timestamps policy |
| Guest with valid commercial transaction | Yes | Not by guest status alone | Stable order relationship; purpose/retention |
| Historical orders with correct references | Yes | Not a send list | Customer/order integrity & lawful processing |
| Inactive/old but legitimate record | Yes, within retention limits | Only if eligible | Analytical age/RFM, not default exclusion |
| Newsletter flag / partner opt-in flag | Only as documented raw fields if needed | No, not on flags alone | Evidence, scope, current suppression |
| Invalid email, duplicate, orphan record | Hold for correction/dedup | No | Preserve source, review before downstream use |
| Erased, suppressed or expired retention | Exclude/remove as legally required | No | Apply erasure/suppression across destinations |
| DST-ambiguous old update date | Other verified fields may still be useful | Independently gated | No unproven Record Modified timestamp |

`source_key` must use a durable merchant/installation namespace + shop ID +
local record ID; customer and order relations must never cross installations.
Do not unify identities across merchant tenants based on email alone.

## What the BC sample actually says

The operator's **sampled** aggregate checks included 500 customers and 500
orders with zero missing/inverted/obviously future/DST-window anomalies under
the tested SQL rules. The sample is not a full historical proof. Customer
segmentation sample: 213 guest and 287 registered; 99 with at least one of the
two raw marketing indicators, 69 with both. These numbers overlap; neither
99 nor the underlying flags certify any send audience.

## Technical import sequence (not active)

1. Confirm retention/purpose and field dictionary before the source-schema
   approval; preserve source originals (never delete BC records as cleanup).
2. Audit historical distribution and actual trust boundaries, not only the
   last 500 IDs. Define an explicit quarantine and reprocessing contract for
   invalid identifiers, cross-object references, malformed values, DST cases,
   duplicate source keys and suppression/erasure.
3. For a one-time **history snapshot**, decide whether the schema needs
   `snapshot_observed_utc` and how it will coexist with local historical
   timestamps. **Do not** represent snapshot observation as the historical
   `source_updated_utc` or use it for Record Modified without separate
   signoff on replay/order guarantees.
4. Once current PrestaShop write paths are verified, establish an absolute UTC
   checkpoint for future incremental changes. Include updates to historic
   customers as well as newly created ones, using durable idempotent keys.
5. Segment RFM/recency/order history separately from channel permission;
   marketing audiences must enforce proven eligibility and current exclusions.
6. Review the 24-field v0.3 candidate and potentially revised snapshot schema,
   categories, Data Space, refresh mode, DLO-to-DMO mapping and hashes **before**
   any irreversible schema upload or stream activation.
7. Authorize schema, synthetic API validation, controlled real ingestion and
   marketing sends as **distinct** gates, each requiring its own approval.

## Non-goals

This note changes no running module, installed version, database, worker,
credentials, OAuth, Data Cloud schema or Salesforce tenant state. The existing
TimestampQualityAudit remains fail-closed (its historical_backfill_allowed=false
is a transport authorization guard, **not** a data-retention or exclusion
decision). Historical ingestion is not enabled until all relevant gates pass.
