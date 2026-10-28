# PrestaShop Salesforce Marketing Cloud

A reusable PrestaShop bridge for Salesforce Marketing Cloud Engagement.

## Goal

Keep PrestaShop as the source of truth for commerce data and use Salesforce Marketing Cloud as the orchestration layer for customer campaigns and journeys.

The connector is intentionally asynchronous: storefront hooks only persist local events. Salesforce calls are processed outside the customer request so Marketing Cloud availability never blocks checkout.

## Initial scope

- PrestaShop 8.x first, architecture compatible with 9.x.
- Salesforce Marketing Cloud Engagement OAuth 2.0 server-to-server authentication.
- Customer, cart and order event foundations.
- Local queue, retries and audit logs.
- Abandoned-cart detection and recovery journey in later milestones.

## Module

The installable module lives in `pssalesforcebridge/`.

Current bootstrap (`0.1.0`) provides:

- module installation/uninstallation;
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

See `ROADMAP.md` for delivery phases.
