# G7 — Diagnóstico nativo de cliente y pedido desde la misma outbox (0.8.5)

**18/11/2026 — Solo staging, Growth + Data Cloud, Test.** Seguimos tras la captura real de `CUSTOMER_CREATED` cliente #7, `CUSTOMER_UPDATED` cliente #4 y `ORDER_CREATED` pedido #10 en la única outbox G3 de PrestaShop 9.1.5, versión 0.8.4. No es necesario crear más pedidos ni nuevas cuentas.

## Qué añade 0.8.5

La nueva acción del Back Office **«G7 - Read-only native PST source check»** permite ejecutar la primera lectura real controlada del **último cliente y del último pedido en la outbox**, sin exportarlos:

1. Verifica que `PS_SFMC_MODE=test`, `PS_GROWTH_LOCAL_CAPTURE=staging_armed`, identidad Merchant+namespace estables y motor SQL con restricciones `CHECK` activas.
2. Con el **plan G3 ya instalado** (ninguna tabla adicional), busca exclusivamente el evento local `blocked` más reciente por tipo, usando `Db::executeS(..., true, false)` para evitar caché SELECT.
3. Lee la **misma tabla** `growth_entity_ledger_v1` y exige que la última secuencia de esa entidad coincide con el evento seleccionado y que ambos siguen `blocked`.
4. Carga objetos nativos actuales `Customer`, `Order` y `Currency` mediante sus constructores PrestaShop. En pedidos exige relación cliente, tienda y moneda coherentes.
5. Reutiliza **sin duplicar esquema** `GrowthNativeCustomerOrderDraft::buildCustomer / buildPair` y `GrowthTypedPstPayloadBuilder` para validar los 11 campos PST v0.4 de cada objeto, *solo en memoria*.
6. Descarta los JSON y comprueba nuevamente el ledger **sin caché**. Si entre las dos lecturas cambió la última secuencia, aborta. Esta doble lectura **no es un fence transaccional** y no certifica datos fuente inalterables hasta un futuro POST.
7. Muestra únicamente `customer: native_pst_shape_verified_blocked`, `order: native_pst_shape_verified_blocked` u otra condición genérica; **ningún nombre, email, pedido, importe, namespace, endpoint, token ni cuerpo JSON** aparece en el panel.

Se puede ejecutar de nuevo sin efectos secundarios: **no hace INSERT/UPDATE/DELETE/DDL**, no reclama lease ni conecta HTTP, no abre el SMTP del hosting, ni permite envío o reintento. La instalación sigue sirviendo a otras tiendas PrestaShop 1.7/8/9; no se codifica ninguna tienda ni su origen Salesforce en la ruta.

## Protocolo de prueba

- Actualizar únicamente `/presta/` con ZIP **0.8.5** después de CI y runtime PS 1.7/8/9 verde. **Nunca desinstalar ni borrar tablas.** Upgrade 0.8.5 deja `PS_SFMC_MODE=test` y `PS_GROWTH_LOCAL_CAPTURE=off`; conserva HMAC, identidad, outbox y ledger.
- Desde BO, rearmar captura local Test existente con la aprobación staging previamente usada. No se crean tablas adicionales.
- Pulsar **«Check native customer and order (no sends)»**. El resultado certificado solo informa de *forma de datos fuente* vigente en ese instante; un fallo no toca la outbox.
- Si algún cliente/pedido nativo no tiene campos completos (ej. `date_upd`, `current_state`, flag), fallar de forma segura y ajustar la compatibilidad de lectura sin inferir consentimiento, pagos ni fechas.
- Se certifica por contrato PHP7.1/8.5 y por MySQL8/MariaDB10.11/11.8 desechables. La prueba PrestaShop runtime sigue verificando instalación/upgrade y que el diagnóstico está inerte al cargar el módulo. **Hasta recibir evidencia del BO del staging, la prueba en la tienda real se considera pendiente.**

## Lo que sigue bloqueado

**G5**: verificación de ingesta de un canary sintético (asíncrona: HTTP 202 no demuestra DLO/DMO). **G6**: Consent Check nativo Marketing/Email por contacto, Business Unit y ámbito. **G7/G8**: relectura fresca en el instante de despacho, lease/claim aprobado y conciliación real. La consulta BO no convierte `status=blocked` en ready ni autoriza POST. **BC producción permanece en 0.7.1 Test** sin cambios; el origen histórico no se mezcla con staging.

Relacionado: [G7 outbox→PST sin IO](G7_CAPTURED_OUTBOX_PST_PREVIEW.md), [incidencia central #12](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/issues/12).

## Incidencia real de staging y corrección diagnóstica 0.8.6 (18/11/2026)

Después de instalar 0.8.5, el propietario confirmó con una captura del BO de PrestaShop 9.1.5 que **Local Test capture estaba armado** (botón «Stop local capture») y que al pulsar «Check native customer and order» apareció el mensaje genérico:

> G7 local PST check blocked: missing or changed source, schema or ledger. No records sent.

**El origen exacto de la excepción NO se puede deducir de ese mensaje**, porque 0.8.5 englobaba cliente y pedido en un solo `try/catch` y descartaba deliberadamente su código. Los clientes y pedidos originales ya se capturaron en la outbox; este fallo es del nuevo diagnóstico de relectura, **no prueba una caída de capturas ni de Salesforce**.

0.8.6 reemplaza ese mensaje por **dos estados independientes** (customer y order) e introduce un clasificador estricto de errores a categorías predefinidas, como `native_customer_flag_unverified`, `native_source_scope_or_relation_invalid`, `ledger_event_not_latest` o `unclassified_blocked`. Se usa exclusivamente una lista cerrada de errores del código; **jamás** se imprimen mensajes crudos de excepción, SQL, IDs, emails, importes o credenciales. Esto evita que un fallo de cliente oculte el resultado del pedido, y permite corregir la causa concreta a partir de su categoría, sin inventar flags, estado de pago ni consentimiento.

- Instalación **solo staging** mediante actualización ZIP 0.8.6, **sin desinstalar**. La actualización no destructiva conserva outbox, ledger, binding, namespace y secreto HMAC; pone `PS_SFMC_MODE=test` y captura local `off`.
- Rearmar «Local Test capture» con la misma aprobación de staging y volver a pulsar el botón de diagnóstico **una sola vez**. No crear otro cliente ni pedido ni volver a ejecutar pruebas de compra.
- Leer los códigos `customer: ... | order: ...` para decidir el ajuste mínimo. Una categoría bloqueada **no** es un envío ni una autorización de exportación.
- CI valida el clasificador con una excepción que contiene PII sintética, y verifica que nunca aparece el contenido desconocido. Runtime PS 1.7/8/9 sigue manteniendo módulos en Test sin transporte.

## Incidencia 0.8.6 — causa comprobada del código `setup_unavailable` (18/11/2026)

El propietario ha instalado 0.8.6, ha armado local Test capture y al pulsar la comprobación G7 ha obtenido `G7 local PST check blocked (setup_unavailable). No records sent.`. Al revisar el **código exacto del controlador**, se ha identificado un fallo determinista: tras procesar individualmente el cliente y el pedido, `verifyStagingNativeReadback()` intentaba llamar a **`$this->escape()`**, método **no definido** en la clase `PsSalesforceBridge`. El `Error` se atrapaba por el `catch` externo y se mostraba como `setup_unavailable`, incluso si las verificaciones de origen habían concluido; no permite inferir si los datos fuente pasaron o fallaron.

La versión **0.8.7** introduce la implementación concreta `escape()` con `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` para HTML BO, sin cambiar outbox, ledger, fuente, G4 ni Salesforce. Se ha incorporado una prueba de regresión que invoca el **método privado real del controlador** con modo Test y captura armada, y verifica que aparecen **dos códigos independientes** y que ya no puede salir `setup_unavailable` por un helper inexistente. Incluye una prueba adicional de escapado HTML frente a caracteres de etiquetas y comillas. La prueba de regresión no usa tiendas ni clientes reales; las pruebas runtime PrestaShop 1.7/8/9 y DB siguen aparte.

Despliegue: actualizar **solo staging** a 0.8.7 **sin desinstalar**. El upgrade mantiene `test` y vuelve a poner captura local `off` por seguridad, sin borrar tablas ni HMAC; rearmarla con la frase de aprobación ya utilizada. Pulsar de nuevo **solo** `Check native customer and order (no sends)`. Enviar los dos códigos `customer: ... | order: ...`, que ahora distinguen la causa real del diagnóstico. **No crear nuevos pedidos/clientes; no exportar ni iniciar campañas**. Producción BC 0.7.1 Test permanece sin cambios.

