# G7 / 0.8.9 — Prueba real manual de 1 cliente y 1 pedido (BC)

**Alcance:** piloto Barcelona Confort, producto único Salesforce Marketing Cloud Growth + Data Cloud. La instalación 0.7.1 permanece activa en BC hasta instalar y comprobar esta candidata; staging 0.8.8 no cambia automáticamente.

## Qué incorpora 0.8.9

El Back Office del módulo, dentro de **G7 — Controlled one-record Data Cloud UAT**, dispone de tres acciones claramente separadas:

1. **Validar cliente y pedido (sin envíos):** introducir el ID de **un cliente real** y el de **su pedido**, siempre pertenecientes a la tienda PrestaShop seleccionada. Relee objetos nativos Customer, Order y Currency, comprueba relación exacta y construye PSCustomer/PSOrder conforme al esquema PST v0.4 de 11 campos. Respuesta saneada sin datos personales, sin POST ni tabla nueva.
2. **Enviar únicamente ese cliente (real):** después de que el responsable haya validado la base jurídica de la prueba y seleccionado el registro, escribir `I_APPROVE_ONE_REAL_DATA_CLOUD_RECORD`. Se obtiene OAuth Core/Token Exchange con secretos **del entorno del servidor**, se ejecuta un único POST al endpoint oficial de PSCustomer. El HTTP 202 queda `accepted_unconfirmed_202`, **no significa persistencia DLO/DMO**. Error y timeout quedan retenidos para revisión, sin reintentos.
3. **Enviar únicamente el pedido del mismo cliente:** comprobar **primero** el cliente en el DLO/DMO de Salesforce, y escribir también `CUSTOMER_DLO_CONFIRMED_IN_SALESFORCE`. El pedido se proyecta con FK al cliente, sin fabricar el estado paid. Se autoriza como máximo **un intento** de PSOrder de ese namespace+tienda.

**Protección anti-repetición:** dos marcas mínimas en la configuración PrestaShop por namespace/tienda/objeto. Reserva duradera **antes del POST** bajo un lock SQL de MySQL/MariaDB. Un 202, fallo inesperado, timeout o caída no permiten reenvío automático ni botón para resetear. La marca de un intento solo acredita el intento, no su llegada. Para investigar el resultado, usar Salesforce Data Explorer y los registros del tenant; no repetir desde BO.

## Configuración segura

- **No cambiar el modo Test.** La actualización fuerza `PS_SFMC_MODE=test` y `PS_GROWTH_LOCAL_CAPTURE=off`, no hace DDL, no modifica G3/legacy ni lanza ninguna transferencia.
- Es obligatorio tener merchant e installation namespace propios **y estables** en el BO. En multitienda se requiere el contexto explícito de **una tienda**, nunca `All shops`.
- Las credenciales Core OAuth no se introducen en formularios, URLs ni Git. El **servidor PrestaShop** necesita las variables de entorno `SF_GROWTH_ORG_URL`, `SF_GROWTH_CLIENT_ID` y `SF_GROWTH_CLIENT_SECRET` accesibles a PHP-FPM. Que existan en GitHub Actions **no las hace disponibles en la tienda**. Si faltan, la prueba real se bloquea sin reservar intento. No utilizar credenciales del transporte eliminado.
- Autorizar por escrito el tratamiento de ese cliente/pedido seleccionado para la prueba. El consentimiento de marketing en Salesforce es **independiente**: no se infiere de `newsletter` ni `optin`; ningún Flow, campaña o correo se activa.
- No usar clientes o pedidos ajenos a la tienda, registros borrados o de invitados; el validador bloquea los casos sin identidad o relación suficiente.

## Secuencia de aceptación

1. Hacer backup verificado de producción BC por el procedimiento externo existente. Comprobar versión PrestaShop/PHP y ventana de reversión.
2. Instalar el ZIP CI de **0.8.9** como actualización **no destructiva** desde 0.7.1. Confirmar modo Test, autor ALFABH, tienda funcional y tablas previas intactas.
3. Probar **solo la vista previa** con cliente y pedido autorizados. Si falla, no pulsar acciones de envío.
4. Proporcionar credenciales OAuth al proceso de PHP de la tienda mediante gestión segura del servidor; no enviarlas al chat.
5. Activar solo el POST manual **PSCustomer**. Leer resultado: 202 = recepción asíncrona pendiente. Comprobar posteriormente la PK `namespace:shop:customer:id` en `PST-PSCustomer` y el mapeo; no reutilizar G5 canaries.
6. Tras confirmar el cliente en Salesforce, activar únicamente **PSOrder**; comprobar la PK `namespace:shop:order:id` y FK `customer_source_key` en `PST-PSOrder`.
7. Con ambas filas y la relación acreditadas, **aún falta** conectar el worker G3 de captura continua, gestionar compra/baja del carrito, revisar consentimiento real de Growth/Flow, reconciliar DLO/DMO y certificar G8 antes del go-live completo.

## Resultado que NO se debe afirmar

Esta función es un **canary de producción manual acotado**, no ingesta continua ni sincronización automática. La aceptación de un HTTP 202 **no certifica DLO**, y el test de 1+1 no certifica campañas o carritos abandonados. Ninguna ejecución desde el Back Office inicia un cron, worker, Journey/Flow o correo. No eliminar la 0.7.1 histórica ni desplegar en otras tiendas sin probar este piloto.

## Última incidencia de lectura remota

El workflow protegido de lectura del catálogo DLO [#44518040970](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/runs/44518040970) devolvió `g7_live_metadata_entities_http_403`. Esto **no prueba** ausencia de DLO ni un fallo de ingesta: es una denegación en un endpoint concreto de metadatos. La recepción del 1+1 debe comprobarse mediante los permisos adecuados en Salesforce o por UI Data Explorer, sin repetir el viejo G5.
