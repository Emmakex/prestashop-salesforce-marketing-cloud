# G7 — Adaptador nativo de lease de PrestaShop, sin envío (19/11/2026)

**Estado ya comprobado:** staging PrestaShop 9.1.5 mantiene el módulo 0.8.8 y el usuario mostró `extended_blocked_no_sends`. La outbox G3 única ya tiene las cinco columnas de control de entrega. Los eventos existentes permanecen `blocked`. No crear nuevas altas, pedidos, tablas ni colas.

## Funcionalidad desarrollada

`GrowthPrestaShopDeliveryLease` opera mediante la API `Db` de PrestaShop sobre la MISMA tabla `growth_outbox_v1` y `growth_entity_ledger_v1`, con el prefijo real de la instalación, sin SQL a una cola alternativa:

- Comprueba en cada operación `PS_SFMC_MODE=test`, captura local `off`, merchant/namespace de `Configuration` exactos, etiqueta `stage/staging` en el namespace, motor y esquema completo `extended_g7_verified` mediante el inventario ya certificado.
- `claim` utiliza `UPDATE ... JOIN ledger` atómico, exigiendo `delivery_state=ready`, revisión vigente, G3/ledger `blocked` y menos de tres intentos. Token de lease almacenado solo como SHA-256, TTL entre 30 y 900 segundos. El resultado se conoce por `SELECT ROW_COUNT()` inmediato sobre la misma conexión, sin consulta intermedia.
- `cancel` bloquea localmente un evento; `markAcceptedUnconfirmed` para HTTP 202 y `markUnknownUnconfirmed` para respuesta perdida lo ponen en `reconcile` (no entregado). Un error conocido queda `held` y un lease caducado queda `reconcile`; no se habilita el reintento automático.
- `inspect` devuelve solo estado, número de intentos, HTTP, vigencia del ledger y booleanos denegados. No muestra email, nombre, ID de origen, namespace, token, cuerpo PST ni información de Salesforce.

## Límites

**Esta clase NO escribe `delivery_state=ready`**. La outbox real y sus eventos existentes permanecen `blocked`; no se cargan ni invocan la clase desde módulo, instalación, Back Office ni cron. No hay cliente HTTP, token exchange, campañas ni migración adicional. Solo una ejecución CI sobre base de datos desechable `g3_ci` cambia temporalmente filas sintéticas a `ready` para certificar la reclamación.

El Consent Check individual de Growth, las bajas, el fence de fuente justo antes del envío y la conciliación asíncrona HTTP 202→DLO/DMO siguen siendo requisitos del despacho futuro, después de autorización expresa. G6 se aceptó administrativamente, lo cual no concede permiso para enviar a contactos concretos.

## Certificaciones

El test `tests/integration/g7_staging_capture_prestashop_db.php` reutiliza la outbox G3 y cinco eventos sintéticos en MySQL8/MariaDB10.11/11.8: denegación de claims bloqueados, CAS válido, segunda reclamación denegada, token incorrecto, revisión antigua denegada, 202→reconcile, respuesta perdida, cancelación, expiración, revocación del modo seguro y salida sin PII. Restablece el esquema y los eventos de prueba al final.

El contrato `tests/g7-native-prestashop-lease-contract.php` se ejecuta con PHP 7.1/8.5 y comprueba que no existe una ruta de transporte, cron ni activación automática a ready.

**No hay nuevo ZIP en este incremento.** Staging conserva 0.8.8 Test; Barcelona Confort producción mantiene 0.7.1 Test. No repetir canaries G5 ni pantallas G6.

## Para acabar G7 y G8

Conectar una lectura nativa fresca de PSCustomer/PSOrder a un despacho con fuente y consentimiento revalidados antes de cada POST; implementar la política explícita de pasar a ready solo tras autorización, un transporte de ingesta con conciliación y tratamiento de errores. Después completar carritos con exclusión de compra/baja y realizar UAT G8 reversible. No crear un segundo mecanismo de colas.
