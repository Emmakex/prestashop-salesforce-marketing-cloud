# Pre-beta 0.9.4 — Diagnóstico seguro de la validación 1+1 en BC

**Incidencia real observada:** en Barcelona Confort (PrestaShop 1.7.8.11) después de confirmar OAuth y guardar `BCABH` + namespace, la pantalla 0.9.3 mostró «Prueba bloqueada o ya intentada...». **Ese mensaje no permite saber la causa** porque es el mismo `catch(Throwable)` para previsualizar localmente y para autorizar un POST real.

## Solución

- El botón **«1. Validar datos sin enviar»** sigue siendo estrictamente local, sin token ni HTTP. Ahora devuelve un diagnóstico acotado para: cliente inexistente/en otra tienda, pedido inexistente/en otra tienda, pedido de otro cliente, moneda no validada, invitado/eliminado, campos obligatorios, flags, importe, formato de correo, formato de fecha local y esquema PSCustomer/PSOrder inválido.
- Cada mensaje de validación local termina indicando que **no se ha enviado nada**; no presenta de manera errónea el estado «ya intentado» de los dos botones separados de envío real.
- Las acciones de **envío real** conservan reserva exact-once, bloqueo por DLO de cliente, Test/Source/Shop/OAuth y prohibición de reintentar sin conciliación. Un error de envío no afirma falsamente que no salió un POST.
- Diagnósticos de **lista blanca** (código de excepción constante → etiqueta fija traducible). Nunca se muestran: correo/nombre/ID, SQL, amount, payload JSON, HTTP body, host, token o mensaje de excepción bruto; los errores desconocidos se reducen a una frase genérica segura.
- `GrowthOneRecordSelection` distingue explícitamente `customer_not_in_shop`, `order_not_in_shop` y `customer_order_mismatch`. Ninguna validación ni campo requerido de seguridad se ha relajado.

## Pasos después de instalar 0.9.4

1. Actualizar el módulo **sin desinstalar** y con copia verificada. Confirmar modo **Pruebas controladas**, identidad **BCABH** y credenciales OAuth intactas.
2. Introducir el **ID del cliente y el ID de su pedido** de BC. Pulsar solo **«1. Validar datos sin enviar»**, NO «Enviar un cliente» ni «Enviar pedido».
3. Anotar únicamente el **texto fijo del mensaje**, sin datos personales ni SQL. Según categoría, revisar en el Back Office la vinculación del pedido, la tienda, el invitado/baja y los campos de fecha, moneda e importe.
4. Si la validación local es correcta, preparar por separado la autorización explícita de un registro con base jurídica/consentimiento correspondiente; verificar DLO después del primer 202, antes de pedido. No dar por terminado G7 ni Growth.

## Garantías

Upgrade `0.9.3 → 0.9.4`: no DDL, no nuevos hooks ni tablas, no modificación de identidad, fuente, vault cifrado, cron, outbox/ledger, ni POST Salesforce. Mantiene Test + captura OFF. Certificación CI con errores sintéticos de cada categoría, compatibilidad 1.7/8/9 y ausencia de strings de excepción privadas.

**No se puede inferir la causa de la captura antigua** con el texto anterior. Este parche permite saberla con una **nueva validación exclusivamente local**, sin reintentar ningún envío real.
