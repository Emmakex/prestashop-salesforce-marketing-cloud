# PrestaShop Salesforce Marketing Cloud

A reusable PrestaShop bridge for Salesforce Marketing Cloud Engagement.

## Goal

Keep PrestaShop as the source of truth for commerce data and use Salesforce Marketing Cloud as the orchestration layer for customer campaigns and journeys.

The connector is intentionally asynchronous: storefront hooks only persist local events. Salesforce calls are processed outside the customer request so Marketing Cloud availability never blocks checkout.

## Compatibility strategy

The connector is designed as a cross-generation module, not as a module tied to a single PrestaShop release.

Primary target families:

- PrestaShop 1.7.8.x
- PrestaShop 8.x
- PrestaShop 9.x

The core integration must remain independent from version-specific Symfony internals. Differences between PrestaShop generations are isolated behind compatibility/adaptor classes so customer, cart, order, queue and Salesforce services remain reusable.

Engineering rules for compatibility:

1. Prefer stable PrestaShop public APIs and hooks shared across supported generations.
2. Never couple the domain/core services directly to Symfony services that only exist in one PrestaShop generation.
3. Put unavoidable version differences under `src/Compatibility/` or dedicated adapters.
4. Avoid core overrides.
5. Keep the module PHP syntax compatible with the supported PrestaShop/PHP range.
6. Detect capabilities when possible instead of branching only on raw version numbers.
7. Every compatibility change must preserve the same Salesforce event contract.
8. CI must test representative PHP versions across the supported PrestaShop generations.

Versions older than PrestaShop 1.7.8 are intentionally outside the initial support target. They can be evaluated later, but they must not force legacy architecture into the main connector.

See `COMPATIBILITY.md` for the support policy.

## Initial scope

- Cross-generation PrestaShop architecture for 1.7.8.x, 8.x and 9.x.
- Salesforce Marketing Cloud Engagement OAuth 2.0 server-to-server authentication.
- Customer, cart and order event foundations.
- Local queue, retries and audit logs.
- Abandoned-cart detection and recovery journey in later milestones.

## Module

The installable module lives in `pssalesforcebridge/`.

Current bootstrap (`0.1.x`) provides:

- module installation/uninstallation;
- compatibility contract for PrestaShop 1.7.8.x, 8.x and 9.x;
- secure-by-default admin configuration surface (credentials are never committed to Git);
- Salesforce authentication client;
- **Test connection** action;
- local queue and log tables;
- no customer/cart/order data is sent yet.

## Configuration

In PrestaShop Back Office open the module configuration and provide:

- Authentication Base URI
- REST Base URI
- Client ID
- Client Secret
- Business Unit MID / Account ID (optional when the installed package context already targets the required BU)

The connector requests OAuth tokens only server-side. Client secrets are never exposed to the storefront.

## Engineering rules

1. PrestaShop is authoritative for customers, carts and orders.
2. Marketing Cloud is authoritative for journeys and campaign delivery.
3. Hooks must never wait for Salesforce.
4. All outbound events go through the local queue.
5. Queue processing must be idempotent and retryable.
6. Secrets and access tokens must never be logged.
7. A successful order must suppress any pending abandoned-cart action for that cart.
8. Production sending is opt-in; bootstrap defaults to `test` mode.
9. Version-specific PrestaShop behavior must remain outside the domain/integration core.

See `ROADMAP.md` for delivery phases.
