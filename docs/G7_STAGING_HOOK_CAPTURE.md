# G7 — PrestaShop → outbox local G3 en staging (módulo 0.8.2)

**Corte 17/11/2026. En 0.8.2 la autoría visible es ALFABH y la verificación de motor admite MariaDB 11.8 con CHECK activo, tras CI real. Funcionalidad local desactivada por defecto; aún no envía datos a Salesforce.**

## Qué ya funciona en esta versión

El módulo registra ahora una ruta de captura **desde sus hooks nativos**:

`actionCustomerAccountAdd` → `CUSTOMER_CREATED`

`actionCustomerAccountUpdate` → `CUSTOMER_UPDATED`

`actionValidateOrder` → `ORDER_CREATED`

Los tres atraviesan `GrowthStagingHookCapture`, el router Growth-only y el mismo `GrowthBlockedHookAdapter`/G3 `BlockedOutboxCoordinator` existentes. `GrowthStagingOutboxStore` escribe atomicamente en las **mismas** tablas con prefijo de PrestaShop `growth_provider_binding_v1`, `growth_outbox_v1` y `growth_entity_ledger_v1` (una sola outbox y su ledger, sin cola adicional).

- Captura exclusivamente IDs de entidad, tienda, clase de evento, instante observado UTC, secuencia y huella irreversible. El HMAC-SHA1 de 40 caracteres se obtiene de la versión fuente del evento usando un secreto aleatorio exclusivo de la instalación; el G3 dedupe final es SHA-256 por namespace. No se guarda en estas tablas el email, nombres, dirección, productos, carrito, importes, tokens ni JSON del cliente/pedido.
- Bloqueo y deduplicación transaccionales: `GET_LOCK`, `SELECT ... FOR UPDATE`, `INSERT`, ledger y `COMMIT`; la desactivación impide nuevas capturas. Estado SQL `blocked` obligatorio por `CHECK`. Sin reclamo worker, OAuth, HTTP, envíos Growth ni reintentos.
- Fallos de DB o datos de hook incompletos **no interrumpen una venta ni envían datos**; la captura queda `local_capture_not_recorded` sin revelar datos identificativos.
- `0.8.1` incluye upgrade no destructivo `upgrade-0.8.1.php`; tanto en instalación nueva como upgrade la captura empieza en `off`. No modifica tablas de 0.7.1 ni habilita procesamiento.
- La nueva pantalla BO permite activar o detener **solo captura local Test** tras configurar Merchant/Namespace. La activación exige frase exacta `ENABLE_STAGING_LOCAL_CAPTURE_ONLY` y crea expresamente las tres tablas Growth G3 (si están totalmente ausentes) o verifica el esquema existente. No se ejecuta en `install()`, `upgrade()` ni cron. Una instalación parcial o columnas/engine incorrectos exige reparación controlada; nunca DROP automático.

## Cómo probarlo con autorización en un staging

**No ejecutar este paso en Barcelona Confort producción (0.7.1 Test).** El propietario debe aprobar previamente tratamiento local de los identificadores y conservar un backup/reversión. Las tablas nuevas son prueba de staging, no certificación legal ni autorización de ingesta comercial.

1. Instalar el ZIP 0.8.2 exclusivamente en staging, tras pasar CI y certificación PrestaShop 1.7.8.11 / 8.2.8 / 9.1.5. No reemplaza la 0.8.0 ya instalada hasta decisión expresa.
2. En Back Office → módulo Salesforce Marketing Cloud Growth + Data Cloud, guardar Merchant y namespace exclusivo de esta instalación. La procedencia temporal de BC `Europe/Madrid` y staging PS9 `UTC` se respetan por separado.
3. Verificar motor **MySQL 8.0.16+** (probado MySQL 8) o **MariaDB 10.11 / 11.8 con CHECK activo**. MySQL 5.7, versiones o `CHECK` desconocidos se rechazan; compatibilidad de PrestaShop ≠ seguridad de su servidor SQL.
4. Escribir `ENABLE_STAGING_LOCAL_CAPTURE_ONLY` en el campo del panel **G7 - Local Test capture** y pulsar **Prepare & enable local Test capture**. Esto crea una vez las tres tablas de origen G3. Si no hay consentimiento/backup/aprobación, dejar desactivado.
5. Crear o actualizar un cliente y crear un pedido **sintéticos dentro del PrestaShop staging**. Comprobar filas `blocked` y un ledger para cada entidad. No usar PII de compradores reales para el test. Repetir el mismo hook no debe crear otra fila; una modificación sí.
6. Pulsar **Stop local capture** para detener nuevas filas. Los datos ya observados se conservan para revisión; no hay limpieza automática ni reversión destructiva.

## Certificación

- Contrato unitario offline `tests/g7-staging-hook-capture-contract.php` sobre PHP 7.1/8.5: identidad de fuente, HMAC, dedupe, cambios de cliente, pedido, fail-closed y no transmisión.
- `tests/integration/g7_staging_capture_prestashop_db.php` en g3_ci **MySQL 8 y MariaDB 10.11**: aprovisionamiento manual, captura desde un hook de cliente y pedido, deduplicación/ledger real, estado `blocked`, desactivación y limpieza vacía por migrador sandbox.
- Certificación runtime PrestaShop 1.7/8/9 de instalación, actualización, cron, no activación en las bases MySQL 5.7 de Docker, preservación de 0.7.1 y rollback. El schema staging no se crea durante estas pruebas; debe ser invocado solo tras opt-in.

## Lo que **NO** está terminado

- Futura migración aprobada de columnas de claim/lease a tablas de tienda, así como worker autónomo, relectura fresca de cliente/orden, conversión coherente de fechas históricas, envío real con OAuth, conciliación de HTTP 202 y prevenciones de bajas/compras.
- Consentimiento nativo Growth (Marketing / Email) en el tenant: la configuración global se observó, pero la autorización por destinatario/Business Unit/Flow no está demostrada.
- PSCart (no forma parte del PST v0.4), Flow de abandono, pruebas de preferencias/baja y campañas reales.
- La ingesta sintética a DLO de PSCustomer + PSOrder está acreditada mediante GitHub Actions; **eso no certifica el circuito de eventos del módulo hasta que staging reciba eventos y hasta que worker/G6 estén aprobados**.

Referencias: [G5 canary](G5_CONTROLLED_SYNTHETIC_UPSERT.md), [G7 lease CI](G7_DURABLE_LEASE_CANCELLATION.md) y [seguimiento central](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/issues/12).
