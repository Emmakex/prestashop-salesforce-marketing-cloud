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

## 0.2.0 — Customer event foundation

- [x] customer create/update hooks
- [x] canonical ContactKey: `ps_{shop_id}_{customer_id}`
- [x] local idempotency ledger
- [x] normalized customer Data Extension contract
- [x] newsletter/opt-in source mapping
- [x] customer hook compatibility adapter for PrestaShop 1.7.8, 8 and 9

## 0.2.1 — Customer delivery worker

- [x] Salesforce REST client
- [x] Data Extension upsert by External Key
- [x] configurable customer Data Extension key
- [x] protected cron endpoint
- [x] queue claiming with concurrency lock
- [x] exponential retry and terminal failure after five attempts
- [x] test mode blocks outbound customer writes
- [x] upgrade path from 0.2.0

## 0.2.2 — Customer backfill

- [x] resumable per-shop backfill cursor
- [x] multishop historical customer scan
- [x] configurable 10–500 customer batch size
- [x] shared live/backfill normalization and queue pipeline
- [x] backfill progress summary in module configuration
- [x] upgrade path from 0.2.1

## 0.3.0 — Orders

- [x] `actionValidateOrder` capture across supported PrestaShop families
- [x] `actionOrderStatusPostUpdate` capture across supported PrestaShop families
- [x] normalized order contract and stable `OrderKey`
- [x] `ORDER_CREATED`, `ORDER_UPDATED`, `ORDER_PAID`, `ORDER_CANCELLED`
- [x] order Data Extension upsert by External Key
- [x] local order ledger with cart-to-order conversion tracking
- [x] cart conversion lookup prepared for abandoned-cart suppression
- [x] queue locking/retry through the shared queue infrastructure
- [x] upgrade path from 0.2.2
- [ ] order historical backfill (only if required by first production deployment)

## 0.4.0 — Abandoned carts

- cart activity capture
- configurable inactivity threshold (default 60 minutes)
- cart + cart item snapshot
- eligibility checks
- fail-closed order conversion check through `sfmc_order_sync`
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

- health/status dashboard
- queue metrics
- privacy/log retention controls
- release ZIP and upgrade scripts
- runtime certification matrix for PrestaShop 1.7.8.x, 8.x and 9.x
