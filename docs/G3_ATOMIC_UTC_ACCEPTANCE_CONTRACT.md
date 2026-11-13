# G3 · Contrato de aceptación UTC persistente e idempotencia — diseño aislado

**ABH/BC: Marketing Cloud Growth + Data 360.**
**Estado 13/11/2026: IMPLEMENTACIÓN CANDIDATA OFFLINE + TEST SINTÉTICO; sin persistencia real ni esquema aprobado.**

## Hallazgo del código actual (0.7.1)

El camino real de hooks es `CustomerEventService::enqueue` /
`OrderEventService::enqueue` → `CustomerSyncLedger/OrderSyncLedger::observe`
→ `QueueRepository::enqueue` (`INSERT IGNORE` de `sfmc_queue`).
El hash comercial de cliente y pedido se calcula **antes** de encolar.
Los workers `CustomerQueueProcessor/OrderQueueProcessor` interpretan el
payload antiguo y lo entregan por el adaptador **Engagement legado**, que
**no es el destino certificado de ABH**.

Este diseño requiere un gate específico para Growth:
- Una marca UTC en el payload **cambiaría el hash de deduplicación** si
  se añade antes del hash (prohibido).
- `QueueRepository::enqueue` registra `created_at`/ `available_at`
  con `date()` local. Son metadatos operativos en zona actual, no
  evidencia `source_updated_utc`.
- `observe()` del ledger y `enqueue()` son **operaciones separadas**,
  sin transacción unificada en este camino. El futuro registro
  persistente y su fila de cola necesitan una única unidad transaccional,
  o podrían quedar desincronizados.
- Un evento antiguo puede salir de la cola después de una versión más
  reciente: la condición de `markSynced` del ledger compara
  `payload_hash` para actualizar el ledger, pero **no bloquea por sí
  sola la escritura remota del worker**, que ya ha llamado `upsert()`.
- Ni un identificador secuencial local ni el instante de aceptación
  certifican que la mutación de origen ocurrió en ese orden.

## Corte implementado ahora

`pssalesforcebridge/src/DataCloud/StagedAtomicUtcAcceptance.php` define
un contrato puro de aceptación nueva que recibe:

- `source_namespace` inmutable, ID de tienda/entidad y tipo de evento;
- `dedupeKey` comercial **ya calculado** por el productor de hooks
  usando su hash SHA1 de payload original, **sin añadir hora**;
- `insertOrLoad`, una función de persistencia inyectada que debe
  implementar **INSERT SI NO EXISTE + cargar original de forma atómica**
  bajo la clave única `(source_namespace, dedupe_fingerprint)`.

El `dedupe_fingerprint = SHA256(namespace + salto de línea + dedupeKey)`
separa instalaciones con IDs coincidentes, sin exportar payload ni email
en la clave del diario. El factory de nueva observación reutiliza
`LiveEventUtcObservation::observeNow()` del PR #47. **Únicamente en la
primera aceptación** se genera `captured_at_utc`; el callback devuelve
la **misma fila y el mismo instante** para reintentos y duplicados.

Se valida el tipo de evento, namespace, ID, clave legacy, estructura
exacta de la fila devuelta, marca UTC canónica, secuencia positiva y
ausencia de campos extra (no devolver PII). Si el adaptador devuelve
una identidad distinta, simula duplicado generando una marca nueva o
declara una inserción sin fábrica, el contrato falla. La clase no
consulta base de datos, ni llama al worker ni a Salesforce.

`StagedAcceptedSequenceReview::inspect(seq, latestSeq)` devuelve
`older_local_acceptance_quarantine` si la secuencia local es anterior;
si coincide con la última, devuelve `latest_local_acceptance_unverified`.
**Ambos estados mantienen `allow_outbound_apply=false`**. Esto es
intencionado: un número de autoincremento refleja orden de aceptación
en un diario, no necesariamente versión del registro ni orden de
escritura en PrestaShop.

## Prueba transaccional real, aislada de BC

Se ha añadido el [laboratorio InnoDB MySQL/MariaDB](G3_MYSQL_ATOMIC_OUTBOX_SANDBOX.md)
en `tests/integration/`. El nuevo CI ejecuta **transacciones reales**
en una base desechable y demuestra rollback de journal/outbox/ledger,
deduplicación y ocho procesos concurrentes sin perder la marca UTC.
La salida del laboratorio está siempre `blocked`; su esquema y sus
clases **no forman parte de un upgrade PrestaShop**. Esto valida la
**viabilidad técnica de una arquitectura de aceptación**, no certifica
las escrituras del módulo 0.7.1 ni autoriza Data 360 o Growth.
La migración, la cola/ledger reales, la fuente de versiones y la
retención continúan pendientes de aprobación G3.

## Futuro diseño físico (NO ejecutar; requiere aprobación + release)

Se propone, no se crea, una tabla de diario por instalación con:

| Columna propuesta | Tipo conceptual | Uso |
| --- | --- | --- |
| `accepted_sequence` | BIGINT UNSIGNED AUTO_INCREMENT | Secuencia técnica local, no reloj del origen |
| `source_namespace` | ASCII VARCHAR(32) | Separación por instalación |
| `shop_id` | INT UNSIGNED | Alcance de tienda |
| `entity_type` | customer / order | Tipo de entidad |
| `entity_id` | BIGINT UNSIGNED | ID de origen |
| `dedupe_fingerprint` | CHAR(64) ASCII binaria | Hash del namespace + dedupe legacy |
| `original_event_type` | VARCHAR(32) | Tipo al aceptarse primero |
| `captured_at_utc` | UTC ISO string, 24 caracteres | Instante **de captura**, no de modificación |
| `clock_source` / `clock_sync_verified` | Metadatos de auditoría | Calidad del reloj de ejecución |
| `source_modified_provenance` | Indicador `unverified` | No fabricamos UTC del registro |

**Restricciones pendientes de revisión:** índice único compuesto
`(source_namespace, dedupe_fingerprint)`, clave primaria
`accepted_sequence`, aislamiento por tienda, tabla en prefijo
PrestaShop, almacenamiento de `UTC` y retención/borrado.

El futuro adaptador **debe** mantener una transacción que incluya
el bloqueo/insert atómico del diario, registro en `sfmc_queue` y
actualización del ledger cuando proceda, con rollback integral.
`INSERT IGNORE` por sí solo no prueba inserción nueva; comparar filas
afectadas y cargar la fila original bajo lock. Las claves únicas y los
bloqueos deberán probarse con concurrencia real en una DB de prueba y
versiones MySQL/MariaDB soportadas, **nunca** con datos de BC.
No se presupone que una callback PHP aporte durabilidad: la prueba
actual usa un almacén en memoria exclusivamente.

## Política de frescura que aún bloquea Growth

Antes de conectar un worker real, se necesita una política verificable
de versiones de origen: identidad por comercio/tienda, lectura de
estado actual en PrestaShop, hash/revisión confirmada, recuperación de
pedidos, destino Growth aprobado, reintentos y rechazo de sobrescritura
obsoleta. El journal evita recalcular `captured_at_utc`, **no** resuelve
por sí solo orden causal, conversiones ni consentimiento.

| Pregunta | Respuesta en este corte |
| --- | --- |
| ¿Se genera la marca UTC solo para evento nuevo? | Sí, en contrato sintético |
| ¿El reintento reutiliza la misma marca? | Sí, si `insertOrLoad` cumple atomicidad; prueba fake |
| ¿El hash de deduplicación existente cambia? | No |
| ¿Se separan instalaciones y tiendas? | Sí, mediante namespace y dedupeKey validado |
| ¿Un evento atrasado puede salir a Salesforce? | No desde esta clase; solo revisión, nunca envío |
| ¿Se certificó durabilidad bajo fallos reales? | **No** |
| ¿Se certificó reloj NTP/orden de mutaciones? | **No** |
| ¿Se actualizó el módulo instalado 0.7.1? | **No** |
| ¿Se aprobaron datos/schemas Data 360/Flows Growth? | **No** |

## Pruebas

`tests/data-cloud-atomic-utc-acceptance-contract.php` valida:
primera aceptación, reintento con otro timezone, duplicado
`CUSTOMER_CREATED` → `CUSTOMER_UPDATED` con hash idéntico,
cambio de payload legítimo, llegada tardía, namespace y tienda
diferentes, order/customer con el mismo ID, registros extra/PII, y
rechazo de fuentes inválidas. Todos los resultados permanecen
`record_modified_authorized=false`,
`data_360_ingestion_authorized=false`,
`marketing_sends_authorized=false`.

**Próximo gate real:** revisión de un upgrade separado y rollback del
diario, pruebas de concurrencia/atomicidad y política de versión
fuente, junto a G3-01 (identidad), G3-03/04 (fechas y refresh) y G3-07
(retención), antes de solicitar autorización explícita para escritura
de metadatos en PrestaShop. Ningún SQL de producción se exige al usuario.
