# G7 — Ventana de 60 minutos y cambios DST (sin envíos)

**Corte de 16/11/2026.** PrestaShop Barcelona Confort conserva el módulo instalado **0.7.1 Test**, con `CART_ABANDON_MINUTES=60`. Esta configuración expresa la política de negocio, pero **no certifica** que los valores `cart.date_upd` tengan una semántica temporal UTC o local homogénea.

## Reutilización, sin duplicación

Ya existía el conversor `SourceTimestampNormalizer::toUtc()` (en Data Cloud) y el resolvedor por tienda `ShopTimezoneResolver::readFromPrestaShop()`. El primero valida una fecha SQL `YYYY-MM-DD HH:MM:SS`, acepta zonas IANA explícitas y rechaza fechas imposibles, horas inexistentes en el salto de marzo y horas repetidas en octubre. El segundo lee `PS_TIMEZONE` para el `shop_id` real, sin asumir Europe/Madrid para todas las instalaciones. Este corte **reutiliza ambos**.

El nuevo `GrowthCartInactivityWindowReview::inspect(localDateUpd, shopTimezone, nowUtc, writePathEvidence)` compara instantes UTC con un umbral **exacto de 3600 segundos**. El instante de referencia es un valor UTC estricto proporcionado por el caller; no usa el reloj local del servidor y no obtiene la hora de la base de datos por inferencia.

### Clasificación segura

| Evidencia / situación | Estado |
|---|---|
| Ruta de escritura no demostrada | `write_path_provenance_unknown` |
| Menos de 3600 segundos | `under_60_minutes` |
| 3600 segundos o más bajo una hipótesis explícita de escritura local | `elapsed_60m_candidate_under_asserted_provenance` |
| Fecha de origen posterior al reloj de referencia | `source_clock_in_future` |
| Hora de octubre repetida | `dst_overlap_ambiguous` |
| Hora de marzo inexistente | `dst_gap_nonexistent` |
| Fecha/zona inválida o error de conversión | bloqueado con motivo saneado |

**Una ventana hipotéticamente cumplida no es una autorización de marketing.** El argumento `shop_local_write_path_asserted` es una hipótesis inyectada por el caller, no un certificado del origen en BC. Si se usa `unverified`, ni siquiera interpreta `date_upd` como hora local. La respuesta **nunca incluye** fechas originales, UTC calculado, carrito/cliente, ID de tienda, email ni datos privados.

## Integración con la lectura ya construida

`GrowthLocalCartSnapshotReader` acepta opcionalmente una evaluación de tiempo **solo después** de confirmar que el carrito pertenece a la tienda/cliente y que tiene productos positivos. Si la evaluación falla, se anota `review_failed` y no se propaga una excepción o cuerpo sensible. Se conserva el mismo número de lecturas locales.

`GrowthCartConversionHoldGate::inspectWithNativeCartWindow` reutiliza el flujo actual de **7 SELECT locales, sin caché**: 2 de carrito/productos, 1 de cliente y 4 de pedido en dos rondas (nativos y ledger). Invalida las afirmaciones externas `abandoned`, `snapshot_current`, `abandonment_window=elapsed` y consentimiento Growth. Añade únicamente `local_cart_inactivity_window_state` al resumen de seguridad.

`inspectPrestaShopWithNativeCartWindow` es un adaptador de solo lectura para PrestaShop 1.7/8/9, no conectado a hooks/cron/worker. Recupera la zona por tienda mediante `ShopTimezoneResolver` y ejecuta sus SELECT con `Db::executeS($sql, true, false)`. No crea registros, URLs de recuperación ni campañas.

## Bloqueos que permanecen

- **Procedencia temporal:** un `PS_TIMEZONE=Europe/Madrid` actual no acredita el timezone real de cada escritura, importación, override o cron histórico. Tampoco existe todavía certificación del reloj UTC/NTP de los futuros eventos de carrito. Por ello `source_write_path_certified_for_bc=false` y `as_of_utc_clock_certified_for_bc=false` siempre.
- **Actividad real y snapshot:** `date_upd` puede no representar todas las interacciones de actividad comercial. Falta contrastar snapshot/hash y determinar si el carrito fue editado después del evento.
- **Carrera de compra o baja:** las siete lecturas no son una transacción que cierre el intervalo entre verificación y un envío futuro.
- **Consentimiento Growth:** no existe todavía lectura CSC por destinatario y finalidad certificada en ABH; G6 conserva bloqueo de envío.
- **Transporte:** el conector PST solo tiene `PSCustomer` y `PSOrder`, no `PSCart`. No crear Data Stream, Flow, ingesta ni campañas.
- **G5:** `/actions/test` devolvió HTTP 202 no explicado; prohibido relanzar POST.

**Pruebas CI:** `php tests/g7-cart-inactivity-60m-contract.php`, con 3599/3600/3601 segundos, CET/CEST, horas DST en marzo/octubre, futuro, fecha imposible, zona inválida, fuente no verificada, composición G7 y 7 SELECT frescos con `PS_TIMEZONE` por tienda.

Referencias internas: `docs/ABH_TIMEZONE_CONFIRMATION_AND_DB_PREFLIGHT.md`, `docs/BC_G3_TIMESTAMP_PROVENANCE_AUDIT.md`, `docs/G7_NATIVE_CART_SNAPSHOT_REVIEW.md`.

**Corte siguiente realizado (solo contrato):** [comparación de snapshot y diseño de exclusión final](G7_SNAPSHOT_EQUIVALENCE_FINAL_EXCLUSION.md), sin lector de digest real ni autorización de envío.

**Próximo corte G7:** especificar cómo observar actividad y cambio de snapshot con instante UTC fiable para *nuevos* eventos, y una barrera de supresión final (compra, baja, límite de frecuencia) que no dependa de lecturas realizadas antes del futuro despacho. No reabrir G3/G4 ni exigir SQL al propietario.
