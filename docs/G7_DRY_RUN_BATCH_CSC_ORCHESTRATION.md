# G7 — orquestador único de revisión por lotes (sin envíos)

**19/11/2026.** El PR #149 habilitó la selección paginada de todas las revisiones vigentes de la outbox G3, pero la comprobación CSC anterior solo examinaba el último evento global por tipo. Este incremento une ambas piezas para revisar **cada evento concreto** de una tienda.

## Flujo implementado

`GrowthG7DryRunBatchOrchestrator::reviewWindow` recorre, de forma únicamente local y bajo los gates Test/staging existentes, hasta **5 páginas de 1–50 eventos** cada una con `GrowthG7CurrentEventBatch::scan`. No abre otras tablas o colas.

Cada referencia interna contiene tienda, tipo, entidad y `accepted_sequence`, pero **no sale en el resumen público**. Se pasa a `GrowthG7CscPreDispatchReview::inspectSelected`, nueva entrada que conserva intacta `inspectLatest` y exige tanto en la doble relectura nativa como en la consulta CSC que la identidad del pedido o cliente y la secuencia de la outbox sean exactamente las seleccionadas. La lectura CSC deriva al cliente del mismo evento o del pedido real, nunca de un identificador externo suministrado por el operador.

Una lectura obsoleta, cambio del ledger, baja local, ausencia, opt-out, un CSC inválido o una excepción implican `hold_for_review`. Incluso un opt-in observado y coherente se clasifica como **candidato pendiente de fence**, nunca permiso de ingesta o de envío.

`summarize` devuelve únicamente cuatro recuentos de revisión: candidato CSC no autorizado, bloqueo de origen/local, CSC denegado/desconocido, y otros bloqueos; incluye total de eventos y existencia de más páginas. **Nunca IDs, cursor, source keys, correo, cuerpo PST, tokens o errores SQL**. El cursor interno sirve para reanudar con límite de páginas en una futura invocación controlada; no está destinado a logs.

Si una página, el schema o el gate de staging falla, la ejecución finaliza en `batch_review_failed_closed`, sin afirmar éxito parcial ni divulgar el cursor. No se cambia ninguna fila a `ready` o `claimed`.

## Pruebas

El mismo test de `g3_ci` con MySQL 8, MariaDB 10.11/11.8 y cinco eventos sintéticos comprueba: revisión completa de las cuatro versiones vigentes (no la revisión antigua), páginas 2+2, reanudación por cursor interno, opt-out CSC aislado al cliente #904, baja de cliente #901 que afecta a sus dos pedidos, revocación por activar captura, límites y ausencia de mutaciones. El contrato PHP 7.1–8.5 prohíbe conexión a cron, BO, HTTP y escrituras SQL.

## Estado y cierre

**Código de orquestación de revisión, NO despachador:** no hay lector CSC Salesforce operativo certificado para envíos comerciales, aprobación de transición a ready, fence final delante del POST, ni conciliación DLO/DMO. Todo sigue en Test y bloqueado, sin nuevo ZIP.

Staging PrestaShop 9.1.5: módulo 0.8.8 Test, ampliación G7 validada con `extended_blocked_no_sends`. Barcelona Confort producción: 0.7.1 Test intacta. G5 sintético y aceptación administrativa G6 no requieren repetirse.

**Siguiente corte de producto:** transporte de ingesta Data Cloud con una autorización explícita separada del consentimiento comercial, recepción 202 como estado incierto y conciliación con DLO/DMO; finalizar exclusión por compra/baja de carritos 60 min y G8 UAT. Nunca iniciar envíos a contactos sin consentimiento vigente y autorización final.