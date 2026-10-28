# Compatibility policy

The connector aims to serve the broad modern PrestaShop installed base without making the core integration dependent on one PrestaShop generation.

## Target PrestaShop families

| PrestaShop | Support target | 0.7.0 representative runtime certification | PHP context |
| --- | --- | --- | --- |
| 1.7.8.x | Yes | PrestaShop 1.7.8.11 | legacy PHP generation, including PHP 7.1–7.4 depending on shop runtime |
| 8.x | Yes | PrestaShop 8.2.8 | PHP 7.2+ through the PHP versions supported by the installed PrestaShop 8 release |
| 9.x | Yes | PrestaShop 9.1.5 | PHP 8.1+ through the PHP versions supported by the installed PrestaShop 9 release |
| <= 1.7.7 | No initial certification | Not certified | evaluate only if a concrete deployment justifies it |
| 1.6.x | No | Not certified | outside the architecture target |

The table describes the connector policy, not a replacement for the official PrestaShop PHP compatibility matrix. A store must always use a PHP version supported by its exact PrestaShop release.

Runtime certification is representative by supported family. Version `0.7.0` has been exercised on the exact releases listed above; this does not claim that every patch release in each family has been individually executed. Version-specific behavior remains isolated behind the compatibility boundary so additional patch releases can be added to the certification matrix without changing Salesforce-facing contracts.

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
2. **Install compatible** — module installs/uninstalls and renders configuration on that PrestaShop family.
3. **Runtime certified** — critical module flows pass integration tests on a concrete PrestaShop release from that family.

We must not claim runtime certification based only on PHP linting.

## 0.7.0 runtime certification evidence

The `0.7.0-E` matrix runs against real PrestaShop installations with MySQL using the official PrestaShop container images. For each representative version it validates:

- installation of the exact public `0.6.1` code state;
- real `0.6.1 -> 0.7.0` module upgrade, including preservation of merchant configuration values;
- clean `0.7.0` installation from the versioned ZIP;
- connector tables, configuration defaults and required hook registrations;
- customer, cart and order smoke paths using real PrestaShop core objects;
- customer backfill, abandoned-cart detection and privacy retention in the real runtime;
- customer, order and Journey outbound delivery remaining fail-closed in `test` mode;
- protected health and cron endpoints;
- Front Office reachability after connector execution.

The exact pull-request head `de0753fd76584069e22510dc4396e15a429685bb` passed the normal CI gate and all three runtime jobs. It was merged as `main` commit `0dc19cef34cfee39fd95a842991672072276a522`; both commits resolve to the same code tree `49e0c22e8e839607fe4fd6694259a5812698d396`. The post-merge `main` commit also passed the normal CI gate and the 3/3 runtime matrix.

This certification does **not** include live Salesforce Marketing Cloud credentials, a live Journey send, or live end-to-end recovery through Marketing Cloud. Those remain separate production-integration gates.

## CI and release gate

The syntax matrix covers representative PHP runtimes from PHP 7.1 through PHP 8.5. The runtime PrestaShop matrix is now an automated release gate for the supported families and is validated on the exact pull-request head as well as the resulting `main` state. New compatibility claims must add concrete runtime evidence rather than relying on version-range declarations alone.
