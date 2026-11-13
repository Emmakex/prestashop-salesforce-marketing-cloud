# ADR propuesta · Fecha de modificación y modo de actualización G3-03/G3-04

**Fecha:** 2026-11-13  
**Estado:** PROPUESTA / SIN APROBACIÓN DE RESPONSABLE, PRIVACIDAD NI DATA 360  
**Comercio piloto:** ABH / Barcelona Confort. **Producto:** Marketing Cloud Growth/Next + Data 360 (no Engagement).

## Contexto

`PS_TIMEZONE=Europe/Madrid` y columnas MySQL `DATETIME` se han verificado en BC, pero no se conoce con certeza el origen de cada escritura histórica. Las horas 02:00–03:00 de cambios DST pueden ser ambiguas o inexistentes. Los nuevos `captured_at_utc` de hooks miden observación, no actualización causal. La aceptación local no constituye una versión monotónica del registro de origen.

Salesforce exige seleccionar el Refresh Mode del Ingestion API Data Stream cuando se crea y no permite modificarlo después. Las actualizaciones parciales de **streaming** requieren Primary Key y Record Modified. En **bulk**, incluso con stream Partial, los campos ausentes se procesan como upsert y pueden convertirse en null. No se confunde Full con sustitución automática completa de todos los registros de la tabla.

## Opciones para decisión humana, por objeto

1. **v0.2 sin Record Modified + Full**: elimina la falsa precisión temporal de los históricos, pero exige contrato de entrega de filas completas, relectura, reconciliación y protección de actualizaciones tardías. No está aprobado ni garantiza orden.
2. **v0.3 con source_updated_utc verificado + Full**: candidato solo tras identificar la fuente de modificación real por registro, verificar relojes, cambios de estado, relectura y tratamiento de borrados.
3. **v0.3 con source_updated_utc verificado + Partial streaming**: candidato solo con PK, timestamp de modificación fuente, verificación de cambios, retries, control de consistencia y trazabilidad; no hay fallback a bulk para parches.
4. **Partial bulk para parches**, **source_updated_local** como Record Modified y **captured_at_utc** como modificación fuente: rechazados como diseños seguros con la evidencia disponible.

**No se elige opción en este ADR**. Las opciones sintéticas solo alcanzan `review_candidate_only`, jamás `approved`, `upload` o `ingest`.

## Implementación / evidencia

Se incorpora un guard de revisión sin efectos secundarios bajo `tests/review/G3RecordModifiedRefreshReview.php`, con pruebas negativas ejecutadas en PHP 7.1 y 8.5 (`tests/g3-record-modified-refresh-review-contract.php`). No cambia módulos, transportes, esquema v0.2/v0.3, datos ni credenciales reales. El [expediente de decisión](../G3_RECORD_MODIFIED_REFRESH_MODE_DECISION.md) incluye el escenario Partial/bulk y los requisitos exactos.

Fuentes Salesforce:
- https://developer.salesforce.com/docs/data/data-cloud-int/guide/c360-a-create-ingestion-data-stream.html
- https://developer.salesforce.com/docs/data/data-cloud-int/guide/c360-a-perform-partial-update-on-record.html
- https://developer.salesforce.com/docs/data/data-cloud-int/references/data-cloud-ingestionapi-ref/c360-a-api-insert-records.html

## Aprobaciones que faltan

Responsable técnico: escritura/UTC histórico verificable, versión real de cambio, cutover y estado de reloj. Arquitecto de Data 360 y propietario de datos: una decisión **por PSCustomer y PSOrder** sobre esquema, modo, Record Modified, campo/versión, tratamiento de columnas no presentes, reintentos y borrados. Privacidad: finalidad y retención del dato que llegue a DLO/DMO.

El issue #45 continúa abierto; G3-08 seleccionará el YAML/hash exacto después de todas las decisiones G3. Subida y operaciones reales necesitan aprobación G4+ separada.

**Reversibilidad:** revertir exclusivamente pruebas, documentación y contrato CI; `pssalesforcebridge` permanece 0.7.1 Test sin reinstalación.
