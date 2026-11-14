# ADR candidate · G3-08 / G7 · Recuperación de carritos Growth con comprobación en origen

**14/11/2026 · ABH / Barcelona Confort · REVIEW ONLY — sin firma, sin selección de esquema y sin despliegue.**

## Problema de negocio y límites conocidos

Objetivo: recuperar carritos abandonados y evitar recordar compras ya completadas. El destino contratado en ABH es **Marketing Cloud Growth/Next + Data 360**, no Engagement. La instalación `pssalesforcebridge` 0.7.1 en Test ya registra capturas de carritos, usa un plazo de 60 minutos y posee `CartAbandonmentDetector`, `OrderSyncLedger::hasOrderForCart`, `CartContactStateReader` y `AbandonedCartJourneyProcessor`. **Este último usa Journey Builder de Engagement y nunca debe ser el sender ABH.**

Los YAML congelados v0.2/v0.3 definen PSCustomer/PSOrder, **no PSCart**. El catálogo Data 360 ABH leído completamente (527 relaciones de SalesOrder/Individual/Account) tampoco resuelve el vínculo comercial comprador–pedido. No hay consentimiento/supresión Growth certificado, Flow/campaña verificado ni permiso de exportación. G3-01…08 sigue pendiente y G4/G7 bloqueados.

## Alternativas para decisión posterior

| Opción | Implicación | Evidencia o autorización necesaria | Estado |
| --- | --- | --- | --- |
| **A. Nuevo objeto PSCart/evento Data 360** | Cambia esquema de ingestión; crea catálogo/retención y posible exposición de carrito | Nuevo expediente G3 con minimización, PK/FK, Event Time/Record Modified según categoría, Stream/DMO, finalidad y firma separada | **No seleccionado** |
| **B. Cálculo de abandono y conversión en PrestaShop; futuro transporte Growth** | Evita presuponer un tercer objeto PST; revalida estado actual en el origen antes de activar un Flow; no reutiliza sender Engagement | Selección de transporte/Flow **realmente soportado por el tenant ABH**, consentimiento por finalidad, supresiones, límites de frecuencia, carrera de compra vs envío y transacción de cierre | **Candidato de mínima exposición; no aprobado** |
| **C. Señal Growth directa sin PSCart en Data 360** | Requiere comprobar API/evento soportado por la edición y relación con campaña | Evidencia del tenant y permisos; test sintético, idempotencia, recuperación y supresión; sin inventar endpoint | **No verificado** |

La alternativa B es la que merece revisión primero: usa datos y comprobaciones que PrestaShop ya controla, sin asumir que son autorizaciones de marketing. **No se decide aquí la topología final ni se exime G3 de sus aprobaciones PSCustomer/PSOrder.** Para segmentación de compradores en Data 360 seguimos necesitando G3-06.

## Experimento local incluido (sin conectarlo al módulo)

- `tests/review/G3GrowthCartSourcePresendReview.php` recibe exclusivamente **14 aseveraciones sintéticas acotadas**, sin email, IDs de cliente/pedido, tokens ni RecoveryUrl.
- Solo considera candidato de **evaluación local** cuando coinciden instalación/tienda, carrito abandonado y snapshot actual, productos y ventana confirmados, **ambas comprobaciones de pedido negativas** y fiables, cliente actual válido y consistente, consentimiento específico Growth, supresión y frecuencia verificados. Todos los valores `unknown` bloquean.
- La existencia de una compra en PrestaShop o en el ledger bloquea; si un SELECT a pedidos falló y devolvió estado desconocido, **no debe convertirse en “no hay pedido”**. El contrato no presume que `hasOrderForCart()` pruebe ausencia en caso de error: un adaptador Growth real necesitaría una lectura triestado y un control cercano al dispatch para cerrar la carrera.
- El estado más favorable es `local_source_candidate_only`, **nunca send-ready**. Todos los flags de Flow, consentimiento real, ingesta, creación de enlace de recuperación y envío siguen `false`.
- `tests/g3-growth-cart-source-presend-contract.php` prueba **24 supresiones sintéticas**, mutaciones de forma/tipo y combinación de fallos; se ejecuta en CI con PHP 7.1 y 8.5. No hay llamadas HTTP, SQL ni datos reales.

## Condiciones de salida antes de cualquier envío

1. **G3:** aprobar identidad, privacidad, fechas, categoría, relaciones y esquema exacto; si se añadiera PSCart, reabrir formalmente la revisión de esquema.
2. **G4/G5 separados:** solo con permiso específico publicar/validar sintéticamente y, más tarde, probar ingesta autorizada.
3. **G6:** definir una fuente de consentimiento **actual y específica para Growth**, oposición, supresión y borrados. Newsletter/optin de PrestaShop no son permisos universales.
4. **G7:** probar el Flow/campaña real de ABH con destinatario sintético, doble comprobación de compra, ventana temporal, frecuencia y cierre de carrera; el experimento puramente sintético no certifica un envío.
5. **G8:** aislar proveedor e instalación, adaptar PrestaShop 1.7/8/9, planificar upgrade/rollback y autorización explícita de go-live.

**No se cambia `pssalesforcebridge/`, no se publica ningún YAML PST, no se crea Stream/DMO, no se hace SQL en BC, no se instala ZIP y no se habilitan mensajes.** La propuesta es reversible eliminando clase/test/CI y esta ADR candidata.

## Fuentes y precedentes

- `pssalesforcebridge/src/Queue/AbandonedCartJourneyProcessor.php` (solo Engagement histórico) y `pssalesforcebridge/src/Order/OrderSyncLedger.php`.
- `docs/G3_08_BUSINESS_GOAL_COVERAGE.md`, `docs/G3_CUSTOMER_ORDER_RELATION_TOPOLOGY_DECISION.md`, issue #45.
- Salesforce oficial: https://developer.salesforce.com/docs/marketing/marketing-cloud-growth/guide/mc-manage-flows.html — existen Flows ligados a campañas Marketing Cloud Next; **no certifica configuración ni activación para ABH**.
- Salesforce oficial: https://developer.salesforce.com/docs/data/data-cloud-int/guide/c360-a-ingestion-api-schema-req.html — restricciones de schema y cambios no reversibles tras publicarlo.


### Corte adicional: resultado triestado de pedido (14/11/2026, revisión sintética)

El código histórico `OrderSyncLedger::hasOrderForCart()` usa consultas `Db::getValue()` y entrega un booleano. Un `false` o valor escalar `0` no acredita por sí solo si **no existe** pedido o si falló la consulta. Antes de reutilizar la comprobación en Growth, hay que exigir una semántica de lectura diferenciada.

El intérprete puro `tests/review/G3GrowthOrderEvidenceInterpreter.php` distingue, para **dos lecturas independientes** (tabla nativa de pedidos y ledger del conector), `found`, `none` y `unknown`:

- Solo un resultado exitoso y explícitamente vacío (`[]`) equivale a ausencia local *candidata*. Un `false`, `null`, `0`, formato incorrecto, campos adicionales o cruce de `shop_id/cart_id` producen `unknown` y retención de la acción.
- Cualquier pedido encontrado en una de las lecturas supone carrito convertido, incluso si la segunda lectura falla. Una consulta devuelta como desconocida nunca permite inferir no compra.
- Cada fila sintética se verifica por `id_shop`, `id_cart` e `id_order` de forma estricta; no se retorna el ID de pedido ni se procesan nombres, direcciones o correos. Las entradas del ensayo usan exclusivamente identificadores inventados.
- La prueba `tests/g3-growth-order-evidence-contract.php` combina el intérprete con el guard previo `G3GrowthCartSourcePresendReview`, comprueba fallos y rechazo de estados ambiguos para ambas fuentes; la CI lo ejecuta en PHP 7.1/8.5.
- **No hay un lector SQL Growth implementado, ni estas pruebas consultan PrestaShop.** Un adaptador futuro requerirá seleccionar `id_shop,id_cart,id_order` con una lectura que distinga resultado exitoso vacío de error, acotar el alcance por instalación, revalidar cerca del despacho, cerrar la carrera compra/envío y someterse a pruebas en las tres versiones soportadas.
- Incluso si ambas lecturas sintéticas están vacías, la salida es `no_order_candidate_only`; `absence_certified_in_live_prestashop`, autorización Growth, envío e ingesta siguen `false`. Esto **no** cierra G3 ni autoriza G7.

**Estado de la ADR:** candidata sin firma; la alternativa B sigue sin seleccionar. Sin upgrade, cambios de BO, publicación del esquema PST, Data Stream ni envío.
