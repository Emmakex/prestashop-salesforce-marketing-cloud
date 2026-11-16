# G7 — Última relectura local de compra y baja, sin despachos

**16/11/2026.** Alcance exclusivo PrestaShop 1.7/8/9 → Salesforce Marketing Cloud Growth + Data Cloud. Barcelona Confort continúa con **0.7.1 Test** instalada. No cambiar módulos, tablas, campañas, streams, hooks ni permisos.

## Qué se reutiliza y qué se incorpora

Ya existen:
- `GrowthCartConversionHoldGate` con **7 SELECT sin caché** (2 carrito/productos, 1 cliente y 4 de pedidos/ledger en dos rondas).
- `GrowthLegacyCartFreshNativeReview` para reconstruir y revisar **en memoria** el hash 0.7.1, `sha1(json_encode(payload))` de 40 caracteres, desde objetos nativos de PrestaShop.
- `GrowthLocalCustomerHoldReader` y `LocalOrderPresenceReader` que interpretan los datos sin exportar email, IDs, filas ni SQL.
- Outbox `BlockedOutboxCoordinator` y `PrestaShopDbBlockedOutboxStore` estrictamente bloqueados/sandbox G3: **no** utilizarlos para carritos ni eliminar sus salvaguardas, pues PSCart no está autorizado.

Nuevo `GrowthCartLastLocalExclusionReview::inspect()`: vuelve a ejecutar **exactamente 3 SELECT adicionales**, con la misma semántica y sin caché, al final del análisis local:

1. `GrowthLocalCustomerHoldReader`: comprueba cliente activo, no eliminado, no invitado y que no consten bajas locales; un opt-in positivo local nunca equivale a permiso Growth.
2. `LocalOrderPresenceReader`: verifica de nuevo pedido nativo del carrito y el ledger del conector, diferenciando `found`, `none` y `unknown`, donde un error, un ledger ausente o una respuesta malformada **no equivale a ausencia**.
3. Consolida todos los motivos observados, con prioridad del pedido confirmado, después incertidumbre de pedido y después baja/estado de cliente. No devuelve filas ni datos personales.

El nuevo método explícito `GrowthCartConversionHoldGate::inspectPrestaShopWithFreshLegacyCartFinalReview()` llama primero al revisor nativo existente (7 SELECT + reconstrucción SHA-1) y **después** a los tres SELECT de exclusión. En total, **10 SELECT locales sin caché** más las lecturas internas de objetos nativos `Cart/Customer/Currency/getProducts/getOrderTotal` (estas pueden generar consultas adicionales internas de PrestaShop; no se describen como incluidas en las 10).

## Pruebas de carrera sintéticas

En `tests/g7-fresh-native-legacy-snapshot-contract.php` se comprueba, sobre fixtures aislados:

- Las diez SELECT locales ocurren con `executeS($sql, true, false)`, y las **últimas tres** consultan cliente → pedido nativo → ledger, después de la reconstrucción del hash.
- La coincidencia de snapshot sin negativos continúa como `last_local_no_negative_observed_review_only` y **nunca** permite enviar.
- **Pedido que aparece en la tercera lectura de pedidos**, después de que las primeras dos comprobaron ausencia: `purchase_found_in_last_local_review`, se mantiene `purchase_found_locally=true`.
- **Baja newsletter que aparece en la segunda lectura del cliente**, después de la reconstrucción: `last_local_customer_negative`, con motivo de bloqueo.
- **Ledger que falla en su última lectura**: `last_local_purchase_source_unverified` y `purchase_state_unknown=true`.
- Señales con campo no permitido se rechazan **antes de leer SQL**, y no se devuelven ID, digest, correo, artículo o timestamp.

## Límites de seguridad no resueltos

**No hemos implementado una barrera atómica de despacho.** Las diez SELECT no son una transacción que bloquee todos los cambios del carrito/compra/baja, ni puede la transacción MySQL hacer atómica una llamada de entrega a Salesforce. Una compra/baja puede ocurrir después de la última lectura; los resultados siguen `hold_for_review`, `race_with_future_dispatch_closed=false` y `marketing_sends_authorized=false` en TODOS los casos.

Tampoco se han certificado el hash histórico guardado de BC, su procedencia, la zona horaria de escritura, la última actividad efectiva, la frecuencia Growth ni el consentimiento final de la suscripción Marketing/email en Salesforce. G6 sigue bloqueado en consentimiento efectivo; G5 `POST /actions/test` sigue en cuarentena por HTTP 202. La configuración BC 0.7.1 Test no se ha modificado.

**Siguiente corte útil** (sin construir una cola paralela): definir y probar la reclamación/versionado de una tarea *solo dentro del laboratorio G3*, con cancelación local ante compra/baja y sin permitir nunca un outbound. Al disponer de consentimiento Growth y contrato PSCart aprobados, someter el futuro despacho externo a una revisión independiente. No inferir `Go Live` de estos tests.
