# Compatibility policy

The connector aims to serve the broad modern PrestaShop installed base without making the core integration dependent on one PrestaShop generation.

## Target PrestaShop families

| PrestaShop | Initial support target | PHP context |
| --- | --- | --- |
| 1.7.8.x | Yes | legacy PHP generation, including PHP 7.1–7.4 depending on shop runtime |
| 8.x | Yes | PHP 7.2+ through the PHP versions supported by the installed PrestaShop 8 release |
| 9.x | Yes | PHP 8.1+ through the PHP versions supported by the installed PrestaShop 9 release |
| <= 1.7.7 | No initial certification | evaluate only if a concrete deployment justifies it |
| 1.6.x | No | outside the architecture target |

The table describes the connector policy, not a replacement for the official PrestaShop PHP compatibility matrix. A store must always use a PHP version supported by its exact PrestaShop release.

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
3. **Runtime certified** — hooks, queue, Salesforce payloads and critical flows pass integration tests on that exact family.

We must not claim runtime certification based only on PHP linting.

## CI direction

The initial CI syntax matrix covers representative runtimes from PHP 7.1 through PHP 8.5. Runtime PrestaShop fixtures/containers will be added progressively and become the release gate before declaring broad production compatibility.
