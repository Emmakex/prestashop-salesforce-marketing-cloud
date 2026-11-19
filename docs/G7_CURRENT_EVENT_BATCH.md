# G7 — lectura paginada de todos los eventos actuales de la outbox G3

**19/11/2026.** Cierre de un hueco operativo: el preflight anterior se limitaba al último evento global por tipo; no permitía recorrer clientes y pedidos distintos con independencia.

## Qué se ha implementado

`GrowthG7CurrentEventBatch::scan` explora por **keyset** `accepted_sequence > cursor`, en páginas de 1–50 filas, la **misma** outbox G3 instalada y su ledger de entidad, sin segunda cola. La consulta hace un `INNER JOIN` limitado a namespace y tienda. Solo devuelve una fila cuando:

- La versión de la fila es exactamente `latest_accepted_sequence` del ledger para ese cliente o pedido.
- G3 original y ledger permanecen `blocked`; entrega permanece `blocked`, sin intentos, lease ni HTTP anteriores.
- El tipo y evento de origen son conocidos: `CUSTOMER_CREATED`, `CUSTOMER_UPDATED` o `ORDER_CREATED`.
- El módulo se evalúa bajo Test, namespace de staging, captura detenida, binding y esquema G7 ampliado íntegros (guardias compartidas con el lease nativo).

La selección interna contiene solo referencias numéricas de tienda, tipo, entidad y secuencia necesarias para que un **futuro** orquestador revalide la fuente; no incluye emails, nombres, carritos ni payloads. Su método `summarize` devuelve exclusivamente un recuento y un indicador de paginación, sin IDs ni cursor.

`GrowthG7NativePreflight::inspectSelected` añade una entrada de revisión para **un evento concreto**, sin sustituir `inspectLatest` usado anteriormente. Reutiliza el mismo doble control de fuente nativa PST/ledger y bloquea la revisión de una secuencia obsoleta aunque el cliente siga activo. Así puede revisarse cada miembro del lote sin confundir una actualización antigua con su revisión vigente.

## Garantías y límites

**La salida es solo una colección de candidatos para revisión.** No existe cambio a `ready`, `claim`, escritura SQL, envío HTTP ni autorización comercial. Si el ledger cambia después de seleccionar un candidato, el preflight exacto lo detecta y mantiene HOLD; un cambio aún posterior sigue exigiendo fence en el momento del envío.

No hay nueva tabla, versión del módulo, ZIP ni tareas automáticas. Los eventos existentes del staging 0.8.8 `extended_blocked_no_sends` permanecen sin cambios. Barcelona Confort producción sigue 0.7.1 Test.

## Certificación

`tests/integration/g7_staging_capture_prestashop_db.php` prueba sobre cinco eventos sintéticos en `g3_ci` y la outbox extendida real del sandbox: cuatro revisiones vigentes de varias entidades, descarte del CUSTOMER_CREATED anterior cuando hay CUSTOMER_UPDATED nuevo, páginas 2+2 y fin, no duplicación, entrada exacta de PSCustomer/PSOrder, rechazo de secuencia obsoleta y tienda ajena, exclusión de una fila que deje de estar bloqueada, límite de tamaño y revocación del gate al armar captura. Sin tráfico externo, escrituras a clientes ni filtración de identificadores en resumen.

El contrato `tests/g7-current-event-batch-contract.php` verifica bajo PHP 7.1–8.5 los límites, condiciones de JOIN/ledger, separación del módulo y la ausencia de sender.

## Siguiente cierre real

Consumir los lotes desde un único orquestador en staging con lectura CSC *real* y consentimientos de finalidad vigentes, fence atómico, decisión aprobada sobre transición a `ready`, POST de Data Cloud con clasificación de resultados y conciliación DLO/DMO. Recuperación de carritos 60 min depende del mismo control de compra/baja. G8 valida UAT, reversibilidad y puesta en marcha autorizada.