# G7 — Evidencia nativa de carrito y bloqueos por compra (sin envíos)

**16/11/2026 — alcance acotado.** El objetivo comercial sigue siendo recuperar carritos abandonados mediante Marketing Cloud Growth + Data Cloud, no activar de momento campañas. El módulo en Barcelona Confort permanece en **0.7.1, Test**. No se modifica el esquema PST (solo PSCustomer y PSOrder), no se crea PSCart y G5 conserva la prohibición de nuevas llamadas POST al test tras HTTP 202.

## Qué falta en los controles anteriores

Ya existían `LocalOrderPresenceReader` (dos SELECT por ronda), `GrowthCartConversionHoldGate` (dos rondas, cuatro SELECT por carrito) y `GrowthLocalCustomerHoldReader` (un SELECT actual sin caché que bloquea bajas, invitados, cuentas eliminadas o inactivas). **No se duplican.**

El vacío real de G7 era que las banderas `cart_has_products`, `cart_status=abandoned`, `snapshot_current` y `abandonment_window=elapsed` podían proceder únicamente de afirmaciones externas, no de una lectura real y mínima del carrito nativo.

## Implementación nueva

`GrowthLocalCartSnapshotReader` añade solo dos SELECT acotados por tienda y carrito:

- Carrito: campos `id_cart`, `id_shop`, `id_customer`, `date_upd`; verifica que pertenece a la tienda y al cliente esperados, con `LIMIT 2` para detectar duplicados/inconsistencias.
- Productos del carrito: comprueba exclusivamente existencia de una fila con `quantity > 0`, mismo `id_shop` y `id_cart`, `LIMIT 1`. No consulta catálogo, precio, dirección, email ni cantidades agregadas.

Una consulta ausente, malformada, de otro cliente o tienda, con fecha local inválida, sin producto positivo o con error SQL devuelve siempre **hold_for_review**. Los resultados solo contienen estados y motivos acotados, nunca IDs, fechas, datos de filas o SQL.

`GrowthCartConversionHoldGate::inspectWithNativeCart` reutiliza **el lector anterior de cliente** y las **dos rondas anteriores de pedidos**, combinándolos con el nuevo lector. En el caso sano esperado son siete SELECT: 2 carrito/productos + 1 cliente + 4 de compra. Todos usan `Db::executeS($sql, true, false)` en PrestaShop 1.7/8/9 para eliminar falsos negativos por caché.

Aunque un caller presente un carrito como `abandoned`, ventana `elapsed` y snapshot `current`, la nueva composición anula expresamente esas afirmaciones no certificadas. Que un carrito tenga productos hoy **no demuestra que esté abandonado**, que tenga 60 minutos de inactividad ni que su snapshot histórico coincida con el actual.

## Limitaciones que NO se ocultan

- `date_upd` se valida **solo por formato** de fecha SQL local. No se interpreta como UTC ni se calcula una ventana fiable hasta cerrar el contrato de zona horaria/históricos y actividad.
- Una compra o baja puede ocurrir **después de los siete SELECT**. Falta una barrera atómica junto al despacho futuro.
- El consentimiento específico Marketing Cloud Growth, la supresión central y el límite de frecuencia requieren fuentes verificadas. Los booleanos PrestaShop `newsletter/optin` nunca autorizan.
- No existe todavía PSCart Data Stream/Flow de Growth aprobado ni conexión a una campaña. No se crean enlaces de recuperación ni se entrega ningún email.
- Aunque el estado nativo sea `cart_present_nonempty_review_only`, **todas** las autorizaciones de envío, ingesta y recuperación siguen false. No se conecta la clase nueva a los hooks, cron o workers.

## Evidencia reproducible

`php tests/g7-native-cart-recovery-hold-contract.php`: prueba sintética carrito/no carrito, cruces de tienda/cliente, fecha inválida, sin productos, cantidad errónea, columnas privadas inesperadas, baja newsletter, conversión registrada **en la segunda comprobación**, rechazo de señales no previstas antes de SELECT, y comprobación de **7 SELECT sin caché** en el adaptador PrestaShop. CI PHP 7.1/8.5 y runtime PS 1.7/8/9.

Esquema nativo de referencia:
- https://github.com/PrestaShop/PrestaShop/blob/develop/install-dev/data/db_structure.sql
- https://devdocs.prestashop-project.org/9/development/database/
- https://devdocs.prestashop-project.org/8/webservice/resources/carts/

## Avance posterior: verificación offline de los 60 minutos (16/11/2026)

Se añade el [contrato 60 minutos/DST](G7_CART_60M_DST_WINDOW_REVIEW.md): cálculo de 3600 segundos con el normalizador IANA/UTC ya existente y adaptación opcional al mismo lector nativo, sin nuevas consultas SQL. Rechaza DST ambiguo o inexistente y separa la procedencia real de escritura de una hipótesis de test. Todas las comprobaciones continúan bloqueadas para marketing, sin equiparar `date_upd` a actividad comercial certificada.

## Siguiente corte G7 (separado, sin activar envíos)

Certificar la fuente de actividad/frescura del carrito, la ventana efectiva de 60 minutos y el contrato temporal (origen, DB/server timezone, DST), además de un diseño explícito de exclusión atómica de compra y baja antes del despacho. Con G6 pendiente de consentimientos reales, ninguna prueba G7 puede declarar campañas listas para producción.
