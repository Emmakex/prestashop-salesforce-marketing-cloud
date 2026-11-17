# G7 — Lotes reales PSCustomer/PSOrder y respuesta asíncrona, sin activar ingesta

**17 de noviembre de 2026 · pieza reutilizable para el puente Growth-only.**

## Qué se ha implementado

`GrowthStreamingIngestionPlan::prepare` prepara, **solo en memoria**, los micro-lotes para el verdadero endpoint de *upsert* de Ingestion API. El contrato recibe `sourceName` de la conexión concreta, `namespace` de instalación, tienda explícita, objeto y registros. **Reutiliza** el codificador tipado PST v0.4 actual: 11 campos por PSCustomer o PSOrder, FK/PK tipada, mismo namespace/tienda, máximo 50 registros / 200 KB. No añade PSCart, ningún campo de consentimiento ni ninguna segunda cola.

La ruta que prepara tiene exactamente la forma `POST /api/v1/ingest/sources/{sourceName}/{PSCustomer|PSOrder}`; **no** lleva el sufijo `/actions/test`. El origen HTTPS debe estar dentro del dominio de instancias de Data Cloud esperado, sin URL arbitrarias ni redirecciones.

`GrowthStreamingIngestionPlan::classify` fija la semántica de resultado **antes de implementar el dispatcher**:

| Resultado HTTP | Lectura segura | Consecuencia |
| --- | --- | --- |
| **202** en upsert | `accepted_asynchronously_unconfirmed` | Aceptado para proceso asíncrono; no demuestra que esté en DLO/DMO, ni su autorización de marketing |
| **429/500/502/503/504** | `backoff_and_reconcile_before_retry` | Necesita conciliación/reintento posterior bajo una política de outbox, nunca automático |
| **Timeout o conexión cortada** | `unknown_after_transport` | Resultado remoto incierto; no reintentar a ciegas |
| **401** | `authentication_requires_review` | Reautenticación/revisión, no pérdida de evento |
| **400/403/404/409/422** | `request_or_schema_requires_review` | Error de ruta/forma/permisos, no marcar evento entregado |
| **Cualquier otro HTTP** | `unexpected_http_status_hold` | Mantener bloqueo; ni siquiera 200 indica el 202 esperado en upsert |

La clasificación devuelve únicamente estados constantes saneados, sin cuerpo remoto, token, PK, contacto, correo, host o URL. **Todas** las combinaciones mantienen `record_persisted_certified=false`, `automatic_retry_authorized=false`, `outbox_claim_release_authorized=false` y `marketing_sends_authorized=false`. Una aceptación 202 nunca puede liberar por sí sola una reclamación de outbox: hace falta el protocolo posterior de entrega, idempotencia y conciliación.

El `prepare` devuelve un `body` que **puede contener PII**: nunca persistirlo en logs, GitHub Actions, reportes, comentarios, trazas ni UI administrativa. El controlador es una clase pura, no tiene SQL, cURL, ficheros, hooks, cron ni llamadas de red.

## Distinción obligatoria con incidente G5

Salesforce documenta que el `POST /api/v1/ingest/sources/{name}/{object}` devuelve **202 Accepted** como aceptación **asíncrona**. Es la semántica que el futuro sender deberá manejar.

En cambio, `POST .../{object}/actions/test` sirve para **validación síncrona no persistente**. El 202 del test ABH [#43849592253](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/runs/43849592253) continúa siendo una anomalía bloqueante. **NO repetir POST, NO considerar validado el esquema real y NO reutilizar esta clase para esquivar G5**. Corresponde resolver la semántica con Salesforce y auditar si la fila sintética fue procesada.

Referencias oficiales:

- [Upsert Records](https://developer.salesforce.com/docs/data/data-cloud-int/references/data-cloud-ingestionapi-ref/c360-a-api-insert-records.html)
- [Synchronous Record Validation](https://developer.salesforce.com/docs/data/data-cloud-int/references/data-cloud-ingestionapi-ref/c360-a-api-synchronous-record-validation.html)
- [Streaming Ingestion](https://developer.salesforce.com/docs/data/data-cloud-int/references/data-cloud-ingestionapi-ref/c360-a-api-streaming-ingestion.html)

## Qué falta para conectar al outbox existente

1. G5: resolución del incidente y validación certificada del schema PST.
2. G6: consentimientos reales + comprobación nativa al envío y supresiones; los flags PrestaShop no autorizan.
3. G7: **misma** outbox G3 (no cola paralela), esquema de entrega/reintento aprobado, claim/lease duradero, orden por entidad, control de cancelación y nuevos pedidos/bajas en el instante de despacho; reconciliar 202 y timeout antes de repetir. PSCart no tiene DLO aprobado y no se envía.
4. G8: autorización de tratamiento, retención, pilotaje, rollback y puesta en producción. El módulo **0.8.0 sigue desactivado para enviar**. Barcelona Confort conserva **0.7.1 Test**; DNS `marketing.barcelonaconfort.cat` continúa pendiente de validación.

Prueba `tests/g7-streaming-ingestion-plan-contract.php` con fixture G4 original cubre dos objetos, origen/objeto/scope inválidos, cliente/pedido de otra tienda, PK/FK, lote duplicado, status 202, errores HTTP, timeout, PII ausente de reportes y no cableado accidental del transporte. CI PHP 7.1/8.5 más runtimes PrestaShop 1.7/8/9.
