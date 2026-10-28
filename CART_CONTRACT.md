# Cart and abandonment contract

## Scope

Version 0.4.0 captures and classifies carts locally. It does **not** send Journey Builder events to Salesforce. Outbound abandoned-cart delivery is introduced in 0.5.0.

## Capture source

The connector observes `actionCartSave` and normalizes the cart behind `Compatibility/CartHookAdapter.php` for PrestaShop 1.7.8.x, 8.x and 9.x.

## Stable identity

- Cart key: `ps_{shop_id}_{cart_id}`
- Contact key when a customer exists: `ps_{shop_id}_{customer_id}`

## Snapshot

Each cart snapshot stores:

- shop, cart and customer identifiers;
- ContactKey and email when known;
- currency and cart total;
- total product quantity and distinct item count;
- last activity and calculated abandonment time;
- consent/eligibility state and reason;
- normalized cart items with product, combination, customization, SKU, name, quantity and prices;
- a deterministic snapshot hash used for abandonment-event deduplication.

## Status model

- `active`: contains products and can still become an abandonment candidate;
- `empty`: no products remain;
- `ineligible`: contact/consent requirements are not met;
- `converted`: a PrestaShop order exists for this cart;
- `abandoned`: the inactivity threshold was reached and a local `CART_ABANDONED` event was queued.

A later cart modification creates a fresh snapshot and may return a previously abandoned cart to `active`, unless it has converted to an order.

## Eligibility policy

The default policy is `marketing_optin` and is intentionally fail-closed. It requires:

1. at least one product;
2. a known PrestaShop customer/ContactKey;
3. a syntactically valid email;
4. an active customer;
5. `newsletter` or `optin` enabled.

A configurable `known_contact` mode exists for installations whose documented legal basis permits abandoned-cart communication without the marketing opt-in fields. The connector does not decide the merchant's legal basis; the strict policy remains the default.

## Conversion suppression

Before a cart is classified as abandoned, the connector checks both:

1. the local `sfmc_order_sync` ledger; and
2. PrestaShop's native `orders` table by `id_shop + id_cart`.

If either source finds an order, the cart is marked `converted` and no `CART_ABANDONED` event is created.

## Local persistence

- `sfmc_cart`: one current canonical snapshot per shop/cart;
- `sfmc_cart_item`: normalized current line items;
- `sfmc_queue`: deduplicated `CART_ABANDONED` events awaiting the 0.5.0 Journey Builder delivery layer.

## Inactivity

`PS_SFMC_CART_ABANDON_MINUTES` controls the threshold and defaults to 60 minutes. The minimum accepted value is 15 minutes.
