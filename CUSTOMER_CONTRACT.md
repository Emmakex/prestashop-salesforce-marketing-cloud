# Customer event contract

Customer events are normalized before entering the queue so Salesforce-facing code never depends on a PrestaShop generation.

## ContactKey

`ps_{shop_id}_{customer_id}`

The email address is not used as the primary identity because it can change.

## Event types

- `CUSTOMER_CREATED`
- `CUSTOMER_UPDATED`

Both events use the same payload contract. The event type describes why the current customer state was observed; the payload represents the current state.

## Normalized payload

| Field | Type | Notes |
| --- | --- | --- |
| `contact_key` | string | Stable bridge identity |
| `customer_id` | integer | PrestaShop customer ID |
| `shop_id` | integer | PrestaShop shop ID; required for multishop identity |
| `email` | string | Current email address |
| `firstname` | string | Current first name |
| `lastname` | string | Current last name |
| `newsletter` | 0/1 | Raw PrestaShop newsletter flag |
| `optin` | 0/1 | Raw PrestaShop partner/opt-in flag when present |
| `active` | 0/1 | Customer active state |
| `language_id` | integer | Source PrestaShop language ID |
| `date_add` | string | Source creation timestamp |
| `date_upd` | string | Source update timestamp |

`newsletter` and `optin` are synchronized as source attributes. The bridge must not reinterpret them as a universal legal-consent decision. Campaign eligibility remains an explicit Marketing Cloud / merchant policy.

## Idempotency

The queue dedupe key is derived from `shop_id`, `customer_id` and the SHA-1 fingerprint of the normalized payload. Re-observing the same state therefore does not create a second outbound job.

A local `sfmc_customer_sync` ledger stores the latest observed payload hash and synchronization state. Future workers will mark the ledger `synced` only after Salesforce acknowledges the corresponding write.

## Hook compatibility

The compatibility adapter accepts `newCustomer`, `customer` and a defensive `object` fallback. Generation-specific payload differences must be resolved at the compatibility boundary, never inside Salesforce services.
