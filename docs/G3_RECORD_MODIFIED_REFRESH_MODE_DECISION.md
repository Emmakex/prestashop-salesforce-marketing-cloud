# G3-03 / G3-04 · Decisión de fechas, Record Modified y Refresh Mode — ABH/PST

**Fecha:** 2026-11-13 · **Estado: REVIEW ONLY / ninguna decisión G3 aprobada.**  
**Producto:** Salesforce **Marketing Cloud Growth (Next) + Data 360** por el conector Ingestion API `PST`. **No Marketing Cloud Engagement**.

## Distinguir hechos de hipótesis

**Verificado en Back Office de BC:** shop 1, zona actual `Europe/Madrid`, columnas `customer.date_upd` y `orders.date_upd` como MySQL `DATETIME`; comprobación de metadatos **3/3 PASS**. Estas columnas **no contienen offset ni fuente de reloj**. El código PrestaShop 1.7 estándar normalmente utiliza `date()`, pero importaciones y cambios anteriores impiden afirmar un UTC histórico universal.

**Verificado en prototipos offline:** `SourceTimestampNormalizer` rechaza horas ambiguas/no existentes del DST; `LiveEventUtcObservation` obtiene `captured_at_utc` al observar un hook; `StagedAtomicUtcAcceptance` conserva ese instante en duplicados; `StagedAcceptedSequenceReview` distingue una secuencia local de una versión de origen.

**No verificado:** hora absoluta histórica, procedencia de cada `date_upd`, fuente de revisión monotónica de los objetos, nivel de sincronización del reloj, orden causal en origen, actualizaciones completas/parciales de Data 360 y proceso de reconciliación. No se puede declarar ninguno aprobado por tener `PS_TIMEZONE=Europe/Madrid` hoy.

## El detalle de Salesforce que cambia la elección

Según documentación oficial de **Ingestion API**:

- El **Record Modified Field** es opcional para objetos `Profile` / `Other`, pero permite a Data 360 comparar cuándo se modificaron las filas; no utilizar `source_updated_local` ni `captured_at_utc` como ese valor.
- **Refresh Mode** se configura al crear el Data Stream y **no se puede cambiar después**. Ambos objetos deben aprobarlo antes de crear flujos de datos.
- **Partial** conserva los campos ausentes **solo con streaming** y requiere **Primary Key + Record Modified** en una actualización parcial. En las cargas **bulk**, Data 360 procesa registros como upsert y los campos omitidos **pueden quedar a null aunque el Data Stream se haya creado en Partial**. Nunca sustituir streaming por bulk silenciosamente.
- Una respuesta de ingestión `202 Accepted` significa que la petición se aceptó para procesamiento asíncrono, **no** que la fila se haya conciliado con el destino o autorizado para envíos.

Referencias oficiales:
- [Create an Ingestion API Data Stream](https://developer.salesforce.com/docs/data/data-cloud-int/guide/c360-a-create-ingestion-data-stream.html).
- [Perform a Partial Update on an Existing Record](https://developer.salesforce.com/docs/data/data-cloud-int/guide/c360-a-perform-partial-update-on-record.html).
- [Upsert Records / Record Modified](https://developer.salesforce.com/docs/data/data-cloud-int/references/data-cloud-ingestionapi-ref/c360-a-api-insert-records.html).

## Matriz para decidir por objeto (sin aprobar ninguna alternativa)

| Opción | PSCustomer / PSOrder | Requisito obligatorio previo | Riesgo que evita | Estado |
|---|---|---|---|---|
| **v0.2 (22 campos), sin Record Modified** | Full record upsert propuesto | Actualizar **todos** los campos permitidos, leer estado actual, controlar escrituras antiguas y reconciliación de bajas. No suponer orden remoto garantizado. | Evitar fingir una fecha UTC histórica o inventar orden causal | **Pendiente** |
| **v0.3 (24 campos), Full con Record Modified** | Upsert de fila completa | Demostrar `source_updated_utc` a partir de una modificación real verificada de origen + integridad de versión y lecturas actuales | Evitar que una carga parcial elimine valores por omisión | **Pendiente** |
| **v0.3 (24 campos), Partial + streaming** | Actualizaciones de campos seleccionados | Clave primaria + `source_updated_utc` verificado, origen/revisión probados, streaming real, manejo de reintentos, bajas y supresiones | Evitar nulificación de campos no enviados | **Pendiente** |
| **Partial + bulk** | No usar para parches de campos | Sin alternativa automática; requiere rediseño a filas completas y revisión | Bulk actúa como upsert, no como parche seguro | **Rechazado como estrategia de patch** |
| **`date_upd` histórica → UTC asumiendo zona actual** | Ninguno | No utilizar sin evidencia de la zona efectiva de escritura y DST por fila | Evitar errores y colisiones por horas ambiguas | **Cuarentena** |
| **`captured_at_utc` / `accepted_sequence` como versión fuente** | Ninguno | No usar como Record Modified ni watermark causal | Evitar que un evento tardío sobrescriba cambios recientes | **Cuarentena** |

*Nota:* **Full** en una operación de Ingestion API no supone por sí solo una sustitución completa diaria de toda la tabla ni garantiza borrar filas ausentes. El tratamiento de borrados necesita diseño separado. No confundir la configuración de Refresh Mode con los conectores de extracción periódica.

## Prueba ejecutable, no operador de ingesta

`tests/review/G3RecordModifiedRefreshReview.php` ofrece un analizador **puro** de decisiones hipotéticas, sin HTTP, SQL ni acceso a datos reales. Rechaza el uso de reloj local o de captura como fecha de modificación, modificaciones de origen sin evidencia, revisiones obsoletas/no corroboradas, lectura actual ausente, Partial con bulk, campos incompletos en Full y marcas UTC inválidas. Un caso **completamente sintético** con fuente de modificación y revisión independientemente acreditadas puede alcanzar `review_candidate_only`, **nunca autorizado para Data 360**. `source_provenance_certified_for_bc`, `refresh_mode_approved`, `record_modified_authorized`, `data_360_ingestion_authorized` y `marketing_sends_authorized` permanecen siempre en `false`.

`tests/g3-record-modified-refresh-review-contract.php` ejecuta esas pruebas con PHP 7.1 y 8.5 en CI.

## Qué falta para poder cerrar G3-03/G3-04

El responsable técnico debe verificar el **camino real de escritura de cada objeto** y la procedencia/gestión del reloj, distinguir históricos no verificados y fijar el alcance de un futuro cutover con versión fuente independiente. El propietario de datos y arquitecto de Data 360 deben **elegir por objeto y firmar** `v0.2/v0.3`, `Full/Partial`, el campo `Record Modified` y la política de frescura, sustitución de campos, reintentos y borrados. No hay pruebas reales de una revisión fuente fiable para BC, y no se seleccionará un valor por defecto.

La siguiente aprobación de G3-08 fijará el YAML exacto y su SHA-256; **G4 necesitará autorización independiente** incluso si G3 se cierra. Permanecen prohibidos subir el esquema, crear streams, backfill, ingesta o enviar emails.

**Sin credenciales, SQL de BC, ZIP ni modificación de `pssalesforcebridge/`. BC 0.7.1 continúa en Test.**
