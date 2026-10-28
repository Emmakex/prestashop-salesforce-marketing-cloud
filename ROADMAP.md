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
- [x] queue worker claims every supported order event type
- [ ] order historical backfill (only if required by first production deployment)

## 0.4.0 — Abandoned carts

- [x] cart activity capture
- [x] configurable inactivity threshold (default 60 minutes)
- [x] cart + cart item snapshot
- [x] configurable eligibility checks
- [x] fail-closed order conversion check through connector ledger and native orders
- [x] snapshot-based deduplication
- [x] local `CART_ABANDONED` queue events
- [x] cart adapter contract shared by supported PrestaShop families
- [x] upgrade path from 0.3.0

## 0.5.0 — Journey Builder

- [x] API Entry Event integration through `POST /interaction/v1/events`
- [x] configurable `PRESTASHOP_ABANDONED_CART` Event Definition Key
- [x] just-in-time snapshot/order/eligibility revalidation before send
- [x] local Journey event audit trail
- [x] `eventInstanceId` persistence
- [x] suppression reasons for stale/converted/ineligible carts
- [x] retry/backoff and terminal failure handling
- [x] test mode blocks Journey delivery
- [x] upgrade path from 0.4.0
- [ ] live Marketing Cloud Journey certification

## 0.6.0 — Cart recovery

- [x] cryptographically random opaque recovery tokens
- [x] SHA-256-only token persistence; raw tokens are not stored
- [x] configurable expiration, default 168 hours and range 1–720 hours
- [x] atomic one-time-use token claim
- [x] snapshot/shop/cart binding and stale-link rejection
- [x] fail-closed conversion check before recovery
- [x] multishop-aware `RecoveryUrl` population in Journey events
- [x] recovery endpoint with no-store / noindex behavior
- [x] cart duplication and session restoration
- [x] recovery does not implicitly authenticate a customer
- [x] customer/address/carrier sanitization before exposing recovered cart
- [x] source cart → recovered cart relation persisted
- [x] local `CART_RECOVERED` event
- [x] upgrade path from 0.5.0
- [x] recovered cart → order(s) → revenue attribution (`0.6.1`)
- [ ] live end-to-end recovery certification on supported PrestaShop families

## 0.6.1 — Recovery attribution

- [x] map `recovered_cart_id` to every resulting PrestaShop order
- [x] support one recovered cart producing multiple orders/packages
- [x] persist a separate idempotent attribution row per `(shop, order)`
- [x] persist recovery conversion timestamp per order
- [x] persist authoritative order total, currency, state and paid/valid state
- [x] update each attribution row again on later order-status events
- [x] calculate paid recovered revenue from authoritative PrestaShop order totals
- [x] keep revenue separated by currency
- [x] expose recovered carts / attributed orders / paid orders / revenue metrics in Back Office
- [x] reuse the normal order event pipeline; no parallel attribution hook path
- [x] isolate attribution failures so they cannot block order synchronization
- [x] fresh install and 0.6.0 → 0.6.1 upgrade create the same attribution schema
- [ ] optionally synchronize recovery/conversion attribution fields back to Marketing Cloud

## 0.7.0 — Production hardening

- health/status dashboard
- queue metrics
- privacy/log retention controls
- release ZIP and upgrade scripts
- runtime certification matrix for PrestaShop 1.7.8.x, 8.x and 9.x
