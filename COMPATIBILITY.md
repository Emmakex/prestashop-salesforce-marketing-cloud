# Compatibility policy

The connector aims to serve the broad modern PrestaShop installed base without making the core integration dependent on one PrestaShop generation.

## Target PrestaShop families

| PrestaShop | Support target | Runtime evidence | PHP context |
| --- | --- | --- | --- |
| 1.7.8.x | Yes | 1.7.8.11 certified | legacy PHP generation, including PHP 7.1–7.4 depending on shop runtime |
| 8.x | Yes | 8.2.8 certified | PHP 7.2+ through the PHP versions supported by the installed PrestaShop 8 release |
| 9.x | Yes | 9.1.5 certified | PHP 8.1+ through the PHP versions supported by the installed PrestaShop 9 release |
| <= 1.7.7 | No initial certification | none | evaluate only if a concrete deployment justifies it |
| 1.6.x | No | none | outside the architecture target |

The family rows describe the connector policy, not a replacement for the official PrestaShop PHP compatibility matrix. A store must always use a PHP version supported by its exact PrestaShop release.

Runtime certification is exact-version evidence. Certifying 1.7.8.11, 8.2.8 and 9.1.5 demonstrates the connector on representative pinned releases but does not imply that every other patch in those families has been executed. See `RUNTIME_CERTIFICATION.md` for the reproducible gate and scope.

## Architecture rules

### Stable core

Salesforce authentication, event schemas, queueing, retry logic, idempotency and Marketing Cloud contracts are version-neutral and must not import PrestaShop-generation-specific services.

### Compatibility boundary

All behavior that differs between PrestaShop generations belongs under `pssalesforcebridge/src/Compatibility/` or a dedicated adapter namespace.

Prefer capability detection such as `class_exists`, `method_exists`, hook availability or service availability when it is more reliable than a hardcoded version check.

### No core overrides

The connector should integrate through public hooks, public APIs and module services. PrestaShop core overrides are forbidden unless a future requirement demonstrates that no supported public extension point exists.

### Same event contract everywhere

A `CUSTOMER_UPDATED`, `ORDER_CREATED` or `CART_ABANDONED` event must have the same normalized contract regardless of whether it originated in PrestaShop 1.7.8, 8 or 9. Version adapters normalize input before it reaches the queue.

## Certification levels

Compatibility is tracked in three levels:

1. **Syntax compatible** — module PHP parses on the representative PHP runtime.
2. **Install compatible** — module installs/uninstalls and renders configuration on that PrestaShop release.
3. **Runtime certified** — upgrade/install, hooks, local queue/operations and critical connector flows pass integration tests on that exact PrestaShop release.

We must not claim runtime certification based only on PHP linting or from a different patch release in the same family.

## Runtime-certified 0.7.0 matrix

The 0.7.0 runtime release gate executes:

- PrestaShop 1.7.8.11
- PrestaShop 8.2.8
- PrestaShop 9.1.5

For every pinned version it tests the real 0.6.1 → 0.7.0 upgrade, a clean 0.7.0 install, connector tables/configuration/hooks, local customer/cart/order paths, test-mode outbound blocking, backfill, abandoned-cart detection, retention, health, protected cron and Front Office availability.

Live Salesforce Marketing Cloud/Journey delivery is a separate deployment certification and is not implied by this local runtime matrix.

## CI policy

Syntax/contract CI covers representative runtimes from PHP 7.1 through PHP 8.5. The separate Runtime Certification workflow provisions real PrestaShop containers for the pinned matrix.

Both workflows explicitly check out the exact pull-request head SHA. A release candidate is valid only when normal CI and all three runtime jobs are green for the same exact SHA, and the same gates must pass again on the resulting `main` SHA after merge.
