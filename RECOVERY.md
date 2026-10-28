# Cart recovery contract

Version `0.6.0` adds one-time abandoned-cart recovery links without turning the email link into a customer login mechanism.

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

## Attribution preparation

The token ledger persists both:

- `id_cart`: the original abandoned cart;
- `recovered_cart_id`: the duplicated cart placed into the browser session.

A local `CART_RECOVERED` event is also created. This relation is the basis for the next attribution step: recovered cart → order → recovered revenue.

## Journey field

`RecoveryUrl` is populated just-in-time immediately before the Journey Builder API call. Test mode never fires Journey events and therefore does not generate customer-facing recovery links.
