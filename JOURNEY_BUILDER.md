# Journey Builder contract

Version `0.5.0` delivers locally detected `CART_ABANDONED` events to Salesforce Marketing Cloud Journey Builder through the synchronous API Entry Event endpoint.

## Event Definition

Default key:

```text
PRESTASHOP_ABANDONED_CART
```

The key is configurable in the PrestaShop module and must use only letters, numbers, `_` or `-`. The Journey Builder entry event must already exist and be active before Production mode can deliver events.

Salesforce endpoint:

```text
POST /interaction/v1/events
```

The request uses the canonical PrestaShop `ContactKey` plus the configured `EventDefinitionKey`.

## Event data

Create the Journey Builder entry-event data extension with fields compatible with:

| Field | Suggested type |
| --- | --- |
| BridgeEventKey | Text(191) |
| CartKey | Text(191) |
| CartId | Number |
| ShopId | Number |
| CustomerId | Number |
| ContactKey | Text(191) |
| Email | EmailAddress / Text(255) |
| Firstname | Text(255) |
| Lastname | Text(255) |
| Currency | Text(8) |
| Total | Decimal |
| ProductCount | Number |
| ItemCount | Number |
| LastActivity | Date |
| AbandonedAt | Date |
| SnapshotHash | Text(40) |
| RecoveryUrl | Text(2048) |

`RecoveryUrl` is intentionally empty in `0.5.0`. It is reserved for the signed recovery flow planned for `0.6.0`, so the Journey contract does not need to change later.

## Safety gates before every send

The queue event is revalidated immediately before calling Salesforce. Delivery is suppressed when any of these conditions is true:

- the current cart snapshot no longer exists;
- the cart has produced an order according to the local ledger or native PrestaShop orders table;
- the snapshot hash no longer matches the queued abandoned-cart event;
- the cart is no longer in `abandoned` state;
- the cart is empty or no longer eligible;
- the configured consent policy no longer permits the contact;
- the abandonment window has changed.

Suppressed events are considered terminally processed locally and are never sent to Journey Builder.

## Delivery semantics

Successful API responses must contain `eventInstanceId`. The value is persisted in `sfmc_journey_event` together with the queue event, cart, contact, configured Event Definition Key and snapshot hash.

Transient or API errors use the shared exponential retry policy and become `failed` after five attempts. The connector deliberately does not log OAuth tokens, client secrets or complete Salesforce responses.

The Salesforce API does not provide a connector-level idempotency key for this endpoint. `BridgeEventKey` is therefore sent as event data and local queue/audit uniqueness prevents normal duplicate delivery. Journey re-entry rules should also be configured according to the campaign policy.

## Test mode

Test mode still detects abandoned carts and creates local `CART_ABANDONED` queue events, but the Journey delivery worker does not authenticate or send anything to Salesforce. Switch explicitly to Production only after the Event Definition and Journey have been validated.
