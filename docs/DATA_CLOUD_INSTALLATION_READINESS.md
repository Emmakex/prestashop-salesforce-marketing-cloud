# Data Cloud PST — read-only PrestaShop Back Office preflight

Purpose: Show each merchant which local, non-PII facts can be checked
before the tenant data-owner approves Data Cloud ingestion.

## What the Back Office panel does

- Discovers current active shop IDs through the installed ShopResolver.
- Validates IANA PS_TIMEZONE for each shop without a default.
- Queries only INFORMATION_SCHEMA.COLUMNS for date_add/date_upd
  field types in the existing prefix-specific customer/orders tables.
- Displays local PASS / REVIEW REQUIRED states, shop IDs and valid zones.
- Always displays BLOCKED for schema upload, record ingestion and sends.
- Never selects customer/order rows, changes the database, reads secrets,
  fetches tokens or contacts Salesforce.

Existing Marketing Cloud Engagement health is independent. A healthy
Engagement queue does not authorize Growth/Data Cloud writes.

## Evidence scope

The ABH operator separately confirmed the existing production source DB
(despite a test-like name) and current Europe/Madrid shop configuration.
This panel is generic and intentionally uses no ABH DB name, table prefix
or timezone constants; another website will be assessed on its own.

**Critical limitation:** DATETIME field types and current PS_TIMEZONE
do not establish historical date_upd semantics, DST provenance or
monotonic update order. The panel cannot approve a Record Modified field.

## Required human controls before publishing source schema v0.3

Register a durable unique installation namespace across all connected
websites. Verify historical timestamps and fallback DST records from
trusted UTC evidence. Approve the 24-field schema, category, source_key,
Data Space, refresh and Record Modified choices, DLO/DMO relationships,
GDPR lawful basis/consent, retention/erasure and marketing suppressions.

The schema v0.3 remains REVIEW ONLY. No Data Stream, no actual ingestion,
no automated Marketing Cloud campaign sending. A separate change, release
and admin signoff will be needed before any live delivery.

Back Office panel is rendered only on the protected Modules configuration
page. The standalone pure inspector is tested with synthetic values.
