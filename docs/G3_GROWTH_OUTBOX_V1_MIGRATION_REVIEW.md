# G3 — Nueva cola Growth independiente, reversible y bloqueada (laboratorio)

**Fecha:** 13/11/2026. **Proyecto:** puente PrestaShop reutilizable, primera tienda ABH/Barcelona Confort.  
**Destino:** Salesforce Marketing Cloud **Growth/Next + Data 360**, **NO Engagement**.  
**Estado:** propuesta y prueba de CI en MySQL 8/MariaDB 10.11, **NO instalada**, **NO aprobada para producción**.

## Problema que resuelve

El módulo instalado 0.7.1 incorpora `sfmc_queue` y los ledgers
`sfmc_customer_sync` y `sfmc_order_sync` asociados al adaptador
histórico de Engagement. La cola antigua utiliza una clave única
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
  workers de `sfmc_queue` de Engagement.
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
