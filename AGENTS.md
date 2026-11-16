**G6 vínculo Core Marketing / Email (16/11/2026):** el probe `G6MarketingEmailCoreBindingReview` realiza **solo un GET SOQL acotado de configuración** en `CommSubscriptionChannelType` filtrando `CommunicationSubscription.Name='Marketing'` y `EngagementChannelType.ContactPointType='Email'`. Devuelve exclusivamente flags, nunca IDs/valores del tenant. No reutiliza mapeo CSC 404 ni stream timeout; no consulta consentimientos de personas, no certifica Business Unit ni permite envíos. Solo manual en `main` tras CI. [Runbook](docs/G6_MARKETING_EMAIL_CORE_BINDING_READONLY.md).

**G7/G3 revisión versionada bloqueada (16/11/2026):** Solo CI: `G3GrowthBlockedVersionClaimReview` compara la versión `latest_accepted_sequence` bajo el mismo advisory lock de la outbox existente y `SELECT FOR UPDATE`. Compara exclusión local sintética (compra, baja, incierta), sin reclamar, persistir cancelación, habilitar PSCart ni permitir envíos. Tests MySQL 8/MariaDB 10.11; fuente [docs/G7_G3_BLOCKED_VERSION_CLAIM_REVIEW.md](docs/G7_G3_BLOCKED_VERSION_CLAIM_REVIEW.md). Nunca confundir esto con un fence transaccional de entrega Salesforce ni cambiar BC 0.7.1 Test.

# AGENTS — PrestaShop ↔ Marketing Cloud Growth + Data Cloud (único producto)

**G7 última comprobación local (16/11/2026):** `GrowthCartLastLocalExclusionReview` reutiliza exactamente 1 SELECT cliente y 2 SELECT de pedidos nativos/ledger **después** de la revisión fresca de snapshot SHA-1; `GrowthCartConversionHoldGate::inspectPrestaShopWithFreshLegacyCartFinalReview` compone los 7 SELECT anteriores + 3 nuevos, todos sin caché. Simula compra que aparece en tercer pase y baja después del snapshot. **NO es fence transaccional con Salesforce y NO habilita envíos**; no usar el outbox sandbox G3 para PSCart, no crear tabla ni cola paralela, no alterar hooks ni BC 0.7.1 Test. [Documento](docs/G7_LAST_LOCAL_EXCLUSION_RECHECK.md).


**G7 productor fresco local (16/11/2026):** `GrowthLegacyCartFreshNativeReview` reconstruye el JSON original completo de 0.7.1 desde objetos nativos Cart/Customer/Currency/getProducts/getOrderTotal **solo en memoria**, con reglas originales de `marketing_optin` y SHA-1 CHAR40. Se compone con las 7 SELECT sin caché existentes mediante `GrowthCartConversionHoldGate::inspectPrestaShopWithFreshLegacyCartReview`, sin vincularlo a hooks, cron, worker o Salesforce. La lectura no verifica origen/autenticidad del `snapshot_hash` almacenado porque sigue inyectado; tampoco prueba zona horaria histórica, últimos 60 minutos comerciales, consentimiento Growth CSC o exclusión atómica con envío. Incluso hash idéntico = `hold_for_review`. Nunca exportar JSON, hash, PII ni reintroducir transportes históricos. [Guía](docs/G7_FRESH_NATIVE_LEGACY_CART_REVIEW.md).


**G7 snapshot 0.7.1 corregido (16/11/2026):** se inspeccionó directamente el código histórico exacto [527fe127](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/commit/527fe127a681cf3869611e3cbceac36421a28aff): `CartCaptureService` calcula `sha1(json_encode($payload))` del array COMPLETO de `CartNormalizer` más 5 campos de elegibilidad/estado/fecha, guardado en `sfmc_cart.snapshot_hash CHAR(40)`. El protocolo G7 inicialmente SHA-256 era incorrecto y ahora está corregido a SHA-1 de 40 caracteres. `GrowthLegacyCartSnapshotDigestReview` reproduce el replay en memoria con payload inyectado y vector golden sintético; **no hay productor real conectado**, ninguna consulta PII, ni se compara un carrito nativo actual. `RecoveryService` histórico compara token con hash guardado, no reconstrucción en vivo. `GrowthCartConversionHoldGate::inspectWithNativeCartSnapshotReview` mantiene 7 lecturas locales y siempre `hold_for_review`. **NO reutilizar el hash SHA1 como token de seguridad ni autorización de envío**. No reintroducir código del antiguo transporte, no activar hooks, ni confundir 64 caracteres de recovery token con snapshot. [Informe](docs/G7_LEGACY_071_SNAPSHOT_SHA1_COMPATIBILITY.md).


**G7 ventana 60m (16/11/2026):** el candidato `GrowthCartInactivityWindowReview` reutiliza normalizador DST y zona IANA de tienda ya existentes. Exige reloj UTC de referencia, proveniencia de escritura explícita (sin inferir de `PS_TIMEZONE`) y clasifica 3599/3600 segundos, hora repetida/omitida, futuro e inconsistencia. Se integra opcionalmente en el lector carrito/productos sin SELECT adicional; composición G7 sigue en 7 SELECT sin caché, devuelve siempre `hold_for_review`. `shop_local_write_path_asserted` NO es prueba real ni autorización. Fuente [docs/G7_CART_60M_DST_WINDOW_REVIEW.md](docs/G7_CART_60M_DST_WINDOW_REVIEW.md). Nada conectado a hooks, cron, workers o Salesforce.


**G7 corte nativo (16/11/2026):** `GrowthLocalCartSnapshotReader` lee solo carrito y existencia de producto de misma tienda/cliente (2 SELECT); `GrowthCartConversionHoldGate::inspectWithNativeCart` reutiliza 1 SELECT cliente y 4 de pedidos, todos sin caché. Cualquier señal externa `abandoned`, `snapshot_current` o ventana 60 min se invalida, aun cuando el carrito contenga productos. Sigue sin consentimiento Growth certificado, sin cierre de carrera con despacho, sin PSCart stream, sin URLs de recuperación y sin envíos. [Detalle](docs/G7_NATIVE_CART_SNAPSHOT_REVIEW.md). No conectar el lector a hooks/cron antes de las aprobaciones.


**Decisión del propietario 14/11/2026:** se elimina la arquitectura multiproveedor. Este proyecto conecta tiendas PrestaShop **1.7.8.x, 8.x y 9.x exclusivamente** con Salesforce **Marketing Cloud Growth sobre Salesforce Core + Data Cloud**. No ofrecer otros destinos Salesforce, selector de proveedores, transportes alternativos ni rutas de fallback.

**Incidente G5 (16/11/2026):** el primer `/actions/test` recibió **HTTP 202**, que corresponde a aceptación de ingesta asíncrona y no certifica validación. Prohibido reintentar POST o aceptar 202 como éxito. La opción de GitHub Actions ha sido puesta en cuarentena y solo admite comparación del host Data Cloud obtenido por OAuth con la huella del servidor del YAML PST del propietario; sin llamadas de datos. Ver `config/g5-pst-incident-202.json` y `docs/G5_ABH_PST_SYNTHETIC_VALIDATION.md`.

**G6 topología GET inicial (16/11/2026):** el primer run de mapeos/streams [#43864960781](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/runs/43864960781) falló con `g6_topology_http_read_failed` sin HTTP de respuesta. Esto NO certifica ausencia, bloqueo de permisos ni resultado de consentimientos. El ajuste divide resultados de los dos GET, limita/redacta errores de transporte y conserva parciales. No relanzar el workflow anterior a la corrección. Fuente: `docs/G6_ABH_CONSENT_TOPOLOGY_READONLY.md`.

**G6 capturas Data Cloud (16/11/2026):** el DMO CSC es visible por GET de metadatos en Salesforce, pero no aparece en el Explorador de datos (búsquedas `con`/`co`) ni en Modelo de datos → **Asignado** (búsquedas Consent/Subscription, 0 elementos). No equivale a DMO ausente ni a cero consentimientos; no crear ni mapear un DMO por inferencia. Se prepara diagnóstico G6 exclusivamente de **2 GET de metadatos**: mapping CSC y primera página de streams; prohibido leer consentimientos individuales o usar acciones antiguas de MessagingConsent para escribir. Fuente: `docs/G6_ABH_CONSENT_TOPOLOGY_READONLY.md`.

**G6 verificación visual 16/11/2026:** la UI Marketing → Consentimiento → Suscripciones de ABH muestra una suscripción **Marketing** con canal **Correo electrónico**. Esto cierra la identificación funcional (nombre/canal), no la identidad técnica, el consentimiento por contacto ni permiso de envío. `config/g6-abh-marketing-email-ui-evidence.json`. Siguiente paso único: metadata GET-only del DMO CSC; la lectura de consentimientos individuales está prohibida en este corte, y la barrera final de envío continúa cerrada.

**G6 inventario del tenant (16/11/2026):** la configuración Core `CommSubscription` y el consentimiento efectivo Growth (CSC DMO en Data Cloud) NO son intercambiables. El workflow manual `g6-core-subscription-inventory.yml` hace 2 GET acotados de configuración, no consulta contactos ni consentimientos personales; sus resultados son pistas de configuración, no autorización para envío. Ver `docs/G6_ABH_CORE_SUBSCRIPTION_INVENTORY.md`.

**G6 (16/11/2026):** Salesforce Marketing Cloud Growth emplea Communication Subscription Consent para consentimientos de envío. Las señales locales `newsletter` y `optin` solo pueden bloquear, nunca autorizar. El componente `GrowthCommunicationConsentHoldGate` comprueba evidencia CSC por suscripción y scope a través de un callback read-only todavía NO conectado a Salesforce; incluso con opt-in positivo devuelve un candidato de revisión y mantiene `marketing_sends_authorized=false`. Fuente: `docs/G6_GROWTH_CSC_CONSENT_SUPPRESSION.md`. No escribir consentimiento directo en DMO ni convertirlo automáticamente desde PST.

**Avance observado 16/11/2026 — G4 completado en Salesforce:** el propietario mostró el esquema PST guardado, streams PSCustomer/PSOrder activos, mapeos LISTO y relación de pedidos N:1 a Individual. Referencia de evidencia: `config/g4-abh-tenant-ui-evidence.json`. Los párrafos históricos de G2/G3 más abajo son fotografías del estado anterior y **no deben reabrir esas tareas**. La fase actual combina **G5 bloqueado por el HTTP 202 anómalo** (solo diagnóstico de host sin POST ya superado) y **G6 desarrollo de consentimiento/supresión en local**, sin habilitar ingesta ni campañas. Ver `docs/G5_ABH_PST_SYNTHETIC_VALIDATION.md`.

## 1. Fuente de verdad

- Piloto: **ABH / Barcelona Confort**. El módulo instalado y verificado allí es **0.7.1 Test**. Este repositorio prepara **0.8.0 Growth-only**, **no instalado todavía**.
- Org Salesforce Core y aplicación OAuth existentes: autenticación certificada, **no volver a crear conexiones, pedir secretos ni cambiar scopes**.
- **Data Cloud conectado y probado para intercambio de token sin escritura**, conector `ABH_PrestaShop_Data_Cloud` (PST). Se observó `Schema Required`. No hay stream, DMO ni ingesta aprobados.
- BC: `PS_SFMC_MODE=test`, consentimiento local `marketing_optin`, abandono 60 minutos. La auditoría de newsletter contiene contradicciones y no define audiencia autorizada.
- Identidad de instalación/tienda/entidad, consentimiento por finalidad, bajas, retención y fechas locales `Europe/Madrid`: **G3 pendiente de aprobación**. Los históricos sin UTC verificado no son `Record Modified`.

## 2. Producto nuevo

- Solo un provider: `marketing_cloud_growth`. Núcleo genérico por comercio, instalación y tienda; nunca agrupar por email ni compartir un namespace entre instalaciones.
- Código de producto en `pssalesforcebridge`: PrestaShop 0.8.0 preparado en rama, sin rutas ni credenciales del antiguo transporte.
- Mantener la **reversibilidad sin pérdida de datos**: una actualización desde 0.7.1 conserva tablas históricas y configuraciones; no hace `DROP`, no reenvía eventos antiguos y fuerza Test. La limpieza física de datos productivos exige backup y autorización aparte.
- Compatibilidad real PS 1.7/8/9 con CI exact-head y pruebas de actualización; no confundir lint, token o preview con ejecución end-to-end.

## 3. Aceptación del propietario y puertas de entrega

**15/11/2026 — aceptación reiterada del propietario:** el usuario ha confirmado expresamente que los tres grupos G3 ya están aceptados. Se registra en `config/g3-owner-acceptance.json`. **No volver a pedir su aceptación general de esos tres grupos ni repetir los diagnósticos resueltos.** Avanzar directamente con la preparación técnica G4, usando v0.4 tipada para el preview offline. La declaración del propietario no inventa firmas externas, base jurídica individual por campo, mapeo DMO observado o preview realizado en Salesforce. Estos hechos solo se pueden marcar verificados con evidencia independiente. No inferir permiso para escrituras, ingesta ni envíos.

## 3. Puertas obligatorias, sin excepciones

**Sin aprobación específica y pruebas separadas**: NO publicar el esquema PST, crear Data Streams, guardar mapeos, ingerir clientes/pedidos, activar backfill, resolver identidad, enviar campañas, lanzar recovery, ni cambiar BC a Production. No revelar tokens/PII, ni considerar `newsletter=1` permiso de email. El código Growth actual no puede enviar.

**Secuencia real:** G0 producto → G1 Core OAuth → G2 Data Cloud token (certificados) → **G3 contrato de esquema/privacidad/relación (bloqueado)** → G4 streams/mapeos autorizados → G5 validación sintética Data Cloud → G6 identidad/consentimiento → G7 Flow/campaña Growth → G8 despliegue/golive certificado.

## 4. Modo de trabajo

Antes de cada PR: leer `AGENTS.md`, `config/project-contract.json`, `docs/PROJECT_DEVELOPMENT_RULES.md`, `ROADMAP.md`, `docs/ABH_GROWTH_CANONICAL_TARGET.md`; comprobar main/PRs/CI y el último gate. Hacer un corte funcional medible, con pruebas de fallo/cancelación y PHP 7.1+. PR exact-head, no fusionar rojo, comprobar push/base. **No generar ZIP ni pedir instalación** por cambios de documentación. Informar verificado vs pendiente sin sobreprometer. No reabrir la conexión Core/Data Cloud probada.

## 5. Contrato y trazabilidad

`config/project-contract.json` fija único proveedor, BC 0.7.1 observado, 0.8.0 candidato, y prohibiciones de ingesta/envío. `scripts/ci/verify-project-focus.py` rechaza regresar a doble proveedor, cambios de configuración engañosos o distribución de archivos ejecutables de la antigua integración. Las evidencias históricas del repositorio no son un roadmap vigente.
