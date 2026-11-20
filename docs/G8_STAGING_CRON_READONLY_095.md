# G8 · Cron de observación de la outbox — 0.9.5 (20/11/2026)

## Resultado concreto de esta entrega

El módulo `pssalesforcebridge` incorpora una operación `inspect` en su **cron ya protegido**, que llama a `GrowthG8CronReadOnly` y al lector G7 existente de las **mismas tres tablas G3**. Devuelve únicamente recuentos de candidatos (máximo 10), si hay más registros y estados de seguridad. **Nunca** devuelve nombres, correos, IDs, claves de origen, namespace, cursor, hashes, URLs de OAuth, tokens ni excepciones SQL.

- El cron sin parámetro mantiene el estado histórico `blocked_test`. Un parámetro desconocido devuelve error seguro.
- `inspect` es **solo lectura y exclusivamente staging**: modo Test, captura OFF, namespace con `stage`/`staging`, identidad exacta, origen por tienda configurado y tienda actual explícita en multitienda.
- El controlador compara el **token ya existente** mediante `hash_equals` antes de procesar la operación; ningún nuevo secreto.
- El lector `GrowthG7CurrentEventBatch` usa el **ledger G3** y el guard de columnas G7 extendidas; rechaza ausencia/esquema parcial en vez de inventar datos.
- En Barcelona Confort (`BCABH`, namespace producción), `inspect` responde `blocked_non_staging` **sin leer la cola de eventos**, y no activa envío alguno.
- La actualización `0.9.4→0.9.5` conserva credenciales cifradas, merchant/namespace, origen Salesforce, receipts manuales, tablas y datos históricos. Fuerza Test y captura OFF.
- **No se crea cola, no se llama a Salesforce, no se programa un cron ni se habilita una campaña.** El envío de clientes 6031 y pedido 612 ya verificados en DLO/DMO nunca debe repetirse.

## Próximo corte funcional, sin expandir el producto

1. Adaptar las comprobaciones exactas G3 existentes de staging al motor SQL real de BC tras certificar `CHECK`, sin reconstruir las tablas ni abrir reenvíos.
2. Vincular la reserva CAS + la doble lectura nativa del `GrowthAutomaticOneShotCoordinator` a esa misma outbox/ledger y una comprobación GET por `source_key` de presencia en DLO; revalidar estado por contacto.
3. Probar un único evento sintético nuevo de staging con cron protegido, retorno 202 y verificación DLO; demostrar que la repetición no envía y que un timeout queda en `reconcile`.
4. Solo con CI 17/17, runtime 1.7/8/9 y rollback acreditados, activar para BC mediante una autorización específica de envío automático acotado. Campañas seguirán separadas y bajo Consent Check nativo Growth existente.

**Estado actual:** núcleo del automatismo (PR #162) probado; cron preflight ligado en este corte; **sender automático todavía no enlazado**. No instalar repetidamente ni afirmar go-live por una observación de eventos.

## Seguridad para operador

No publicar URLs completas del cron con token en tickets ni conversaciones. En staging, usar el endpoint con HTTPS y token de cron existente obtenido en el Back Office; operaciones aceptadas: `status` o `inspect`. No ejecutar `inspect` en BC para simular envíos. Toda preparación o activación de sincronización sigue requiriendo pruebas específicas.

