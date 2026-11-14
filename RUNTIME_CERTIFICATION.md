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


## Growth Db API runtime verification (14/11/2026, after 0.7.1)

El nuevo contrato `tests/runtime/prestashop-growth-db-api-contract.php` se ejecuta dentro de los **tres contenedores PrestaShop reales**, no un mock, después de instalar `pssalesforcebridge` 0.7.1. Verifica `Db::getInstance()` con inserción, `Insert_ID()`, `getValue($sql, false)`, `executeS($sql, true, false)`, SELECT FOR UPDATE, `START TRANSACTION/COMMIT/ROLLBACK`, bloqueo nombrado `GET_LOCK/RELEASE_LOCK` y liberación al repetir. **Únicamente crea una tabla `TEMPORARY` InnoDB** en el contenedor descartable; la retira al terminar, no modifica esquema PST, colas Growth, eventos reales, hooks ni `sfmc_*`. El test exige `GITHUB_ACTIONS=true`, entorno de runtime desechable, versión concreta, módulo 0.7.1 en `test` y BD exacta `prestashop`. Además comprueba que `PrestaShopDbBlockedOutboxStore` rechaza la conexión real de PrestaShop, porque el adaptador sigue limitado a `g3_ci` hasta que G3 y la instalación se autoricen.

El origen de esta prueba es un posible fallo de concurrencia: en PrestaShop, `Db::getValue()` puede cachear SELECT. El store Growth debe forzar `$use_cache=false` para **adquirir y liberar cada named lock** y revalidar el vínculo comercio/proveedor en cada evento. La prueba MySQL/MariaDB usa un doble que simula caché para fallar si una consulta crítica omite el segundo argumento `false`, e introduce una revocación del vínculo entre eventos en CI.

**Límite de interpretación:** una CI runtime verde certifica *el comportamiento de la API Db y las garantías de no activación* en las tres versiones fijadas, pero **no certifica el almacenamiento Growth dentro de una instalación PrestaShop real**, porque la clase conserva su restricción sandbox ni habilita ingesta/emails Growth. La migración y conexión a hooks requieren aprobación del propietario de datos y del upgrade en un cambio separado. La nueva prueba no modifica la versión publicada ni debe usarse para solicitar instalación en Barcelona Confort.


## Comprobación del motor SQL desde Db real de PrestaShop (14/11/2026)

Además de la certificación de transacciones/locks, `GrowthSqlEngineGate::inspectPrestaShop(Db::getInstance())` emplea exclusivamente `getValue('SELECT VERSION()', false)` y, cuando es MariaDB, `getValue('SELECT @@check_constraint_checks', false)`. No guarda datos, no crea tablas y no habilita migraciones. Los valores se reducen a categorías fijas (`mysql`, `mariadb`, `unverified`) y flags booleanos, sin URLs o datos sensibles.

Los **tres contenedores reales PrestaShop** del workflow corren por diseño con MySQL **5.7**. En los tres debe devolver `engine=mysql`, `status=blocked`, `check_constraints_enforced=false`, `schema_migration_authorized=false` y `growth_outbound_authorized=false`. La detección no es un error del módulo: demuestra que, aunque PrestaShop 1.7/8/9 instale la versión legada correctamente, **no** debe migrarse la cola Growth que depende de `CHECK(status='blocked')` a una base MySQL 5.7.

Contratos independientes PHP 7.1/8.5 cubren MySQL 5.7, MySQL 8.0.15/8.0.44, MariaDB10.11 con CHECK ON/OFF, consultas fallidas y cacheo. La ejecución MySQL8/MariaDB10.11 existente sigue validando el DDL real en `g3_ci`.

**Límites:** la comprobación SQL real no supone que se haya consultado el motor de producción de Barcelona Confort, no lo modifica y **no concede autorización G3/G4 ni habilita instalación Growth**. El operador no necesita acceso SQL ni instalar nada.
