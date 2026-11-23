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
