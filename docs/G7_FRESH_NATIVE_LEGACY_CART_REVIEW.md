# G7 — Revisión fresca del carrito nativo con hash 0.7.1 (sin envíos)

**Fecha: 16/11/2026.** Alcance único: PrestaShop 1.7, 8 y 9 → Salesforce Marketing Cloud Growth + Data Cloud. BC instalado **0.7.1 Test**, sin cambio. No PSCart, no llamadas a Salesforce, no hooks, no cron, no emails y no migraciones.

## Código original verificado y reutilizado

El [commit histórico 0.7.1 `527fe127`](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/commit/527fe127a681cf3869611e3cbceac36421a28aff) demuestra que `CartNormalizer::fromCart()` ensambla **19 claves** en orden más líneas (9 claves), y que `CartCaptureService::capture()` añade **5 claves** de elegibilidad/estado/fecha, ejecuta `sha1(json_encode($payload))` y guarda el resultado en `sfmc_cart.snapshot_hash CHAR(40)`. No se reutiliza el antiguo transporte, una API externa ni otro algoritmo. [Contrato detallado](G7_LEGACY_071_SNAPSHOT_SHA1_COMPATIBILITY.md).

## Implementación aislada

Nuevo `GrowthLegacyCartFreshNativeReview::inspectPrestaShop()`, llamado únicamente por código explícito de revisión y **no por la instalación**:

1. Instancia los objetos nativos `Cart`, `Customer` y `Currency` de PrestaShop, comprueba shop/cart/customer y lee `getProducts()` y `getOrderTotal(true, Cart::BOTH)`. Rechaza datos cruzados, ausentes, mal formados o fuera del límite de 200 productos.
2. Reconstruye **en memoria** el mismo orden de campos, prioridad de cantidades y precios, `line_key`, totales y contadores que la captura 0.7.1. Replica las causas técnicas de elegibilidad `marketing_optin` del antiguo `CartEligibilityPolicy` **solo para reproducir el hash**, no para demostrar permiso de marketing Growth.
3. Interpreta la hora local `date_upd` usando el normalizador DST/IANA ya existente. Recrea `abandon_after` con 3.600 segundos. Eso solo puede coincidir con la huella original si el origen de la escritura histórica y la zona horaria coinciden; horas locales repetidas, omitidas, fechas inválidas y lecturas dudosas retornan un bloqueo.
4. Pasa el array completo **únicamente en memoria** a `GrowthLegacyCartSnapshotDigestReview::inspect()`, que verifica la igualdad con `sha1(json_encode())` bajo el mismo orden. Nunca se devuelven ni escriben identificadores, email, nombres, lista de productos, precios, fechas, JSON, hash o SQL.
5. Retorna únicamente `native_071_recomputed_digest_match_review_only`, `native_071_recomputed_digest_changed_hold` o estado de fuente no verificable. **Una coincidencia tampoco acredita el hash capturado como auténtico**, porque el valor histórico está inyectado y la fuente SQL histórica no está conectada.

## Composición con G7 existente

`GrowthCartConversionHoldGate::inspectPrestaShopWithFreshLegacyCartReview()` ejecuta primero los **7 SELECT sin caché** que ya existían (carrito/productos, cliente, dos rondas nativas y ledger de pedidos). Solo si no observa compra ni error de compra en esas lecturas construye una huella fresca, bajo una hipótesis no certificada de ausencia de pedidos. Si detecta compra, retorna bloqueo sin comparar el hash.

El adaptador nativo lee el `PS_TIMEZONE` de la tienda específica usando `ShopTimezoneResolver`, igual que la revisión temporal anterior. Aunque la huella calculada coincida, la edad de 60 minutos no queda certificada: el algoritmo SHA-1 incluye `last_activity` y `abandon_after`, pero su coincidencia no prueba actividad real ni zona de escritura histórica. Una compra o baja todavía puede producirse después de los SELECT y antes de un futuro envío.

**Ninguna operación tiene un sender ni altera `sfmc_cart` ni consulta esa tabla.** El código sigue aislado de hooks/cron/workers. Es una comparación de compatibilidad local para preparar un adaptador autorizado, no un paso de despacho.

## Pruebas

`tests/g7-fresh-native-legacy-snapshot-contract.php` (CI PHP 7.1/8.5) comprueba, con clases PrestaShop simuladas, que el objeto nativo actual reproduce el **hash SHA-1/40** del JSON completo original (`651957cd3ca8e447ffca0b532ffe9cd1d6573cf2`) con el campo de causa técnica real `newsletter_subscribed`. Cambios de artículos, nombre, precio, total, fecha, newsletter, optin, email, cruce de tienda/cliente, DST, pedidos sobrevenidos o baja local son bloqueados. El adaptador compuesto conserva **7 SELECT sin caché** y todos los indicadores de autorización a false. Certificación CI + matriz PrestaShop 1.7/8/9.

## Bloqueos pendientes para G7 productivo

- **Origen histórico del digest:** todavía no se ha consultado de forma autorizada `sfmc_cart` en BC; el hash aportado al comparador es inyectado y no prueba autenticidad. No se accede a SQL de producción ni se modifica BC.
- **Revisión transaccional final:** los objetos nativos y los siete SELECT se leen en instantes distintos, sin exclusión atómica de una compra o baja simultánea con entrega externa. No se ha implementado el fence de despacho.
- **Fuente temporal:** `date_upd` y el algoritmo histórico dependían del timezone por proceso; un `PS_TIMEZONE` actual no prueba el timezone del escritor original ni una última interacción comercial.
- **Consentimiento:** `newsletter` y `optin` **no equivalen** al CSC vigente de Marketing Cloud Growth para canal y finalidad. El lector real CSC y el consentimiento de emisión siguen pendientes por G6.
- **Transporte:** G5 `/actions/test` permanece bloqueado por HTTP 202. Ningún Flow, envío, Data Stream PSCart, recovery URL ni ingesta.

**Siguiente trabajo:** revisar la validez de datos históricos y el *fence* justo antes del despacho mediante pruebas de una operación local atómica y de la supresión del canal Growth. Cualquier transición a envíos necesita aprobación explícita, contrato de datos y el servicio oficial de consentimiento de Salesforce.
