# Cart recovery contract

Version `0.6.0` introduced one-time abandoned-cart recovery links without turning the email link into a customer login mechanism. Version `0.6.1` closes the local attribution loop from a recovered cart to every PrestaShop order generated from that cart and to paid recovered revenue.

## Security model

- Recovery URLs contain a cryptographically random 256-bit token.
- The raw token is never stored in PrestaShop; only its SHA-256 hash is persisted.
- Tokens are single-use and claimed atomically before recovery.
- Token lifetime is configurable from 1 to 720 hours; the default is 168 hours (7 days).
- A new token for the same cart snapshot expires any previous unused token for that snapshot.
- Tokens are bound to `shop_id`, source `cart_id`, `customer_id`, `ContactKey` and `snapshot_hash`.
- The recovery endpoint is HTTPS, no-store and noindex.
- PrestaShop `secure_key` is never exposed or reused as a recovery credential.

## Recovery flow

```text
Journey Builder email
        ↓
RecoveryUrl with opaque token
        ↓
/modules/pssalesforcebridge/recover?t=...
        ↓
atomically claim token
        ↓
re-check source cart has no order
        ↓
re-check snapshot hash
        ↓
duplicate source cart
        ↓
sanitize identity / addresses / carrier
        ↓
attach duplicated cart to browser cookie
        ↓
mark source CART_RECOVERED
        ↓
redirect to PrestaShop checkout
```

## Authentication rule

The recovery token does **not** authenticate a customer.

If the browser is already authenticated as the same customer, the duplicated cart may remain associated with that authenticated customer. Otherwise the duplicated cart is detached from the source customer and its addresses are cleared before it is attached to the browser session.

This prevents a bearer recovery link from exposing the original customer's account or address data.

## Multishop

`RecoveryUrl` is generated with the abandoned cart's `id_shop`, so the URL uses the correct shop domain/base URI rather than whichever shop happens to execute the cron.

## Stale and converted carts

Recovery fails closed when:

- the token is invalid, expired or already used;
- the source cart no longer exists;
- the source cart belongs to another shop;
- the cart has already produced an order;
- the current cart snapshot no longer matches the snapshot for which the Journey event was sent;
- the source cart no longer contains recoverable products.

## Attribution

The recovery token ledger persists the first part of the chain:

```text
source abandoned cart
        ↓
recovery token / click
        ↓
recovered_cart_id
```

Order attribution is intentionally stored in a separate one-to-many ledger:

```text
recovered_cart_id
        ├── PrestaShop order A
        ├── PrestaShop order B
        └── ...
```

This is necessary because PrestaShop can split one cart into multiple orders, for example when checkout contains multiple packages. A single `recovered_order_id` on the token would overwrite attribution and undercount recovered revenue.

The `sfmc_recovery_order` table therefore stores one idempotent row per `(id_shop, id_order)` with:

- recovery-token id;
- source abandoned cart id;
- recovered cart id;
- PrestaShop order id;
- authoritative order total;
- order currency;
- current order state id;
- current paid flag;
- current valid flag;
- initial conversion timestamp;
- created/updated timestamps.

When the normal order pipeline processes `ORDER_CREATED`, `ORDER_UPDATED`, `ORDER_PAID` or `ORDER_CANCELLED`, it checks whether that order's `cart_id` is a recovered cart. If it is, the corresponding attribution row is inserted or updated. Later status changes update the same `(shop, order)` row rather than creating duplicate conversions.

Attribution is deliberately local and best-effort. It is isolated from the authoritative order queue: an attribution-storage failure must never stop the `ORDER_*` event from being queued for Salesforce.

## Revenue rule

Only attribution rows whose current `paid = 1` contribute to paid recovered revenue.

If one recovered cart produces several paid orders, all of those orders contribute independently. If one of those orders later ceases to be paid, its row is updated and it no longer contributes to the paid-revenue metric.

Revenue is grouped by currency. EUR, USD, GBP or other currencies are never summed into one synthetic global figure without an explicit FX layer.

The Back Office recovery summary exposes:

- active / used / expired recovery links;
- recovered carts;
- attributed orders;
- paid recovered orders;
- paid recovered revenue grouped by currency.

These metrics are based on PrestaShop order state and totals, not on email-open tracking.

## Journey field

`RecoveryUrl` is populated just-in-time immediately before the Journey Builder API call. Test mode never fires Journey events and therefore does not generate customer-facing recovery links.
