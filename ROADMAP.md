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
- [x] runtime certification against real PrestaShop fixtures/containers on pinned representatives: 1.7.8.11, 8.2.8 and 9.1.5

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
- [ ] live end-to-end recovery certification with a real Marketing Cloud Journey

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

### 0.7.0-C0 — Sync correctness invariants

- [x] customer worker claims all `entity_type=customer` events, including `CUSTOMER_BACKFILL`
- [x] customer sync completion is conditional on the exact payload hash that was delivered
- [x] order sync completion is conditional on the exact payload hash that was delivered
- [x] stale events can no longer overwrite a newer pending ledger state
- [x] PHP 7.1 and PHP 8.5 sync correctness contract coverage in CI

### 0.7.0-A — Health & queue observability

- [x] local health service with `healthy` / `warning` / `critical` severity
- [x] protected no-cache JSON health endpoint
- [x] queue counts for pending / processing / retry / failed / processed
- [x] queue breakdown by event type and status
- [x] oldest outstanding event age
- [x] stale processing-lock detection
- [x] production configuration completeness checks without exposing secret values
- [x] test mode treats intentionally unsent backlog as informational
- [x] HTTP 503 for critical health so external monitors can alert
- [x] PHP 7.1 and PHP 8.5 runtime contract tests in CI

### 0.7.0-B — Back Office health dashboard

- [x] Back Office dashboard consumes the same `HealthService` report as the JSON endpoint
- [x] queue counts, outstanding age, stale locks and active event breakdown
- [x] operational issue list and production configuration readiness
- [x] customer backfill, cart snapshot and Journey delivery summaries
- [x] recovery attribution and paid recovered revenue by currency
- [x] output escaping for dynamic event/status values
- [x] dashboard rendering remains local and performs no Salesforce network call
- [x] PHP 7.1 and PHP 8.5 dashboard contract coverage in CI

### 0.7.0-C — Privacy & retention

- [x] configurable retention windows from 1–3650 days
- [x] processed queue cleanup never deletes pending / processing / retry / failed work
- [x] local log retention
- [x] Journey audit cleanup restricted to sent / suppressed rows
- [x] old empty / ineligible / converted / recovered cart snapshots and items are removable
- [x] old abandoned snapshots are removable only without active recovery tokens or unresolved/failed `CART_ABANDONED` queue work
- [x] active snapshots and operationally required abandoned snapshots are retained
- [x] expired or unusable recovery token cleanup
- [x] recovered-token anonymization preserves order/revenue attribution without customer identity
- [x] automatic retention at most once per day from the protected cron
- [x] manual retention action and last-run status in Back Office
- [x] retention failure isolated from commerce-event delivery
- [x] cron error responses sanitized; raw exception messages are no longer exposed
- [x] PHP 7.1 and PHP 8.5 retention contract coverage in CI

### 0.7.0-D — Release hardening

- [x] public module version bumped to `0.7.0`
- [x] Composer PHP floor aligned with the declared PHP 7.1 source-compatibility contract
- [x] `0.6.1 -> 0.7.0` upgrade script materializes missing retention defaults without DDL
- [x] existing merchant retention values are preserved during upgrade
- [x] fresh install and upgrade defaults are checked for contract parity
- [x] versioned `pssalesforcebridge-0.7.0.zip` release artifact
- [x] package validates embedded module version, Composer floor and required upgrade script
- [x] package excludes `vendor/` and local development files
- [x] SHA-256 checksum generated alongside the exact ZIP
- [x] PHP 7.1 and PHP 8.5 release/upgrade contract coverage in CI
- [x] exact-head and post-merge release gate policy documented

### 0.7.0-E — Runtime certification

- [x] reproducible Docker runtime workflow added as a release gate
- [x] pull requests explicitly check out the exact head SHA, not GitHub's synthetic merge ref
- [x] real `0.6.1 -> 0.7.0` module upgrade exercised on each pinned PrestaShop version
- [x] clean 0.7.0 install exercised on each pinned PrestaShop version
- [x] connector tables, defaults and hook registrations verified in real PrestaShop runtimes
- [x] local customer, cart and order hook paths verified with real PrestaShop core objects
- [x] test mode verified to block customer/order/Journey Salesforce writes
- [x] backfill, abandoned-cart detection, retention, health and protected cron exercised
- [x] Front Office remains reachable after connector smoke
- [x] PrestaShop 1.7.8.11 runtime certified
- [x] PrestaShop 8.2.8 runtime certified
- [x] PrestaShop 9.1.5 runtime certified
- [x] runtime-discovered `Db::getRow()` / `Db::getValue()` limit semantics protected by CI contract
- [x] runtime-discovered global `Db` namespace imports protected by CI contract
- [x] runtime certification scope and non-goals documented in `RUNTIME_CERTIFICATION.md`

### 0.7.0-F — Salesforce live certification harness

- [x] manual-only `workflow_dispatch` live certification workflow
- [x] dedicated GitHub environment boundary for tenant credentials and approval controls
- [x] default auth-only stage performs no Data Extension or Journey writes
- [x] Data Extension and Journey stages require explicit live-write confirmation
- [x] harness reuses production `AuthClient`, `RestClient`, Data Extension writers and `JourneyClient`
- [x] OAuth certification verifies configured REST Base URI against Salesforce `rest_instance_url`
- [x] Data Extension stage writes recognizable synthetic customer/order rows using the production field contracts
- [x] Journey stage requires a dedicated active test ContactKey and persists only sanitized evidence
- [x] exact dispatched SHA is verified before certification
- [x] live gate scope and final end-to-end recovery procedure documented in `SFMC_LIVE_CERTIFICATION.md`

### Remaining release/deployment gates

- [ ] run `auth` certification against the target Marketing Cloud tenant
- [ ] run `data_extensions` certification against the target customer/order Data Extensions
- [ ] run `journey` certification against the active `PRESTASHOP_ABANDONED_CART` API Entry Event and dedicated test contact
- [ ] complete live end-to-end abandoned-cart recovery on controlled PrestaShop staging, including one-time link use and recovered-order attribution
- [ ] publish the final `v0.7.0` Git tag and GitHub Release only after exact-main CI + runtime certification is green
