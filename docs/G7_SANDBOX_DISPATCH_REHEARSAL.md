# G7 — Ensayo de despacho sobre outbox G3 (CI, sin red)

**Corte 17/11/2026.** La captura local de staging ya tiene una primera evidencia real `CUSTOMER_UPDATED`, pero las altas de cliente siguen pendientes de diagnóstico de posible HTTP 500 en PrestaShop 9. **Esta mejora NO depende del error de alta** y no requiere acceso al servidor ni instalación de un ZIP.

## Qué se ha conectado

Se utiliza **la misma outbox y ledger G3**, sin crear tablas ni colas adicionales:

1. `GrowthOutboxDeliveryLease::claim` toma una revisión **solo** si el evento está preparado por el *fixture descartable* de CI, es la última versión del ledger y no existe otro claim. En la tienda el `status=blocked` sigue siendo obligatorio y **no hay preparación/claim de envío**.
2. `GrowthStreamingIngestionPlan::classify` clasifica una respuesta HTTP **simulada**. No hay `curl`, cliente HTTP, credenciales, serialización de PII ni llamada a Salesforce.
3. `GrowthBlockedDispatchRehearsal::simulate` persiste únicamente el desenlace del ensayo en la outbox **del sandbox `g3_ci`** mediante el lease existente:
   - **202** → `reconcile` (aceptado asíncronamente, **no entregado**).
   - **Sin respuesta/timeout** → `reconcile` **inmediato**, HTTP `NULL`, sin reintento (nuevo método `markUnknownUnconfirmed` con token).
   - **200 inesperado, 401, 503 u otra respuesta válida diferente de 202** → `held`, inspección manual; ningún envío o reintento.
   - **Cancelación, otro trabajador, revisión antigua o fila blocked** → no obtiene claim.
   - **Cancelación posterior al claim** → la respuesta tardía no revive la fila ni revierte la cancelación.

Los resultados son metadatos controlados que **no contienen identidad del cliente, token de lease, namespace ni payload**. Todos mantienen `outbox_delivery_authorized=false`, `record_persisted_certified=false`, `marketing_sends_authorized=false`.

## Certificación y límites

La prueba `tests/integration/g7_durable_outbox_lease.php` ensaya esos casos contra MySQL 8, MariaDB 10.11 y MariaDB 11.8 reales **solo en la base CI descartable**. Conserva la prueba de cuatro trabajadores concurrentes, cancelación, revisión obsoleta, restricciones `status='blocked'` y restauración del esquema G3.

`GrowthOutboxDeliveryLease` comprueba doblemente entorno/usuario/schema `g3_ci` y no admite bases de tiendas. El ensayo no se incluye desde `pssalesforcebridge.php` ni desde cron. **No se ha desarrollado todavía el worker operativo**: faltan la fuente de eventos iniciales fiable, versión real por tienda, obtención de snapshot privado actual, contrato de transporte autorizado, reconciliación DLO/DMO, consentimiento Growth por contacto y cierre transaccional entre observación local y envío.

No desplegar esta prueba en PrestaShop, no elevar filas `blocked` a `ready` en staging/producción y no usar HTTP 202 como evidencia de llegada a Salesforce.

## Referencias

- [Lease y cancelación](G7_DURABLE_LEASE_CANCELLATION.md)
- [Clasificación del upsert Data Cloud](G7_STREAMING_OUTCOME_BOUNDARY.md)
- [Seguimiento central #12](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/issues/12)
