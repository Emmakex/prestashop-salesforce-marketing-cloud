# G7 — Reclamación duradera y cancelación en la misma outbox G3

**Corte de código 17/11/2026 · Preparado para certificar el ciclo local, SIN despacho Salesforce.**

## Entregado

`GrowthOutboxDeliveryLease` implementa la parte que faltaba para el control persistente de entrega: usa **las tablas G3 existentes** `g3_growth_outbox_v1` + `g3_growth_entity_ledger_v1`; no crea otra cola ni guarda PII, payload ni tokens de OAuth.

- `claim`: actualización atómica con JOIN al ledger y versión exacta; si hay nueva revisión, compra/baja, cancelación o otra reclamación, se deniega. Token de lease SHA-256 y TTL 30-900 segundos.
- `cancel`: persiste cancelación por evento/scope, incluso con claim activo. **La cancelación no prueba que una llamada remota en curso haya sido interceptada**; queda como bloqueo de nuevas reclamaciones.
- `markAcceptedUnconfirmed`: HTTP 202 solo significa aceptado asíncronamente, pasa a **reconcile**, nunca a delivered.
- `holdClaim`: errores/credenciales quedan retenidos, sin reintentos automáticos.
- `quarantineExpired`: los leases caducados quedan en **reconcile**, nunca se relanzan a ciegas.
- `inspect`: devuelve solo estado/contador/categoría HTTP (sin email, PK o tokens), con `record_delivered_certified=false` y `marketing_sends_authorized=false`.

La **prueba de integración real** crea columnas temporales `delivery_state`, `lease_token_hash`, `lease_until_epoch`, `delivery_attempts`, `delivery_last_http` en la **misma tabla** G3 del servidor MySQL/MariaDB desechable, mantiene la constraint original `status='blocked'`, inserta eventos sintéticos mediante `StagedAtomicUtcAcceptance`/G3 store, ejecuta **4 procesos concurrentes reclamando la misma secuencia**, pruebas de idempotencia, baja, cancelación en vuelo, cambio de versión, 202 y lease vencido; al final restaura el esquema G3 exacto y revierte vacío.

Las pruebas corren como un job integrado de G3 en **MySQL 8 y MariaDB 10.11**, aparte de CI de PHP y runtime PrestaShop.

## Fronteras deliberadas

- El constructor exige el **DSN y usuario exactos de CI (`g3_ci`)** y una segunda bandera `G7_CI_LEASE_ENABLE=yes_synthetic_only`. Es deliberadamente imposible usarlo con el SQL de producción de BC. Los ALTER temporales existen **solo en el archivo de prueba** y no forman parte de instalación/upgrade.
- El módulo `pssalesforcebridge.php` **no incluye ni invoca** esta clase. Hooks y cron permanecen bloqueados. No hace llamadas HTTP ni accede a ningún dato personal real.
- **No se ha creado un worker de envío** ni tablas productivas; hay que revisar y autorizar una migración reversible para habilitar states sobre la outbox existente, junto con G5 (HTTP 202 inesperado en `/actions/test`), G6 (Consent Check Growth por destinatario y alcance) y política de conservación. No es todavía entrega extremo a extremo.
- PSCart no se almacena ni envía a Data Cloud: G4 sigue limitado a PSCustomer/PSOrder. La recuperación de carritos requiere Flow/campaña Growth autorizada y supresión de compra/baja al enviar.
- **BC producción permanece en 0.7.1 Test**, y la 0.8.0 probada en PrestaShop 9.1.5 UTC de laboratorio no queda actualizada automáticamente por este PR.

Este corte no añade otra arquitectura. La siguiente integración autorizada usa exactamente **la misma outbox + proyección nativa + GrowthStreamingIngestionPlan**, con el estado remoto conciliado; no reenviar indiscriminadamente después de timeout.
