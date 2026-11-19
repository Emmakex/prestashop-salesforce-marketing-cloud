# 0.9.1 — Conexión Salesforce desde el módulo PrestaShop

**Producto:** ALFABH · PrestaShop Salesforce Marketing Cloud Growth + Data Cloud. **Fecha:** 19/11/2026.

## Qué se administra directamente

En **Módulos → Salesforce Growth + Data Cloud → Configurar → Conexión con Salesforce**, el administrador principal de una tienda concreta puede:

- Guardar `Salesforce Org URL` (My Domain de Salesforce Core HTTPS), `Client ID` y `Client Secret`.
- Probar la conexión desde el servidor PrestaShop mediante **dos operaciones OAuth exclusivamente**: client credentials de Salesforce Core y token exchange a Data Cloud (c360a). El indicador «Guardadas» solo confirma almacenamiento cifrado, **no prueba OAuth** hasta pulsar **Probar conexión**.
- Revocar las credenciales de esa tienda mediante confirmación explícita; otras tiendas permanecen sin cambios.
- Rotar la pareja completa ingresando de nuevo los tres valores. Los campos siempre aparecen en blanco y nunca se revela el secreto guardado.

**Ya no hace falta establecer variables `SF_GROWTH_*` en PHP-FPM para el envío individual** de cliente y pedido. La prueba 1+1 obtiene el token desde la configuración cifrada y **no envía nada** durante Guardar ni Probar conexión. El POST de datos sigue siendo un paso aparte, autorizado para un registro y sin reintentos automáticos.

## Seguridad

1. Los tres valores se empaquetan como un solo JSON en memoria y se cifran como una única envoltura **AES-256-GCM**, con nonce aleatorio de 96 bits, tag de 128 bits y AAD asociado al ID exacto de tienda.
2. La clave de 256 bits se deriva con HMAC-SHA256 de la constante privada de PrestaShop `_COOKIE_KEY_` que está en los archivos de configuración del servidor, **fuera de la base de datos**. Se separa criptográficamente por aplicación, finalidad y tienda. Si esa clave cambia o se restaura la BD a un servidor con otra clave, las credenciales ya no se pueden descifrar: será necesario introducirlas de nuevo. La seguridad depende de mantener privados los archivos de configuración y las copias de seguridad.
3. Solo se escribe un valor en `Configuration` con `id_shop` explícito; no se crea una tabla ni se mezclan tiendas. No se utilizan ni se migran claves heredadas del proveedor antiguo.
4. El formulario no devuelve URLs/Client IDs secretos/Client Secret ni OAuth tokens. No se guardan tokens en BD, logs o caché. Solo el administrador con `id_profile=1`, autorización CSRF válida y contexto explícito de una tienda puede guardar, probar o revocar.
5. La URL se valida con el cliente OAuth existente: HTTPS + dominio `*.my.salesforce.com` de Salesforce. El token exchange solo acepta `*.c360a.salesforce.com`; no se admiten endpoints de terceros, redirects ni TLS sin verificación.
6. Revocar escribe una lápida solo para esa tienda: no se llama a `Configuration::deleteByName` para no afectar otras instalaciones. Los respaldos antiguos deben protegerse según la política de privacidad del cliente.
7. Guardar o probar nunca habilita sincronización masiva, envíos de marketing, recuperación de carritos, históricos ni captura local. La actualización 0.9.0 → 0.9.1 conserva Test y captura OFF, sin DDL, backfill o intentos de conexión automáticos.

## Prueba UAT en Barcelona Confort

1. Instalar **0.9.1** primero en el PrestaShop 9.1.5 de pruebas; verificar que aparecen las tres acciones de conexión en el nuevo Back Office, con los secretos siempre ocultos.
2. En BC producción, tras copia de seguridad verificada y aprobación del despliegue, **actualizar** el módulo 0.7.1 a 0.9.1 sin desinstalar. Confirmar Test y ausencia de campañas.
3. Introducir las credenciales de la Connected App **de Growth/Core**, no Engagement. Hacer clic en **Guardar conexión cifrada** y luego en **Probar conexión OAuth + Data Cloud**. Revisar el mensaje de Core y de token exchange, sin facilitar credenciales por chat.
4. Elegir un cliente y su pedido de BC autorizados para la prueba; usar **Validar datos sin enviar**. Tras la autorización específica, enviar un único PSCustomer y verificar su DLO. Después enviar un único PSOrder y verificar su FK.
5. HTTP 202 es **solo aceptación asíncrona**, no confirma DLO/DMO. Si hay timeout o error, no repetir el envío: revisar en Salesforce antes de ninguna nueva acción.

**Alcance no completado:** reconciliación automática DLO/DMO, worker G3, carritos, Consent Check comercial por contacto, Flow Growth y UAT end-to-end del piloto real. 0.9.1 soluciona el paso de administración y autenticación desde el propio módulo, sin fingir que la integración completa está activa.
