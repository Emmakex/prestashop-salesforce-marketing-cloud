# PrestaShop Salesforce Marketing Cloud

A reusable PrestaShop bridge for Salesforce Marketing Cloud Engagement.

## Goal

Keep PrestaShop as the source of truth for commerce data and use Salesforce Marketing Cloud as the orchestration layer for customer campaigns and journeys.

The connector is intentionally asynchronous: storefront hooks only persist local events. Salesforce calls are processed outside the customer request so Marketing Cloud availability never blocks checkout.

## Compatibility target

One module targets PrestaShop **1.7.8.x, 8.x and 9.x**. Version-specific behavior must stay behind the compatibility/adaptor boundary; Salesforce-facing contracts remain identical across PrestaShop generations.

See `COMPATIBILITY.md` for certification policy.

## Current scope

Version `0.2.0` provides:

- module installation/uninstallation;
- Salesforce OAuth 2.0 server-to-server configuration and connection test;
- local queue and audit foundations;
- `CUSTOMER_CREATED` and `CUSTOMER_UPDATED` capture;
- stable `ContactKey` (`ps_{shop_id}_{customer_id}`);
- normalized cross-version customer payloads;
- local customer synchronization ledger and payload idempotency;
- no outbound customer write to Marketing Cloud yet.

The next customer cut will add the asynchronous Salesforce Data Extension writer/worker and initial backfill tooling.

## Engineering rules

1. PrestaShop is authoritative for customers, carts and orders.
2. Marketing Cloud is authoritative for journeys and campaign delivery.
3. Hooks must never wait for Salesforce.
4. All outbound events go through the local queue.
5. Queue processing must be idempotent and retryable.
6. Secrets and access tokens must never be logged.
7. A successful order must suppress any pending abandoned-cart action for that cart.
8. Production sending is opt-in; configuration defaults to `test` mode.
9. Version-specific PrestaShop behavior belongs behind compatibility adapters, never in Salesforce services.
10. Runtime compatibility is certified by real integration tests, not inferred from PHP lint alone.

See `ROADMAP.md` and `CUSTOMER_CONTRACT.md` for delivery and payload details.
