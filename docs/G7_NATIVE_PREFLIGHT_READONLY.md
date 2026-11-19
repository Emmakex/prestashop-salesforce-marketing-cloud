# G7 — doble relectura nativa antes de un futuro despacho

**19 de noviembre de 2026.** Este incremento une componentes G3/G4/G7 ya certificados y sigue siendo solo lectura; no incorpora un sender ni nuevas tablas.

## Alcance implementado

`GrowthG7NativePreflight::inspectLatest` inspecciona **la última fila** de una tienda y tipo `customer` o `order` en la outbox G3 ampliada:

1. Reutiliza `GrowthPrestaShopDeliveryLease` para comprobar que el namespace es de staging, el modo es Test, la captura local está detenida y el esquema extendido `extended_g7_verified` está íntegro. Una fila solo se inspecciona si permanece `blocked`, con 0 intentos y vigente en el ledger.
2. Lee de nuevo el ledger y los objetos nativos `Customer`, `Order` y `Currency`, utilizando las clases actuales para generar y verificar en memoria la forma PST v0.4 `PSCustomer` / `PSOrder`. No conserva el cuerpo PST ni genera un paquete de envío.
3. Aplica `GrowthLocalCustomerHoldReader` a la fila nativa de cliente con una lectura SQL sin caché. Cuenta inactiva, invitado, eliminado, newsletter/optin negativos o consulta fallida **impiden** considerar la fuente limpia; las señales locales afirmativas también quedan bloqueadas porque **no equivalen a consentimiento Growth**.
4. Repite la lectura nativa y del ledger, compara las huellas de origen **solo en memoria**, y comprueba nuevamente la revisión del lease. Una modificación o pérdida de evidencia produce `hold_for_review`.
5. La respuesta expone únicamente un código de motivo, estabilidad de doble lectura, estado genérico de señales locales y booleanos de seguridad. **Nunca IDs, emails, valores de compra, JSON, tokens, URL, SQL, hashes ni nombres.**

## Lo que no hace

La clase **no llama** `claim`, `cancel`, ningún `UPDATE` ni ningún POST. Los cinco campos del esquema G7 instalado en staging siguen bloqueados; no existe operación que pase una fila a `ready`. No está conectada a los hooks, al cron ni al Back Office de la versión 0.8.8.

La doble lectura reduce la posibilidad de usar datos desactualizados, pero **no elimina la carrera entre una última lectura y un eventual POST**. El futuro sender tendrá que cerrar esa ventana con un mecanismo de exclusión y revalidar permiso y consentimiento en el instante efectivo. La aceptación administrativa G6 no sustituye una autorización de envío ni certifica cada contacto.

## Pruebas

El archivo `tests/integration/g7_staging_capture_prestashop_db.php` usa la misma base `g3_ci` de MySQL y MariaDB y los eventos sintéticos G3 ya existentes: prueba que ambos tipos de objeto se proyectan, que un opt-out local produce una razón de bloqueo, que una modificación entre las dos lecturas se detecta, que un tipo `cart` no puede usar un contrato `PSOrder`, que los informes no contienen datos personales y que ninguna fila cambia de estado ni incrementa intentos. La tabla de clientes es una respuesta sintética inyectada; no se conecta a clientes reales.

`tests/g7-native-preflight-contract.php` comprueba, bajo PHP 7.1–8.5, ausencia de rutas de escritura, envío, cola, cron o activación automática.

## Próximo trabajo, sin reabrir G5 ni G6

Desarrollar el control de elegibilidad y revocación `ready` con fuentes actuales y Consent Check de Growth, transporte autorizado y conciliación HTTP 202→DLO/DMO, y la exclusión específica de carritos tras compra/baja. Después realizar G8, autorización expresa de salida y rollback. No se precisa ZIP por este PR: staging permanece en **0.8.8 Test**, producción Barcelona Confort en **0.7.1 Test**.
