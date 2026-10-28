# Runtime certification — 0.7.0

This document records the reproducible PrestaShop runtime gate for `pssalesforcebridge` 0.7.0.

Runtime certification is intentionally narrower than the compatibility target. The module targets the PrestaShop 1.7.8.x, 8.x and 9.x families, but a green result certifies the exact pinned versions below rather than every patch release that exists in those families.

## Certified matrix

| PrestaShop | Runtime status | Fixture |
| --- | --- | --- |
| 1.7.8.11 | Certified | official `prestashop/prestashop:1.7.8.11` container |
| 8.2.8 | Certified | official `prestashop/prestashop:8.2.8` container |
| 9.1.5 | Certified | official `prestashop/prestashop:9.1.5` container |

The runtime workflow is `.github/workflows/runtime-certification.yml`. Each job checks out the exact pull-request head SHA instead of GitHub's synthetic merge ref. On `main`, it checks out the exact pushed SHA.

## What each runtime job proves

Each matrix entry provisions a real PrestaShop installation with a real SQL server and executes the same sequence:

1. Build an exact module fixture from the public 0.6.1 source state.
2. Install 0.6.1 through PrestaShop's module CLI.
3. Change one merchant retention value and remove another default.
4. Replace the module files with the exact 0.7.0 package under test.
5. Run the real `prestashop:module upgrade` path.
6. Verify the module advances to 0.7.0, preserves the merchant value and materializes the missing default.
7. Uninstall the connector.
8. Perform a clean 0.7.0 installation from the exact ZIP contents.
9. Verify all connector tables, required configuration defaults and registered hooks.
10. Exercise customer, cart and order connector hook methods with real PrestaShop core objects.
11. Verify the customer, order and Journey workers remain fail-closed in `test` mode and perform no outbound Salesforce write.
12. Execute customer backfill, abandoned-cart detection and retention components locally.
13. Verify the local health report.
14. Call the protected HTTP health endpoint.
15. Call the protected HTTP cron endpoint and verify its test-mode response contains no Salesforce secret material.
16. Confirm the Front Office remains reachable after the connector smoke.

The runtime harness is `tests/runtime/run-prestashop-certification.sh` and the core smoke is `tests/runtime/prestashop-smoke.php`.

## Runtime hardening discovered by the matrix

The runtime gate exposed cross-version issues that syntax and isolated contract tests could not reveal. 0.7.0-E therefore also hardens the product and keeps regression guards for them:

- cart-total enrichment catches `Throwable` so incomplete runtime context can never break `actionCartSave`;
- health and cron are machine-to-machine JSON endpoints and no longer depend on theme rendering;
- SQL passed to PrestaShop `Db::getRow()` / `Db::getValue()` never adds a manual `LIMIT 1`, because those helpers own the single-row limit;
- namespaced classes that use PrestaShop's global `Db` class must explicitly import it;
- CI permanently enforces the `Db` single-row and namespace contracts on PHP 7.1 and PHP 8.5;
- runtime module commands execute as the same web user used by the PrestaShop container, avoiding false cache/permission results.

## Exact-head release rule

Runtime certification is valid only when both workflows are green for the same exact commit:

- normal `CI` — syntax, health, sync, retention, release contracts and package;
- `Runtime Certification` — all three pinned PrestaShop runtime jobs.

A pull-request result is not sufficient for release by itself. After merge, both workflows must also pass on the exact resulting `main` SHA before a tag or GitHub Release can be published.

## What this does not certify

This matrix does **not** claim:

- that every PrestaShop 1.7.8.x, 8.x or 9.x patch release has been executed;
- live Salesforce Marketing Cloud credentials or tenant configuration;
- a live Journey Builder send, email delivery or Salesforce-side Data Extension schema;
- arbitrary third-party modules that subscribe to the same PrestaShop hooks;
- unsupported PrestaShop <=1.7.7 or 1.6 installations.

Live Marketing Cloud/Journey certification remains a separate deployment gate. The connector's storefront safety is tested in `test` mode specifically to prove that local commerce flows do not require Salesforce availability.

## Reproduction

From a checkout with Docker available:

```bash
bash tests/runtime/run-prestashop-certification.sh 1.7.8.11
bash tests/runtime/run-prestashop-certification.sh 8.2.8
bash tests/runtime/run-prestashop-certification.sh 9.1.5
```

The GitHub Actions workflow is the release authority because it executes the full pinned matrix and exact-head checkout policy consistently.
