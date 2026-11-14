# G3 — Nueva cola Growth independiente, reversible y bloqueada (laboratorio)

**Fecha:** 13/11/2026. **Proyecto:** puente PrestaShop reutilizable, primera tienda ABH/Barcelona Confort.  
**Destino:** Salesforce Marketing Cloud **Growth/Next + Data 360**, **solo Growth + Data Cloud**.  
**Estado:** propuesta y prueba de CI en MySQL 8/MariaDB 10.11, **NO instalada**, **NO aprobada para producción**.

## Problema que resuelve

El módulo instalado 0.7.1 incorpora `sfmc_queue` y los ledgers
`sfmc_customer_sync` y `sfmc_order_sync` asociados al adaptador
histórico de producto discontinuado. La cola antigua utiliza una clave única
`dedupe_key` **sin namespace de instalación**. No podemos convertirla
en una cola Growth con `UPDATE` ni reapropiarnos de sus filas, porque
crearía posibles colisiones, transacciones incompletas y envíos por el
proveedor equivocado.

La decisión técnica propuesta es **no modificar ni migrar filas
antiguas**: usar dos tablas nuevas **solo Growth** dentro del prefijo
propio de cada instalación, y una identidad lógica explícita
`source_namespace:shop_id:entity_type:entity_id`.

## Contrato físico candidato v1

| Tabla lógica | Responsabilidad | Estado predeterminado |
| --- | --- | --- |
| `growth_outbox_v1` | Diario/cola de observaciones nuevas en UTC. Identidad de instalación, PK secuencial local, fingerprint idempotente por instalación y tipo de evento | `blocked` |
| `growth_entity_ledger_v1` | Última aceptación **local** por instalación/tienda/entidad; FK al outbox | `blocked` |

Los identificadores físicos definitivos, longitud de prefijos,
política de retención y namespace deben quedar autorizados antes de
hacer una migración de módulo. En CI se utilizan exclusivamente las
tablas `g3_growth_outbox_v1` y `g3_growth_entity_ledger_v1` dentro
de la base temporal `g3_ci`; no se crean objetos en BC.

La nueva cola deliberadamente **no almacena emails, nombres, tokens,
RecoveryUrl ni payloads de clientes**. Solo referencias de origen y
metadatos pseudónimos. La minimización, el borrado de referencias y el
período de retención requieren revisión del responsable (G3-07).

### Restricciones de seguridad

- `UNIQUE(source_namespace, dedupe_fingerprint)` con colación
  binaria; los mismos IDs de otra instalación son independientes.
- `accepted_sequence` y `captured_at_utc` **no** demuestran la
  versión causal del registro, su actualización UTC histórica ni
  permisibilidad de una campaña.
- `status='blocked'` en **ambas tablas**, con restricciones SQL
  `CHECK`. Este esquema **no tiene estado de salida habilitable**;
  cualquier futura activación necesita otra decisión y migración.
- El guardado de outbox y ledger se produce dentro de **una misma
  transacción InnoDB** y bajo bloqueo por
  namespace/tienda/tipo/entidad. El reintento del mismo fingerprint
  conserva el instante de la primera aceptación.
- La cola Growth nunca comparte el mecanismo `claimBatch()` ni los
  workers de `sfmc_queue` de producto discontinuado.
- No se crean Data Streams, Data Lake Objects, Data Model Objects,
  segmentos, Flows ni campañas mediante este componente.

## Instalación y reversión controladas

`G3GrowthOutboxSandboxMigration::install()` comprueba:

1. Base exacta `g3_ci`, usuario sandbox y `G3_CI_ENABLE` explícito.
2. Si ninguna tabla nueva existe, crea ambas y comprueba columnas,
   InnoDB, índice único por namespace, FK y checks de estado.
3. Si existen ambas y pasan la inspección, devuelve **no-op** y
   preserva sus registros.
4. Si solo hay una tabla, detecta una migración parcial y **se detiene**.
   MySQL DDL realiza commits implícitos: no inventamos un rollback
   transaccional de cambios estructurales.

`rollbackIfEmpty()` solo elimina las **dos tablas propias y vacías**,
en orden FK → tabla principal. Con eventos existentes, errores de esquema
o instalación parcial, **rechaza** el rollback destructivo y exige una
decisión de conservación/recuperación. No altera
`sfmc_queue`, `sfmc_customer_sync` ni `sfmc_order_sync`.

Este rollback es **solo una certificación del laboratorio**, no
instrucciones para desinstalar el módulo de BC.

## Pruebas obligatorias en CI

`tests/integration/g3_growth_outbox_v1.php` ejecuta en bases
desechables MySQL 8/MariaDB 10.11:

- Install, re-install idempotente, inspección de schema,
  rollback vacío y re-rollback idempotente.
- Rollback rechazado si hay entradas persistentes.
- Inyección de fallos tras insertar outbox o actualizar ledger;
  rollback de ambas operaciones.
- Duplicados y reintentos que reutilizan el instante UTC original,
  además de un evento comercial nuevo con su secuencia distinta.
- Reintento antiguo que no retrocede el ledger local.
- Varias tiendas, instalaciones y clases de entidad con el mismo
  identificador numérico sin colisión.
- Ocho procesos de PHP con conexiones reales sobre un mismo evento,
  que deben producir una sola entrada.
- Intentos de cambiar `status` a `pending`/`ready` rechazados
  por la propia base de datos.
- Confirmación de que las tablas espejo antiguas mantienen su
  cantidad de filas antes y después de las pruebas.
- Instalación parcial deliberada que detiene install y rollback,
  seguida de reparación **únicamente en la BD de CI**.

## Qué sigue bloqueado para entregar ABH

1. **G3-01:** asignar y registrar un `source_namespace` exclusivo
   e inmutable por instalación, válido para otras tiendas.
2. **G3-03 y G3-04:** evidenciar reloj del servidor y diseñar la
   versión de origen, frescura y Record Modified para Data 360.
   No usar `captured_at_utc` como revisión causal.
3. **G3-07:** definición de finalidades y conservación/eliminación de
   identidades y eventos, incluidos datos de clientes y pedidos.
4. Aprobar el esquema `PSCustomer`/`PSOrder`, privacidad,
   categorías, DLO/DMO y modos de actualización (gate G3 completo).
5. En **PR separado**, preparar un upgrade seguro PrestaShop
   1.7/8/9 (prefijos, permisos, rollback/backup y concurrencia);
   **solicitar aprobación** antes de ejecutarlo en BC.
6. Implementar el adaptador real de transporte **Data 360** con
   pruebas sintéticas, relectura del estado del cliente y controles
   de consentimiento/supresión Growth antes de conectar cualquier worker.

**No requiere acceso SQL del usuario ni nuevo ZIP.** BC continúa con
`pssalesforcebridge` 0.7.1 en modo Test; este laboratorio solo
demuestra el comportamiento de un diseño aislado.


## Capa de selección de proveedor ya codificada, pendiente de activación (14/11/2026)

El nuevo `pssalesforcebridge/src/Provider/ExplicitProviderRouter.php` es **código reutilizable del módulo**, no una clase del laboratorio. Separa el plan local por comercio, instalación, tienda, entidad y proveedor expresamente elegido: `source_namespace:shop_id:entity_type:entity_id`. Acepta candidatos PrestaShop `1.7.8.x`, `8.x`, `9.x`; los casos exactos certificados por CI y runtime existente siguen siendo `1.7.8.11`, `8.2.8`, `9.1.5`. El registro lógico no deriva namespace de URL, base de datos ni shop ID. La unicidad global del namespace **no está certificada** (G3-01).

**Regla fija del piloto:** `ABH` requiere seleccionar explícitamente `marketing_cloud_growth`; seleccionar producto discontinuado devuelve error y **no** existe fallback automático. Otros comercios pueden preparar una ruta `unsupported_external_product` independiente, pero queda sin certificación de tenant ni permiso de envío. Todas las rutas se limitan a `mode=test`, `status=blocked` y flags de ingesta, Flow, marketing y worker `false`; `dispatch()` siempre rechaza. No hay DB, Salesforce, cola ni HTTP en este componente. No se integra todavía al bootstrap o worker del módulo instalado 0.7.1, no hay upgrade ni configuración nueva. Ninguna prueba de este selector es consentimiento, aprobación de PST o autorización para cambiar el proveedor operativo.

La prueba `tests/growth-explicit-provider-routing-contract.php` comprueba sintéticamente identidad entre instalaciones/tiendas/entidades, tres familias PrestaShop, selección Growth de ABH, rechazo de producción y selección ambigua, aislamiento del legado y prohibición de salida. **Siguiente paso productivo tras cerrar G3:** unir el selector explícito con el outbox Growth namespaced y una instalación/rollback autorizados, después con el transporte Growth homologado, sin reutilizar registros ni sender producto discontinuado. El CI de contratos **no sustituye** certificación de integración real de este componente en tiendas.


## Integración local real entre selector Growth y outbox, sin instalar (14/11/2026)

Se añade `pssalesforcebridge/src/Growth/BlockedOutboxCoordinator.php` al código reutilizable del módulo. Su función `capture()` consume **el selector real** `ExplicitProviderRouter` y el **contrato real de aceptación atómica** `StagedAtomicUtcAcceptance`. Exige explícitamente un almacenamiento `insertOrLoad` transaccional; nunca construye una conexión SQL ni usa la cola `sfmc_queue` de producto discontinuado. Un comercio con proveedor `unsupported_external_product` no puede entregar registros al outbox Growth.

Solo admite `customer` y `order` con los eventos ya registrados por hooks de PrestaShop. Rechaza `cart` antes de cualquier intento de guardar: **PSCart no existe en el esquema aprobado**. La identidad incluye namespace explícito, tienda, tipo e ID de entidad; la deduplicación utiliza el contrato existente de fingerprint sin timestamp. El resultado regresa únicamente estado `blocked_local_only`, `accepted_sequence`, instante UTC de aceptación y flags que **siempre son false** para ingesta, Flow, envío, cola externa, versión de origen y unicidad global del namespace. **Ni la secuencia ni el instante UTC demuestran que el registro haya sido modificado en ese momento.**

Ahora existe también una prueba de integración de extremo a extremo **local**: `tests/integration/g3_growth_provider_outbox_bridge.php` enlaza las dos clases del módulo con `G3GrowthOutboxSandboxStore` y las dos tablas exclusivas de Growth dentro de la BD desechable `g3_ci`. Se ejecuta sobre MySQL 8/MariaDB 10.11, comprueba reintentos, restauración de timestamp, fallo de transacción, varios comercios/tiendas/entidades, historial que no retrocede, bloqueo SQL de `status`, rechazo de producto discontinuado/carts, rechazo de rollback no vacío y conservación de contadores de las tres tablas legadas. La otra prueba `tests/growth-provider-outbox-bridge-contract.php` verifica el comportamiento sin DB en PHP 7.1/8.5.

**Importante:** se trata de un contrato funcional ya codificado, pero `pssalesforcebridge.php` no lo carga ni lo llama, no hay configuración de proveedor nueva, upgrade ejecutable ni migración de BC. Las tablas `g3_*` son exclusivamente de CI. Implementar una migración real con prefijo PrestaShop, datos de cliente, política de retención y acoplamiento a hooks exige aprobación separada después de G3. No es un Growth sender, y ninguna operación sale a Salesforce. Barcelona Confort continúa en **0.7.1, modo Test**, sin ZIP ni acceso SQL del usuario.


## Preflight físico del upgrade por prefijo (14/11/2026): código real, **no ejecutado en tiendas**

El componente `pssalesforcebridge/src/Growth/GrowthOutboxSchemaPlan.php` entrega un **plan puro, determinista y sin conexión SQL** para la siguiente actualización de la extensión en PrestaShop 1.7.8.x/8/9. Reutiliza `ExplicitProviderRouter` para validar comercio/versión/proveedor/namespace y rechaza cualquier selección de producto discontinuado; no lee `_DB_PREFIX_` ni extrapola el namespace del dominio o de la base. El prefijo debe suministrarse explícitamente y cumplir una lista segura de caracteres y longitud.

El plan propone **tres tablas nuevas**, siempre separadas de `sfmc_*`:

| Tabla relativa al prefijo | Responsabilidad | Protección |
| --- | --- | --- |
| `growth_provider_binding_v1` | Vínculo explícito namespace/comercio/versión/proveedor Growth | `provider=marketing_cloud_growth`, `mode=test`, `status=blocked`, CHECK SQL |
| `growth_outbox_v1` | Eventos de cliente/pedido minimizados, fingerprint único por namespace | FK a binding, CHECK `status=blocked`, InnoDB |
| `growth_entity_ledger_v1` | Secuencia aceptada local por instalación/tienda/entidad | FK a outbox, CHECK `status=blocked`, InnoDB |

Los identificadores de clave foránea se derivan de un hash corto del **prefijo de instalación** para evitar colisiones de nombre de constraints en un mismo esquema MySQL con varias tiendas PrestaShop. La secuencia de creación es `binding → outbox → ledger`; el orden previsto de desinstalación vacía es inverso. **No se utiliza `CREATE TABLE IF NOT EXISTS`:** que una tabla exista no demuestra que tenga el esquema esperado. El preflight clasifica inventario como `absent_review_only`, `partial_install_manual_review` o `present_requires_schema_integrity_review`; ninguna clasificación habilita un upgrade automático o encubre una instalación parcial.

Se añaden contratos en `tests/growth-schema-plan-contract.php` (PHP 7.1/8.5) y `tests/integration/g3_growth_prefixed_schema_preview.php` (MySQL 8/MariaDB 10.11 exclusivamente en `g3_ci`). Esas pruebas ejecutan el DDL generado sobre **dos prefijos independientes** y comprueban CHECK bloqueante, unicidad de fingerprint, FK de outbox a selección de proveedor, FK del ledger al outbox, ausencia de efectos en `g3_sfmc_*`, rechazo de rollback no vacío y desmontaje de **solo** las tablas sintéticas ya vacías.

**Límite explícito:** esta clase puede *describir* una migración, pero no contiene llamadas DB, no la ejecuta ni está enlazada al método `install()`, a `upgrade-*.php`, ni al bootstrap del módulo. No se ha elegido todavía el namespace global G3-01, ni aprobado privacidad/retención G3-07, ni autorizado una migración futura. Los SQL generados por el plan **no deben ejecutarse sobre Barcelona Confort**; hacerlo exige revisión del esquema real de cada tienda, backup, guardas transaccionales/idempotencia, revisión de prefijos y autorización de upgrade separada. El producto instalado sigue en **0.7.1, Test**, sin nuevo ZIP ni cambios SQL en la tienda.


## Ejecutor de instalación y rollback — probado exclusivamente en g3_ci (14/11/2026)

`pssalesforcebridge/src/Growth/GrowthSandboxMigrationExecutor.php` es ya un **ejecutor SQL real**, pero con bloqueo deliberado de entorno: el constructor exige la base desechable `g3_ci`, cuenta `g3_ci_user` y las variables exactas del sandbox CI. **No es un instalador de producción**, ni se carga en `pssalesforcebridge.php`, `install()` ni `upgrade-*.php`. No se ha autorizado ninguna actualización real en Barcelona Confort.

El flujo comprobado es `inspect() → install() → inspect() → rollbackIfEmpty()`. `install()` **solo crea las tres tablas propias de Growth cuando no existe ninguna** y registra el vínculo Growth/Test con parámetros SQL. Si las tres ya existen, exige inspección física de columnas, InnoDB, número de CHECK/FK, tablas referenciadas por FK, restricción de borrado, índice único del outbox y la fila exacta de vinculación; devuelve no-op sin modificar registros. Una instalación parcial o deriva estructural se detiene para revisión manual: **MySQL/MariaDB realizan commits implícitos en DDL**, no hay rollback transaccional de un CREATE a medias ni reparación por DROP automático.

`rollbackIfEmpty()` únicamente retira **las tres tablas creadas por ese prefijo**, en orden ledger → outbox → binding, después de verificar el vínculo y confirmar que **outbox y ledger están vacíos**. Con eventos pendientes, tablas parciales o un esquema alterado rechaza el borrado. No toca `sfmc_queue`, `sfmc_customer_sync` o `sfmc_order_sync` del proveedor legacy. Los diagnósticos son códigos fijos sin datos de clientes ni SQL/DSN.

Pruebas nuevas: `tests/integration/g3_growth_migration_executor.php` corre en MySQL 8/MariaDB 10.11, únicamente `g3_ci`. Demuestra instalación y repetición sin duplicación, coexistencia de prefijos distintos, prohibición de cambiar proveedor, bloqueo de rollback con datos, rechazo de cambio de namespace, instalación parcial y esquema alterado, reversión vacía e imposibilidad de ejecutar con bandera CI desactivada. El test contiene pasos de limpieza forzada de **fixtures simuladas y vacías** del sandbox tras inducir deliberadamente daños; esa limpieza no existe en el código del módulo. Si CI detecta un fallo en estos contratos, no se puede fusionar el cambio.

**Antes de crear un ejecutor de producción** faltan G3 (namespace único, PSCustomer/PSOrder, finalidades, retención, UTC y relaciones), confirmación de versión real del motor SQL, verificación completa de definiciones/índices/constraints del esquema instalado, copia de seguridad y procedimiento de recuperación, autorización separada de migración/upgrade y validación real en PrestaShop 1.7/8/9. No debe interpretarse esta prueba aislada como aprobación de producción ni como un nuevo ZIP desplegable. Barcelona Confort continúa en **módulo 0.7.1, Test**.


## Almacenamiento atómico mediante la API de PrestaShop, exclusivamente en CI (14/11/2026)

Se ha añadido `pssalesforcebridge/src/Growth/PrestaShopDbBlockedOutboxStore.php`. A diferencia del almacén `PDO` de laboratorio, esta clase implementa **el contrato real `insertOrLoad()` para el entorno de PrestaShop**, utilizando sus métodos compatibles `getValue()`, `executeS()` con caché SELECT desactivada, `execute()` e `Insert_ID()`. Se integra con el `BlockedOutboxCoordinator` existente mediante una inyección de almacén, **sin conectar hooks, cron, trabajadores ni transportes externos**.

Antes de poder consultar o escribir exige estrictamente `G3_CI_ENABLE=yes_sandbox_only`, DSN y credenciales de prueba definidas por el proyecto, además de `DATABASE()=g3_ci` y `CURRENT_USER()` correspondiente al usuario descartable. **No existe configuración que habilite escritura Growth en Barcelona Confort**. El vínculo físico debe coincidir con namespace, comercio, versión de PrestaShop, proveedor Growth, modo Test y estado `blocked`. No se aceptan entidades `cart`, eventos no previstos ni datos personales.

La ruta utiliza `GET_LOCK` por instalación/tienda/entidad para proteger concurrencia y `START TRANSACTION` → `SELECT ... FOR UPDATE` → alta idempotente en outbox → actualización del ledger → `COMMIT`. Los duplicados conservan secuencia y captura UTC originales. Un error o `executeS=false` nunca se interpreta como ausencia: se intenta `ROLLBACK` y el evento queda sin aceptación. La respuesta del almacenamiento debe cumplir exactamente el contrato existente `StagedAtomicUtcAcceptance`, sin datos extra ni autorización de salida.

La prueba `tests/integration/g3_growth_prestashop_db_store.php` utiliza un doble de los **métodos de `Db` de PrestaShop**, respaldado por MySQL 8 y MariaDB 10.11 desechables: eventos con duplicados, cambios, tienda y entidad distintas, múltiples prefijos, cuatro procesos concurrentes, fallo forzado tras outbox/ledger, SELECT fallido, intento de proveedor producto discontinuado, rechazo de PSCart, imposibilidad de desinstalar con eventos y conservación de los espejos legacy. **No equivale todavía a una ejecución de esa clase sobre una instalación PrestaShop auténtica**; los tests de runtime de las tres versiones deben complementarse con aceptación específica cuando G3 y el upgrade estén aprobados.

BC mantiene `pssalesforcebridge` **0.7.1 en Test**. No hay nueva versión del módulo, ZIP para instalar, SQL sobre una tienda, publicación del esquema PST, permisos Growth, recuperación de carritos ni envío autorizado.


## Compatibilidad SQL y enforcement CHECK — bloqueo seguro antes de cualquier Growth DDL (14/11/2026)

**Problema demostrado:** la matriz real PrestaShop 1.7.8.11 / 8.2.8 / 9.1.5 de este proyecto usa MySQL **5.7** por diseño del contenedor. MySQL 5.7 y MySQL 8.0 anterior a 8.0.16 **ignoran** `CHECK`; por tanto, certificar el módulo en PS 1.7/8/9 no basta para permitir la nueva cola Growth con `status='blocked'`. El manual oficial MySQL confirma el mínimo 8.0.16: https://dev.mysql.com/doc/refman/8.0/en/create-table-check-constraints.html . MariaDB soporta CHECK, pero permite desactivarlos con `check_constraint_checks=OFF`: https://mariadb.com/docs/server/reference/sql-statements/data-definition/constraint .

**Código:** `pssalesforcebridge/src/Growth/GrowthSqlEngineGate.php` ofrece un preflight determinista, sin SQL, y `inspectConnection(PDO)` exclusivamente con `SELECT VERSION()` y, para MariaDB, `SELECT @@check_constraint_checks`. Rechaza MySQL 5.7, MySQL 8.0.15 o anterior, motores desconocidos/capas compatibles no certificadas, MariaDB con CHECK desactivado y otras familias no ensayadas. Devuelve elegibilidad **solo para el ensayo SQL en la base de pruebas**, nunca una autorización para producción. De momento se admiten MySQL 8.0.16+ / 8.4 y MariaDB 10.11 con variable ON, siempre con pruebas adicionales de constraints reales y sin claims de soporte universal.

`GrowthSandboxMigrationExecutor` lo exige **después de comprobar el guard exacto de `g3_ci` y antes del primer CREATE/DROP o vínculo**. Una versión incompatible no puede crear las tablas del plan v1 ni ejecutar rollback en ese ejecutor. `tests/growth-sql-engine-gate-contract.php` cubre falsos positivos de versiones y variantes; tests de integración existentes continúan ejercitando SQL real MySQL 8 y MariaDB 10.11 para comprobar enforcement e idempotencia.

**Próxima preparación, sin pedir SQL al propietario de BC:** el ejecutor de instalación para comercios debe incorporar una detección exclusivamente de lectura de motor, transacciones, FK/CHECK, plan de copia/restauración y revisión del esquema; si la producción tiene MySQL 5.7, no autorizar el DDL Growth actual. El soporte del producto PrestaShop 1.7/8/9 **sigue vigente**, pero el plan de almacenamiento Growth necesita versión del servidor compatible o diseño alternativo seguro, aprobado y probado. El usuario no necesita instalar una versión nueva. G3 no equivale a autorización de SQL, mapeos Data 360 o campañas.
