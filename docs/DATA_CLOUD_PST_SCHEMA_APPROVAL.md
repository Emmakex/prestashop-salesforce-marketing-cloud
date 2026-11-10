# PST / ABH — decisions before first schema upload

**DRAFT FOR DATA-OWNER SIGNOFF. NOT AN APPROVED RELEASE.**

Confirmed: connector `PST` / `ABH_PrestaShop_Data_Cloud`, Data Space `default`, OAuth exchange success (run #42655156432), PSCustomer/PSOrder candidate source schema in `schemas/ABH_PST_SCHEMA_REVIEW.yaml`. No real data ingestion.

## Current data model proposal

| Source object | Proposed category | Primary key | Fields |
|---|---|---|---:|
| PSCustomer | Profile | source_key | 11 |
| PSOrder | Other | source_key | 11 |

`source_key` includes merchant namespace, shop ID and customer/order ID; emails do **not** deduplicate tenants. `customer_source_key` references customer within the same shop. `cart_source_key` is a source reference only, with no cart stream yet. `newsletter_flag` and `partner_optin_flag` are raw PrestaShop flags, **not evidence of consent** or permission to send marketing messages.

## Record Modified: approval needed before first stream

The current schema's `source_updated_local` is TEXT, not an ISO UTC timestamp. PrestaShop's local `date_upd` has no timezone suffix. Do not use it as Record Modified.

**Recommended, not yet approved:** add a separate `source_updated_utc` date-time field **before the first schema upload**, derived from an explicit configured IANA timezone per PrestaShop shop. `SourceTimestampNormalizer` now handles exact `yyyy-MM-dd HH:mm:ss` and emits `yyyy-MM-ddTHH:mm:ss.SSSZ`, rejecting nonexistent or ambiguous DST wall-clock times, invalid dates and unknown timezones. `Europe/Madrid` is a test fixture and must **not** be forced globally on merchants.

At DST fall-back ambiguity, quarantine for a true absolute-source timestamp rather than guessing. This pure helper is not wired into any live worker or payload mapper.

Alternative: choose **no** Record Modified field and Full refresh; this would require separate ordered-delivery/stale-update guarantees before real sync. Full versus Partial Refresh Mode becomes immutable at Data Stream creation. Neither choice has yet been approved.

## Data owner and admin acceptance

- [ ] Confirm necessity of all 22 fields, handling of email/name and marketing flags, data minimization and lawful basis.
- [ ] Confirm retention and deletion, right to erasure, suppressed contacts, account segmentation and merchant namespace boundaries.
- [ ] Confirm per-shop timezone source; choose whether to add `source_updated_utc` and handle DST ambiguity BEFORE uploading the schema.
- [ ] Approve keys `source_key`, categories Profile/Other, Data Space `default` and Full/Partial Refresh Mode.
- [ ] Approve DLO to DMO mapping, customer-order relation and deduplication policy.
- [ ] Approve exact version/SHA256 of final YAML; preview detected objects and fields in Salesforce BEFORE Save.
- [ ] Only after approval create/deploy at most one Data Stream per object, then perform separately authorized synthetic `/actions/test` validation.
- [ ] Real record ingestion, carritos abandonados and Marketing Cloud sending need separate acceptance.

No CI success or OAuth token is an authorization to upload an irreversible Salesforce schema.

Official Salesforce references:
- https://developer.salesforce.com/docs/data/data-cloud-int/guide/c360-a-ingestion-api-schema-req.html
- https://developer.salesforce.com/docs/data/data-cloud-int/guide/c360-a-create-ingestion-data-stream.html
- https://developer.salesforce.com/docs/marketing/marketing-cloud-growth/guide/mc-manage-objects-create-streaming-data-cloud.html
