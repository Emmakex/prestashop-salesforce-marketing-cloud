# Roadmap

## 0.1.0 — Bootstrap

- [x] PrestaShop module skeleton
- [x] installation/uninstallation
- [x] Salesforce configuration screen
- [x] OAuth 2.0 server-to-server client
- [x] Test connection action
- [x] queue/log persistence foundations
- [x] package ZIP CI artifact

## 0.1.1 — Cross-version compatibility foundation

- [x] support target defined for PrestaShop 1.7.8.x, 8.x and 9.x
- [x] centralized PrestaShop compatibility contract
- [x] module installation range widened to 1.7.8.0–9.x
- [x] compatibility/adaptor architecture rule documented
- [x] PHP syntax CI expanded across representative legacy/current runtimes
- [ ] runtime certification against real PrestaShop fixtures/containers per supported family

## 0.2.0 — Customer synchronization foundation

- [x] customer create/update hooks
- [x] canonical ContactKey: `ps_{shop_id}_{customer_id}`
- [x] local idempotency ledger
- [x] normalized customer Data Extension contract
- [x] newsletter/opt-in source mapping
- [x] customer hook compatibility adapter for PrestaShop 1.7.8, 8 and 9
- [ ] Salesforce Data Extension writer / worker
- [ ] initial customer backfill command

## 0.3.0 — Orders

- order validation/payment hooks
- order Data Extension contract
- cart-to-order conversion tracking
- suppression of abandoned-cart candidates after conversion
- order adapter contract shared by supported PrestaShop families

## 0.4.0 — Abandoned carts

- cart activity capture
- configurable inactivity threshold (default 60 minutes)
- cart + cart item snapshot
- eligibility checks
- deduplication
- cart adapter contract shared by supported PrestaShop families

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
- runtime certification matrix for PrestaShop 1.7.8.x, 8.x and 9.x
