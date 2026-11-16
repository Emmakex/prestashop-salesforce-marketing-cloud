# Puente PrestaShop ↔ Salesforce Marketing Cloud Growth + Data Cloud

**Producto único:** integración reusable para **PrestaShop 1.7.8.x, 8.x y 9.x** con **Salesforce Marketing Cloud Growth (Next)** y **Data Cloud**. No hay selección de proveedor, transporte alternativo ni motor de campañas propio. Las reglas vinculantes están en [AGENTS.md](AGENTS.md), [normas de desarrollo](docs/PROJECT_DEVELOPMENT_RULES.md), [contrato](config/project-contract.json) y [roadmap](ROADMAP.md).

## Estado verificado y objetivo de negocio

El piloto es **ABH / Barcelona Confort**. OAuth de Salesforce Core y el intercambio de token Data Cloud **están certificados sin escrituras**; el conector **PST** (`ABH_PrestaShop_Data_Cloud`) existe y se observó con `Schema Required`. Barcelona Confort mantiene el módulo **0.7.1 Test**, consentimiento local `marketing_optin` y abandono 60 minutos. No se ha certificado una campaña Growth ni ingesta real.

El objetivo es llevar clientes, pedidos y señales de carrito **con permisos correctos** a Data Cloud, para activar **segmentos, campañas y Flows de Growth** y detener la recuperación cuando aparece una compra. Los identificadores deben tener namespace de instalación/tienda, consentimiento y supresión por finalidad y control de actualización/reintentos.

## Módulo fuente 0.8.0 (Growth-only)

Se ha retirado del código empaquetado el transporte anterior, sus writers, colas, endpoints de recuperación y workers. Esta versión **no está instalada en BC** y no hereda permiso de envío alguno. Incluye:

- Registro de tres hooks estándar de cliente y pedido; actualmente ejecutan **preflight sin persistencia**, sin tocar datos personales ni hacer peticiones a Salesforce.
- Back Office exclusivamente Growth + Data Cloud con revisión de metadata/timezone local; fuente PST y modo Test visibles, sin campos de otro producto.
- Health y cron protegidos: reportan Test/bloqueo de transmisión; ninguna llamada remota, ningún backfill ni envío.
- Upgrade `0.7.1 → 0.8.0` **no destructivo**: fuerza Test y no borra tablas, tokens, configuraciones ni datos históricos. El uninstall tampoco borra tablas preexistentes; su limpieza requeriría un proceso independiente aprobado.

**Importante:** `0.8.0` es una refactorización de arquitectura, **no el producto operativo end-to-end**. El siguiente incremento funcional implementará la cola Growth duradera y el mapeo de PST después de superar los gates. No se ha aprobado escribir ni enviar datos reales.

**G4 · YAML listo para cargar en PST:** [esquema ABH v0.4](schemas/ABH_PST_G4_SCHEMA_UPLOAD.yaml) y [procedimiento en Salesforce](docs/G4_PST_SCHEMA_PUBLISH_RUNBOOK.md). CI verifica la forma exacta de 22 campos y genera un artifact con SHA256. Esta certificación es offline: no implica Upload Schema, Deploy de streams, ingesta ni campañas.

## Aceptación del propietario y siguiente corte

El **15/11/2026** el propietario ha confirmado que acepta los tres puntos G3 y no desea nuevas rondas de la misma pregunta. [Registro de aceptación](config/g3-owner-acceptance.json). Pasamos a preparación G4 con el esquema tipado v0.4, que el CI convierte en un **artefacto OpenAPI de preview sin conexión a Salesforce**. Esa aceptación no se falsifica como firma independiente de privacidad/arquitectura, ni autoriza implícitamente publicación, ingesta o campañas. La instalación BC continúa en 0.7.1 Test.

**G4 — compatibilidad de payload:** el nuevo constructor `pssalesforcebridge/src/DataCloud/GrowthTypedPstPayloadBuilder.php` valida los registros tipados v0.4 y `scripts/ci/build-g4-pst-typed-preview.py` exige paridad del YAML con los payloads sintéticos de PHP para PrestaShop 1.7/8/9. El archivo OpenAPI es un contrato de **campos y tipos**; la semántica tipada de PK/FK se comprueba en el constructor (el esquema no codifica esa restricción). No se ha publicado en PST ni existe aún ingesta autorizada.
 
**Incidente G5 16/11/2026:** el primer `/actions/test` sintético de PSCustomer recibió 202 (no esperado); el workflow de validación está temporalmente bloqueado para POST y se ha convertido en diagnóstico read-only del host oficial del PST, sin peticiones de datos. [Diagnóstico G5](docs/G5_ABH_PST_SYNTHETIC_VALIDATION.md). No repetir el intento ni permitir ingesta/campañas.

**Estado en Salesforce a 16/11/2026:** G4 estructural finalizado (PST + dos streams activos + dos mapeos LISTO + relación `PSOrder → Individual` N:1). Evidencia [UI G4](config/g4-abh-tenant-ui-evidence.json). **Siguiente paso: G5 sintético no persistente** mediante [GitHub Actions `/actions/test` manual](docs/G5_ABH_PST_SYNTHETIC_VALIDATION.md), reutilizando las credenciales protegidas existentes. No instalar todavía 0.8.0 en Barcelona Confort ni activar ingesta/envíos.

**G6 · Sin envíos:** [control read-only de consentimiento y supresión](docs/G6_GROWTH_CSC_CONSENT_SUPPRESSION.md). Salesforce Growth usa Communication Subscription Consent, no los flags `newsletter`/`optin` como permiso de campaña. El nuevo componente comprueba scope/frescura de evidencia CSC inyectada, da prioridad a las bajas y no habilita envíos, incluso con una señal positiva sintética. G5 continúa sin POST por HTTP 202 no aclarado.

**Siguiente corte G6:** inventario protegido de suscripciones Core con **dos GET de configuración, sin datos personales**, y confirmación separada del canal Email en Growth. Ver [G6 inventario de suscripciones](docs/G6_ABH_CORE_SUBSCRIPTION_INVENTORY.md). No ejecuta G5, que sigue bloqueado para POST, ni habilita marketing.

**G6, evidencia 16/11/2026:** la interfaz ABH Growth muestra una suscripción **Marketing** con canal **Correo electrónico**. Se añade una comprobación protegida de metadatos del DMO CSC, exclusivamente GET, sin contactos ni envíos. [Evidencia y procedimiento G6](docs/G6_ABH_MARKETING_EMAIL_CSC_HANDOFF.md). G5 sigue en cuarentena para POST y BC 0.7.1 Test no se modifica.

## Puertas de entrega

| Paso | Estado |
| --- | --- |
| G0 producto Growth + Data Cloud | Confirmado |
| G1 Salesforce Core OAuth | Probado, sin escritura |
| G2 token exchange Data Cloud | Probado, sin escritura |
| G3 esquema PSCustomer/PSOrder, privacidad, namespace, retención, mapeo | **Pendiente de conformidad** |
| G4 publicar PST / Data Streams | No autorizado |
| G5 pruebas sintéticas Data Cloud | Pendiente |
| G6 consentimiento y supresión | Pendiente |
| G7 campañas y Flow Growth | Pendiente |
| G8 rollout de BC y recuperación end-to-end | Pendiente |

El diseño G3 v0.2 de 22 campos es **un candidato técnico**, no licencia para publicar; ver [issue #45](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/issues/45). No inferir UTC de fechas locales, ni usar `newsletter` como consentimiento universal.

## Desarrollo y certificación

CI evalúa PHP >=7.1, contrato Growth-only, preflight/seguridad, PST y pruebas MySQL/MariaDB sintéticas. Runtime valida 1.7.8.11/8.2.8/9.1.5. No crear ZIP para BC ni tocar la instalación sin comprobación y autorización específicas. El historial de código anterior queda visible en Git pero **no se distribuye ni ejecuta**.
