# G7/G3 — revisión versionada de una reclamación bloqueada (solo CI)

**16/11/2026 · Sin envíos.** En `main` el último recheck de carrito mantiene 10 SELECT locales, pero no constituye una barrera de envío a Salesforce. Este corte no modifica su código, PrestaShop ni Barcelona Confort.

## Qué se ha probado

`tests/integration/G3GrowthBlockedVersionClaimReview.php` usa **solo las dos tablas G3 existentes**, `g3_growth_outbox_v1` y `g3_growth_entity_ledger_v1`, y reutiliza el mismo advisory lock por namespace/tienda/entidad usado por `G3GrowthOutboxSandboxStore`.

1. Antes de observar un supuesto candidato, abre una transacción y bloquea el ledger y el outbox con `SELECT ... FOR UPDATE`, comprobando `latest_accepted_sequence` frente a la versión esperada.
2. Si ha aparecido una versión posterior, el intento queda en `stale_version_blocked` y **no ejecuta la comprobación local**.
3. Si la versión coincide, acepta una observación **sintética** del laboratorio: `purchase_found`, `optout`, `clear` o `unknown`. Compra/baja producen una señal de cancelación observada; error de fuente o valor inesperado se consideran incertidumbre.
4. Independientemente del resultado: `hold_for_review`, `claim_authorized=false`, `claim_persisted=false`, `local_cancellation_persisted=false`, `outbound_worker_authorized=false` y `marketing_sends_authorized=false`.

**No se guarda una reclamación, lease, token o cancelación persistente.** El esquema actual solo permite `status='blocked'` y carece de columnas para ello; este contrato verifica cómo rechazar una reclamación y detectar versiones obsoletas sin añadir tablas ni relajar la restricción. La prueba con entrada `cart` falla antes de consultar la base.

## Cobertura y alcance real

`tests/integration/g7_g3_blocked_version_claim_review.php` se ejecuta en el job G3 existente bajo **MySQL 8 y MariaDB 10.11**. Utiliza clientes sintéticos y comprueba coincidencia de versión sin permiso, sustitución por versión nueva, opt-out, compra, fallos de lectura, fuente malformada, cruce de tienda, rechazo de PSCart, conservación de `blocked` y ausencia de PII en el resultado. No lee datos reales de clientes ni usa Salesforce.

Este test **no implementa un outbox de carritos**. La revisión final de compra/baja de G7 sigue fuera de hooks, cron y workers. No hay comprobación transaccional concurrente con el checkout real, ni lease/claim persistente, ni barrera capaz de hacer atómica una llamada remota.

## Siguiente decisión técnica, sin reabrir G3

Antes de implementar un worker real harían falta un contrato PSCart y su aprobación, estado de claim/cancelación persistente en el esquema autorizado, verificación efectiva de consentimiento CSC Growth y un fence de despacho que contemple carreras externas, reintentos y deduplicación. El problema del HTTP 202 de G5 sigue bloqueando los POST y Barcelona Confort mantiene **0.7.1 Test**.

No inferir permiso de entrega de estas pruebas ni instalar `0.8.0` en BC.
