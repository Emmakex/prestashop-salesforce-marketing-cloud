# G7/G8 — Coordinación automática de eventos nuevos sin reintentos (20/11/2026)

**Objetivo acordado:** finalizar el puente **Marketing Cloud Growth + Data Cloud**. La configuración de consentimiento Growth/CRM ha sido confirmada como verificada por el administrador, según el propietario; no rehacerla. El test BC 1+1 (cliente y pedido) ya está verificado en DLO **y** DMO. **No reejecutar los POST de prueba** ni sobrescribir su instalación.

## Implementado en este corte (núcleo, sin activación)

`GrowthAutomaticOneShotCoordinator::tick` es el motor de coordinación ejecutable para lotes acotados (máx. **10 candidatos por pasada**). El código impone:

1. Misma tienda, `merchant`, namespace, fuente Data Cloud vinculada, **modo Test** y captura local desactivada. Comprueba la configuración persistente antes de arrancar y otra vez inmediatamente antes de cualquier intento de POST.
2. Solo `PSCustomer` y `PSOrder`; no se mezclan comercios ni se aceptan referencias externas desalineadas.
3. Comprobación **por registro** de ausencia real en DLO para no duplicar tampoco los canarios y los envíos manuales ya verificados (estado `present` o `unknown` bloquea). Este punto requerirá un adaptador de consulta Salesforce específico; no basta con un HTTP 202 anterior.
4. Pedido **solo tras presencia exacta del cliente** verificada en DLO. `customer_source_key` se valida contra el namespace, tienda y ID real del cliente.
5. Lectura nativa y de elegibilidad local fresca, **reserva atómica persistente antes del POST**, segunda lectura después de reservar y comprobación de huella SHA256. Si cambia cualquier dato, retener.
6. La reserva SQL nativa solo puede obtenerse con `delivery_attempts=0`, sin token/TTL/HTTP anteriores y sobre la última revisión del ledger. Aunque otro proceso dejara indebidamente la fila en `ready`, un intento anterior nunca vuelve a reclamarla. No se crea transición a `ready` ni se conecta ningún sender.
7. Respuesta HTTP **202** → estado `reconcile`, pendiente de observación DLO/DMO. HTTP distinto de 202 → `held`; timeout/estado desconocido → `reconcile` sin reintento ciego. El resultado expuesto contiene **solo contadores**, nunca IDs, email, payload ni fuente.
8. El motor **no envía campañas**, no presupone permiso por `newsletter=1` ni modifica directamente el consentimiento CRM.

## Componentes existentes que deben conectarse, sin crear una cola nueva

- **Origen**: hooks PrestaShop `actionCustomerAccountAdd`, `actionCustomerAccountUpdate` y `actionValidateOrder` → `GrowthStagingHookCapture` y `GrowthStagingOutboxStore` para versiones vigentes; se reutilizan las mismas tres tablas G3 de outbox, binding y ledger.
- **Reserva/dedupe**: `GrowthPrestaShopDeliveryLease` y `GrowthG7CurrentEventBatch`. Los actuales adaptadores son **staging/Test**, no operativos en BC producción. No desbloquear el envío dejando `delivery_state=ready` sin una política final de baja/revocación y una autorización expresa del entorno.
- **Serialización**: `GrowthNativeCustomerOrderDraft` (PST v0.4) con datos **solo en memoria**.
- **Autenticación/HTTP**: `GrowthShopCredentialVault`, `GrowthShopSourceBinding` y `GrowthDataCloudUpsertTransport`, ya probados en el flujo manual BC.
- **Consentimientos**: conservar el **Consent Check nativo Growth/CRM existente**, sin volver a configurar el tenant. La validación administrativa de la integración no sustituye leer bajas y estado de cada contacto al enviar.
- **Programación**: conectar un `cron` protegido por tienda + token, con límites y stop gate, una vez verificados los adapters y la base de datos real. El endpoint actual de `pssalesforcebridge` **solo informa estado**; no debe afirmar que sincroniza.

## Evidencia/alcance de este PR

Este corte es **núcleo de la automatización, no go-live**. La interfaz abstracta utiliza callbacks estrictos para permitir pruebas sintéticas y adaptación a los lectores/ledger reales sin HTTP ni SQL en CI. `tests/g8-automatic-one-shot-coordinator-contract.php` cubre secuencia customer→order, confirmación DLO, presencia remota previa, opt-out local, drift entre dos lecturas, 202, timeout, 503, reserva previa, cross-shop, fuente DO separada y límite máximo de 10.

**Resta antes de beta operativa:** adaptar G3 de staging a la instalación BC sin relajación de checks SQL; cablear callbacks a eventos actuales + DLO GET con permisos, enlazar al cron protegido y botón Stop; certificar error/rollback, conciliación 202 y dedupe real en staging; pedir autorización para habilitar *ese flujo de envío automático concreto*. No usar esta clase directamente para POST productivo hasta completar esos pasos.

**Próximo PR recomendado:** runtime de cron **captura local y conciliación solamente**, luego activar el envío acotado con CAS tras UAT. No ampliar alcance hacia sistemas ajenos ni crear una segunda cola.


## G8 — Adaptador G3 → coordinador en preflight de staging (23/11/2026)

El componente `GrowthG8StagingCandidateAdapter` traduce **solo en memoria** las referencias del escáner `GrowthG7CurrentEventBatch` a `shop/type/id/sequence` que admite `GrowthAutomaticOneShotCoordinator`. Verifica identidad de tienda, tipos `customer/order`, secuencias crecientes, cursor, ausencia de referencias duplicadas, límite de diez y que las banderas `can_set_ready/can_claim/HTTP/ingesta/campañas` sean todas **false**. Ningún ID se presenta al usuario.

El cron actual `inspect` ya utiliza este adaptador dentro de su recorrido G3 de staging, pero **solo publica un contador** y no expone las referencias transformadas. `status` sigue inmóvil. Este corte no crea productor READY, reserva CAS, sender, OAuth ni campaña. La conexión efectiva del coordinador y del reader DLO a un worker quedará bloqueada hasta UAT staging Test, lectura fresca/CSC por contacto, persistencia `reconcile/held` y aprobación explícita del responsable. Evitar extender esta función a namespaces de producción o DO sin un origen certificado.


## G8 — Corte de seguridad G3 + consentimiento Growth + DLO (23/11/2026)

Dos componentes **aislados de cron y BO** completan la composición de las comprobaciones para staging, sin crear nuevas tablas, colas, configuración de permisos ni un productor de eventos READY:

- \`GrowthG8StagingSafetyReview::inspect\` consume las referencias ya adaptadas G3/G7 a G8, el \`GrowthPrestaShopDeliveryLease::inspect\` real (solo lectura), la revisión \`GrowthG7CscPreDispatchReview::inspectSelected\`, el lector DLO y el ID de cliente derivado del pedido nativo. Devuelve solo contadores sanitizados; una revisión incorrecta, una secuencia no actual, un consentimiento denegado, un DLO existente o desconocido, o un cliente padre sin verificar **no permite despacho**. Ni siquiera una CSC positiva observada autoriza el envío: el resultado de G7 conserva \`send_time_consent_certified=false\` y requiere cercado/validación de consentimiento al enviar.
- \`GrowthG8ScopedDloRead::inspect\` adapta el lector Query API V2 probado al \`shop/merchant/namespace/source\` actuales. Exige un descriptor privado de las **dos** entidades DLO derivado del metadata resolver del mismo token y host, y rechaza reutilizar metadatos de Barcelona Confort en DO u otra tienda. Conserva \`present / absent_confirmed / unknown\`, solo consultas \`SELECT\` para las claves exactas y ninguna respuesta con PII.

Los contratos offline de PHP 7.1/8.5 comprueban un cliente/pedido sintético, consulta del padre, CSC positiva no certificada, baja/negativa, READY/lease incoherente, 202 pendiente, host/tienda/namespace/origen cruzados, timeout y cero envíos. En este corte **no** se carga ninguna clase nueva en el módulo ni se añaden operaciones al cron; quedan disponibles para la futura integración bajo autorización específica de staging.

**Bloqueo que sigue siendo real:** todavía no hay un lector CSC real certificado para el contacto concreto al instante de envío, ni un productor READY aprobado. Hasta cerrar ese cercado de consentimiento y el ensayo G3 CAS/reconcile de un evento sintético nuevo, el coordinador no puede ejecutar \`postOnce\`; seguimos en Test, captura OFF y campañas apagadas. No repetir los 6031/612 ni los canarios G5. No confundir la validación administrativa de Growth/CRM con permiso automático de contacto para un cliente concreto.


## G8 — CAS y recibos duraderos: protocolo probado sin transporte (23/11/2026)

El adaptador \`GrowthG8DurableReceiptBridge\` enlaza la firma del coordinador de envío único con **la misma** \`GrowthPrestaShopDeliveryLease\` nativa del outbox/ledger G3. No escribe READY ni crea colas ni incorpora clientes HTTP. Exige exclusivamente staging/Test/captura OFF/origen de la tienda/owner gate y comprueba estado READY, revisión vigente, \`delivery_attempts=0\` y ausencia de HTTP previo. Un token CAS aleatorio de 256 bits solo vive en memoria. El inspector final \`claimedForPost\` confirma que el registro sigue reclamado y su lease vigente; **no sustituye el control CSC de contacto ni autoriza envíos**.

\`GrowthAutomaticOneShotCoordinator::tick\` pasa al callback de recibo \`($candidate,$state,$httpStatus,$transportStarted)\`, manteniendo compatibilidad con callbacks antiguos de menos argumentos, para registrar el resultado sin inventar un estado:
- HTTP **202** después del transporte → \`markAcceptedUnconfirmed\`, estado G3 \`reconcile\`, no \`delivered\`.
- HTTP **503** u otro código no 202 conocido → \`holdClaim\` con HTTP exacto, estado G3 \`held\`.
- timeout/resultado malformado después del inicio del transporte → \`markUnknownUnconfirmed\` y \`reconcile\`, sin reintento.
- cambio/supresión de origen **antes** de cualquier transporte → \`cancel\` y \`cancelled\`, sin POST.
- falla la persistencia del recibo → \`reconciliation_required\` y claim no reutilizable. El eventual TTL caducado se pone en \`reconcile\`, nunca READY.

\`tests/g8-durable-cas-receipt-bridge-contract.php\` certifica el circuito completo de coordinador + CAS + recibo con objetos sintéticos (sin red ni base de datos). El CAS SQL de \`GrowthPrestaShopDeliveryLease\` y el comportamiento de concurrencia siguen cubiertos por pruebas G3/G7 independientes.

**Importante:** este componente no se carga en el módulo ni en el cron. G8 aún requiere la fuente CSC de envío certificada por contacto, una autorización específica del propietario y una UAT aislada de un registro nuevo en staging antes de cualquier operación de transporte real. La configuración administrativa Growth/CRM, los DLO y canarios G5, y cliente/pedido BC 6031/612 están verificados y no se repiten.


## G8 — Cierre de la valla por contacto antes de liberar una versión (23/11/2026)

El coordinador `GrowthAutomaticOneShotCoordinator::tick` ahora exige un callback **obligatorio y sin valor predeterminado permisivo** de elegibilidad por contacto. La firma del callback es `($candidate, $nativeCustomerId, $phase): bool` y se ejecuta en tres puntos: `before_claim`, `after_claim` y `before_transport`. Solo el booleano literal `true` permite continuar. El cliente se deriva del objeto nativo (para pedidos) o coincide con el propio cliente (para PSCustomer); jamás se acepta la identidad de contacto de otra tienda.

La valla tiene finalidad **Data Cloud ingestion**, no autorización de campañas de Marketing Cloud Growth. Una autorización de administrador en CRM tampoco sustituye esta comprobación para un contacto. Si el lector falta, es ambiguo, falla, cambia la elegibilidad, se revoca o devuelve un valor distinto de `true`, la operación se bloquea. Si ya se reservó la fila G3, el callback de recibo la cancela sin POST y sin reintentos. El transporte continúa obligado a validar nuevamente el lease nativo y a guardar `202→reconcile` / timeout → conciliación.

**Importante**: los callbacks `true` de los contratos son exclusivamente sintéticos. La conexión a un lector de consentimiento/supresión realmente certificado por contacto y la UAT en la tienda de staging son requisitos restantes antes de conectar un worker o publicar el paquete final. **No crear v0.9.6 ni retirar Pre-beta en el módulo hasta terminar estas certificaciones.**

## G8 — Transición G3 bloqueado → READY (staging)

El componente `GrowthG8StagingReadyGate` habilita un CAS atómico sobre la **misma** outbox y ledger G3: `blocked → ready`, únicamente en staging, modo Test, captura OFF y con permiso de propietario. Comprueba que el evento sigue siendo la revisión vigente, intentos=0, sin lease ni HTTP previo, origen por tienda y tres verificaciones independientes: fuente/contacto elegible, ausencia DLO confirmada, y para pedidos, cliente padre verificado.

`scanReady` solo devuelve referencias privadas acotadas a 10 y vigentes. No autoriza un envío ni genera tablas, campañas, credenciales o rutas de cron nuevas. El siguiente enlace es el worker autorizado que une estos eventos al coordinador, token Data Cloud, `GrowthG8DurableReceiptBridge` y respuesta Query V2.

Pruebas: `tests/integration/g7_staging_capture_prestashop_db.php` comprueba la promoción real a READY (reemplaza el UPDATE manual anterior), selección antes/después de claim, 202→reconcile y ausencia de segundo intento, en los tres motores MySQL/MariaDB de la CI. Este sandbox no reemplaza la UAT en la tienda de staging ni concede autorización de campañas.

**Bloqueo de versión:** no publicar 0.9.6 ni retirar «Pre-beta» hasta completar el worker real, la UAT en PrestaShop staging y la validación en Marketing Cloud Growth.

## G8 — Operación protegida de ingesta automática en staging (23/11/2026)

El módulo sigue en versión 0.9.5 mientras se certifica la UAT. Este corte conecta `GrowthG8NativeStagingServices`, `GrowthG8StagingWorker`, `GrowthG8CloudMetadataClient`, `GrowthG8ScopedDloRead`, `GrowthG8StagingReadyGate`, `GrowthAutomaticOneShotCoordinator`, `GrowthG8DurableReceiptBridge` y `GrowthDataCloudUpsertTransport` sin otro esquema, cola, cron paralelo, proveedor ni plataforma de campañas alterna.

**Activación de staging deliberadamente manual y revocable**: seleccionar una tienda exacta con namespace `stage`/`staging`, configurar su fuente Data Cloud y OAuth cifrado, modo `test`, captura `off`, consentimiento local conservador `marketing_optin`, comprobar el esquema G3 extendido, y autorizar `OWNER_APPROVES_STAGING_G8_INGESTION` desde el formulario del administrador principal. Solo entonces la operación cron `dispatch` con el secreto cron preexistente puede procesar **hasta 10 eventos**. `status` permanece inerte; `inspect` sigue siendo una lectura de recuentos. No hay scheduler activado automáticamente ni endpoint público sin token.

Para UAT: usar un **cliente y pedido sintéticos nuevos** en la tienda de staging y capturar sus hooks G3 de la tienda, con los registros publicados en modo `blocked`. Desactivar captura antes de despachar. Ejecutar una única llamada protegida `operation=dispatch` y verificar en la misma outbox G3 el cambio `blocked → ready → claimed → reconcile(202)`; comprobar en Data Cloud la llegada al DLO de cliente y posteriormente pedido. Una segunda llamada **no** puede reenviar los mismos registros. El pedido espera presencia certificada del cliente. Cualquier ausencia remota `unknown`, token fallido, supresión local, fuente diferente o error previo bloquea la ingesta; timeout o POST incierto queda en conciliación sin reintento. Retirar la autorización staging tras UAT.

**Consentimiento y alcance**: la elegibilidad local es un filtro conservador de ingesta personal a Data Cloud; no es autorización para campañas. Los consentimientos específicos, preferencias y opt-outs para enviar comunicaciones se aplican en **Salesforce Marketing Cloud Growth**. El módulo no envía campañas ; las campañas operan únicamente en Marketing Cloud Growth.

**No go-live todavía**: las pruebas CI y PrestaShop runtime no son la UAT de una instalación staging real. No instalar en BC producción, no repetir registros 6031/612 ni G5, no pasar a una versión final ni quitar `Pre-beta` hasta recibir prueba UAT y confirmación de segmentación/campañas Growth. No guardar tokens ni IDs en issues o logs.

## G8 — Preparación de captura desde Back Office en staging

Para iniciar la UAT sin acceder a SQL, el administrador principal puede utilizar **Preparar G3/G7 y activar captura para la UAT** exclusivamente en su tienda de staging. Es obligatorio confirmar un respaldo verificado y escribir la frase exacta `ENABLE_STAGING_LOCAL_CAPTURE_ONLY`; el formulario exige CSRF y mantiene Test, captura OFF y dispatcher sin autorizar. Internamente reutiliza `GrowthStagingSchemaBootstrap::install` y `GrowthG7StagingDeliveryMigration::apply` (DDL aditivo idempotente, sin nueva cola). Si una ALTER de MySQL falla, la captura queda OFF y la estructura requiere revisión manual; no se reintenta DDL automáticamente.

Tras preparar la captura, crear cliente y pedido **sintéticos nuevos** mediante PrestaShop. El hook solo registra identificadores protegidos en la outbox original, nunca efectúa POST. A continuación pulsar **Detener captura local**, comprobar que OAuth y fuente siguen listos, autorizar explícitamente la UAT G8 y efectuar una llamada de cron protegida con `operation=dispatch`. Verificar DLO en Salesforce después del cliente antes de enviar el pedido. Retirar la autorización al terminar. En modo producción no se expone este panel.

Esta preparación guiada no constituye una certificación de la tienda de staging ni concede permiso de comunicación comercial. La versión definitiva sigue pendiente de la UAT.

## Operación G8 UAT desde Back Office (staging, aún v0.9.5)

Para no exponer el secreto del cron al responsable de la tienda, la sección G8 autorizada permite **comprobar elegibilidad G8 sin enviar** y dos envíos UAT estrictamente separados. El preflight es local y consulta los indicadores `newsletter`, `optin`, `active`, `deleted`, `is_guest` del cliente seleccionado. Un valor negativo bloquea la prueba sin cambiar preferencias ni asumir consentimiento de campañas.

El botón **Ejecutar G8: solo cliente** exige administrador principal, CSRF, tienda/namespace exactos, aprobación existente, Test, captura OFF, par UAT fijado, elegibilidad local sin negativos y confirmación escrita `EXECUTE_G8_CUSTOMER_ONCE`. Utiliza el mismo `GrowthG8NativeStagingServices`, worker G3/CAS y transporte ya certificado; no utiliza la ruta manual de un registro. En la ejecución de UAT, el trabajador solo examina filas `PSCustomer` y no incluye el pedido.

El botón **Ejecutar G8: solo pedido** es una segunda acción separada (`EXECUTE_G8_ORDER_ONCE`). Exige también cliente padre `present` confirmado en el DLO, con comprobación repetida justo antes del transporte. Es necesario verificar manualmente el cliente en Data Cloud antes de habilitar la segunda acción. El cron `operation=dispatch` se limita por defecto a PSCustomer para evitar el envío accidental de un pedido.

HTTP 202 se registra como `reconcile` pendiente de confirmación DLO; un timeout es ambiguo y no reintentable. El panel no dice «entregado» tras una aceptación 202; si el resultado es incierto, no repetir la acción. Las campañas siguen fuera del módulo y pertenecen a Marketing Cloud Growth.

**Pendiente para release final:** UAT real del cliente 8/pedido 11 y comprobación en DLO/DMO; tras certificación, desactivar autorización staging, preparar versión final sin etiqueta pre-beta y separar aprobación de go-live de producción.

## G8 — recuperación de un primer intento con resultado desconocido (23/11/2026)

Tras autorizar la UAT del cliente de staging 8 y pedido 11, el Back Office mostró `G8: no se pudo confirmar el resultado. Antes de cualquier otro intento comprueba G3 y Data Cloud.` El mensaje corresponde al `catch (Throwable)` exterior del formulario; no acredita ni fallo ni éxito del transporte. **No repetir `customer`, no ejecutar `order`, no resetear el ledger ni permitir una campaña.**

`GrowthG8UatReceiptReview` consulta exclusivamente la revisión actual del cliente/pedido vinculado en las tablas originales G3 outbox+ledger, sin mutaciones: `blocked_no_attempt`, `ready_no_attempt`, `claimed_unconfirmed`, `accepted_202_unconfirmed`, `reconciliation_required`, `held_requires_review`, `cancelled`, `not_found` o `unverified`, más `attempts` y código HTTP estrictamente saneado. El BO presenta dos botones CSRF/administrador/tienda staging: **Revisar recibos G3 sin enviar**, y **Comprobar cliente en Data Cloud (lectura)**. El segundo hace OAuth Core→Data Cloud, metadata GET y Query V2 SELECT exacto por namespace/shop/cliente para devolver solo `present`, `absent_confirmed` o `unknown`; ninguna llamada al endpoint de ingesta, ningún DDL, ninguna campaña.

**Regla irreversible:** el resultado `202` requiere comprobar DLO, y el resultado `absent_confirmed` en un instante no prueba que un POST incierto no esté pendiente de ingestión. Las lecturas no autorizan reintentos ni cambian consentimientos. Hasta obtener ambas evidencias y diagnóstico del origen de la excepción, suspender UAT y la release final v0.9.6. Staging y producción son entornos separados.

## Recuperación UAT tras G3 blocked_no_attempt y cliente DLO ausente

Cuando el responsable comprueba cliente=blocked_no_attempt y pedido=blocked_no_attempt (G3) y PSCustomer=absent_confirmed (DLO), la información disponible no registra un CAS ni recibo de transporte. No iniciar un segundo POST sin diagnosticar el origen del fallo.

El botón protegido **Diagnosticar preenvío G8 sin enviar** aparece incluso con la autorización de staging revocada y comprueba solo fuentes locales: staging/Test/captura OFF, par seleccionado y origen, estado G3 actual, exclusiones locales del cliente, candidatos G3 exactos, estructura G7/lease por lectura y borrador nativo PSCustomer en memoria.

Responde exclusivamente con códigos PII-free como local_customer_excluded, local_customer_reader_exception, g3_candidate_unverified, g7_schema_or_candidate_unverified, native_customer_payload_unverified o local_pre_dispatch_validated_no_send. No POST, OAuth, cambios SQL ni campañas.

Un resultado local validado no autoriza el reenvío ni sustituye la evidencia de Data Cloud. Reparar solo el punto defectuoso y finalizar UAT antes de publicar versión definitiva.


## Diagnóstico de elegibilidad local del cliente (lectura segura)

Si el preenvío G8 devuelve `local_customer_excluded`, ahora informa **solo** los códigos locales permitidos y sin datos personales: `local_customer_deleted`, `local_customer_inactive`, `local_customer_guest`, `local_newsletter_negative`, `local_optin_negative`. Puede devolver varios motivos. El BO descarta cualquier motivo fuera de esa lista; no muestra el contenido de filas ni consulta Salesforce.

Los indicadores `newsletter` y `optin` proceden del cliente PrestaShop y se utilizan en el filtro técnico local actual; **no sustituyen ni modifican** el consentimiento comercial ya verificado en Growth/CRM. El diagnóstico por sí solo no autoriza cambiar las preferencias de un cliente, reenviar ni pasar el ledger G3 a READY. Si las preferencias son legítimamente negativas, no se deben modificar para forzar la UAT; se necesita un cliente sintético nuevo y elegible, con una autorización de prueba independiente y sin reutilizar el primer envío incierto.


## 23/11/2026 — Error BO G8 contextual y no repetición

El botón de elegibilidad G8 (`preflight`) nunca invoca `GrowthG8NativeStagingServices::run`. Si su lectura o renderizado falla, informa `preflight local inconcluso antes de cualquier envío`, sin afirmar nada acerca de otras operaciones anteriores. Si la preparación del formulario `customer/order` falla **antes** de llamar al worker, el mensaje lo distingue. Si se llega a invocar el worker, cualquier excepción en ejecución o visualización devuelve explícitamente `resultado no confirmado, puede haber existido un POST`. No se vuelcan excepciones, tokens, SQL ni datos personales. Los mensajes de BO no sustituyen las evidencias de G3 y Query V2; no habilitan reenvío, campañas ni modificaciones de consentimientos.
