# PST / ABH — decisions before first schema upload

**DRAFT FOR DATA-OWNER SIGNOFF. NOT AN APPROVED RELEASE.**

> **G3 dossier actualizado (2026-11-13):** para la revisión campo a campo y las ocho decisiones del responsable, consulte [`G3_PST_DATA_OWNER_APPROVAL_PACK.md`](G3_PST_DATA_OWNER_APPROVAL_PACK.md) y el contrato verificable [`config/g3-pst-schema-review.json`](../config/g3-pst-schema-review.json). Ambos borradores YAML siguen **sin aprobar y sin subir**. La CI de G3 comprueba que los 24 campos están clasificados y ninguna aprobación se presume; no sustituye la firma del responsable.

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


## Staged schema v0.3 for UTC review (not uploaded)

PrestaShop's `PS_TIMEZONE` setting can be resolved with an explicit shop ID via `Configuration::get('PS_TIMEZONE', null, null, $shopId)`, as demonstrated by the new offline `ShopTimezoneResolver` and tests. This avoids assuming `Europe/Madrid` or the PHP/server timezone for all merchants. The actual timezone and the meaning of historical SQL `date_upd` values still require shop/DB verification before the production field is approved.

The alternative `schemas/ABH_PST_SCHEMA_UTC_CANDIDATE.yaml` is a separate, review-only **v0.3** schema containing **12 fields per object (24 total)**. It adds a valid `source_updated_utc` `date-time` field to PSCustomer and PSOrder. The original `ABH_PST_SCHEMA_REVIEW.yaml` v0.2 with 22 fields is deliberately untouched. A synthetic `StagedUtcRecordMapper` and CI check prove the candidate matches the exact extra fields and valid Zulu format. **Neither candidate is authorized for upload.** If ABH approves the timestamp policy, the production payload builder and mapper must be updated to use the same approved schema, and a version/hash must be frozen before Salesforce Save.
