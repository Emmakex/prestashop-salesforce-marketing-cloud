# G3 · UTC de observación en eventos nuevos de PrestaShop

**Estado: prototipo ejecutable offline y tests sintéticos, NO conectado al
módulo de producción ni al conector Data 360**. Fecha: 13/11/2026.

**ABH / Barcelona Confort utiliza Marketing Cloud Growth + Data 360, no
Engagement**. Ya se confirmó en el BO de PrestaShop 0.7.1 que la
tienda 1 tiene `Europe/Madrid`, y que
`customer.date_add/date_upd` y `orders.date_add/date_upd` son
`DATETIME`. El Back Office tiene 3/3 PASS para sus metadatos, pero
**ninguna prueba ha certificado el origen horario de fechas antiguas**.

## Qué se implementa en este corte

La nueva clase de núcleo `DataCloud/LiveEventUtcObservation.php` modela el
instante **de observación por PHP de un evento nuevo de un hook**.
`observeNow(eventType)` ejecuta `time()` y serializa el mismo instante
mediante `gmdate('Y-m-d\\TH:i:s') . '.000Z'`, que es independiente de
`PS_TIMEZONE` y de las horas duplicadas/inexistentes del horario de
verano. `time()` es hora de reloj de sistema, **no prueba de NTP ni
de orden en una cola distribuida**. El valor tiene precisión de
**un segundo**, sin milisegundos realmente medidos.

Solo se admiten los nombres de evento que ya generan los hooks de la
implementación:
`CUSTOMER_CREATED`, `CUSTOMER_UPDATED`, `ORDER_CREATED`,
`ORDER_UPDATED`, `ORDER_PAID`, `ORDER_CANCELLED`.
Eventos históricos, backfill, importaciones y disparos de marketing
están expresamente fuera del alcance. El método no acepta
identificadores personales, fecha `date_upd`, email ni datos de pedidos.

La función devuelve exclusivamente metadatos:

| Propiedad | Semántica |
| --- | --- |
| `captured_at_utc` | Instante de reloj PHP en UTC **cuando se observa**, no cuándo se guardó el registro |
| `captured_at_precision=seconds` | No inventar precisión de milisegundos |
| `clock_source=php_unix_epoch_seconds` | Fuente de reloj declarada explícitamente |
| `clock_sync_verified=false` | Estado NTP y deriva de reloj **sin certificar** |
| `source_modified_provenance=unverified` | No equiparar observación del hook con mutación fuente |
| `source_updated_utc=null` | La fecha UTC del origen **no se ha construido** |
| `record_modified_authorized=false` | NO puede actuar como Record Modified de Data 360 |
| `data_360_ingestion_authorized=false` | Ningún payload ni stream habilitado |
| `marketing_sends_authorized=false` | Ninguna campaña habilitada |

`captured_at_utc` es **un candidato a nuevo campo de observabilidad** para
el diseño final, no un campo presente en los esquemas PST v0.2/v0.3
ni una autorización de incluirlo en Salesforce. No reemplazar
`source_updated_utc` de v0.3 con el valor del momento de envío.

## Persistencia candidata de aceptación (sin conexión al worker)

El [contrato G3 de aceptación write-once](G3_ATOMIC_UTC_ACCEPTANCE_CONTRACT.md)
documenta una función de almacenamiento inyectable con semántica
`insert-or-load` y deduplicación por instalación. Un reintento
conserva el `captured_at_utc` original **solo si el adaptador
implementa el contrato atómico**; todavía no existe repositorio real
ni migración. El orden de aceptación no es orden de modificación
y ningún resultado autoriza Data 360 o campañas.

## Limitaciones que evitamos

- **Historias anteriores:** no se reinterpretan registros existentes
  mediante la zona horaria actual. Si no hay evidencia absoluta fiable,
  el histórico tiene `provenance_unverified` y queda fuera del
  Record Modified incremental.
- **Reintentos y retrasos:** si un evento se reprocesa horas después,
  su `captured_at_utc` debe conservar el instante original, no recalcularse
  en el worker. **Este corte aún no lo persiste**.
- **Idempotencia:** la marca de captura NO se incorpora a los hashes
  de deduplicación de cliente/pedido, porque convertiría reintentos en
  mensajes distintos. La deduplicación sigue basada en la identidad y
  payload existentes.
- **Ordenación:** dos cambios pueden ocurrir en el mismo segundo y
  llegar fuera de orden. Record Modified requiere política explícita de
  frescura/versión/ledger y fuente de actualización certificada.
- **Persistencia y reloj:** faltan evidencia de sincronización del reloj
  del servidor, captura atómica respecto al registro y pruebas de reinicio
  del proceso, colas y DST con escritura real.

## Integración futura (requiere un corte separado)

1. Confirmar clock sync en el servidor que ejecuta los hooks;
   instrumentar la **captura en el borde del hook** sin PII en logs.
2. Guardar `captured_at_utc` de manera durable **una sola vez** al aceptar
   el evento, preferentemente en la misma operación idempotente de cola.
   Reintentos deben conservar el dato original.
3. Crear una política contra updates obsoletos y duplicados que separe
   `captured_at_utc` de un verdadero `source_updated_utc`.
4. Aprobar como responsable de datos los campos, conservación y
   exposición a Data 360, junto al resto de G3. Solo después considerar
   un esquema final PST (sin subida automática).
5. Validar mediante pruebas sintéticas que timestamps capturados no
   habilitan ingesta ni mensajes Marketing Cloud Growth.

## Pruebas y frontera de despliegue

- `tests/data-cloud-live-utc-observation-contract.php` recorre
  los seis eventos y zonas `UTC`, `Europe/Madrid`,
  `America/Lima`, `Pacific/Auckland`; la fecha UTC no depende
  de `date_default_timezone_set`.
- Pruebas negativas rechazan eventos arbitrarios, backfill, tipos
  incorrectos y validan que ninguna respuesta pueda certificar
  Record Modified, ingesta ni envíos.
- **No modificación de hooks, colas, esquema YAML, migrations, worker,
  credenciales, Salesforce ni configuración. No hay upgrade ni ZIP
  nuevo que instalar en BC**. El nuevo código está versionado como
  prototipo candidato y no se ejecuta automáticamente por existir
  dentro del árbol del módulo.

Estado G3-03/G3-04: **PENDIENTES**.
