# Roadmap

## 0.1.0 — Bootstrap

- [x] PrestaShop module skeleton
- [x] installation/uninstallation
- [x] Salesforce configuration screen
- [x] OAuth 2.0 server-to-server client
- [x] Test connection action
- [x] queue/log persistence foundations
- [x] package ZIP CI artifact

## 0.2.0 — Customer synchronization

- customer create/update hooks
- canonical ContactKey: `ps_{shop_id}_{customer_id}`
- local idempotency ledger
- Marketing Cloud customer Data Extension contract
- consent/newsletter mapping
- initial backfill command

## 0.3.0 — Orders

- order validation/payment hooks
- order Data Extension contract
- cart-to-order conversion tracking
- suppression of abandoned-cart candidates after conversion

## 0.4.0 — Abandoned carts

- cart activity capture
- configurable inactivity threshold (default 60 minutes)
- cart + cart item snapshot
- eligibility checks
- deduplication

## 0.5.0 — Journey Builder

- API Entry Event integration
- `PRESTASHOP_ABANDONED_CART`
- journey event audit trail
- retry/backoff and dead-letter handling

## 0.6.0 — Cart recovery

- signed opaque recovery tokens
- expiration / one-time-use controls
- restore cart flow
- recovered cart / recovered revenue attribution

## 0.7.0 — Production hardening

- CLI/cron worker
- health/status dashboard
- queue metrics
- privacy/log retention controls
- release ZIP and upgrade scripts
- PrestaShop 9 compatibility certification
