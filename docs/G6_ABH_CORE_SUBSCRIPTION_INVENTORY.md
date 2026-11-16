# G6 — Inventario de suscripciones (Salesforce Core, solo lectura)

**Objetivo inmediato:** descubrir si la org Core de ABH expone el objeto estándar `CommSubscription`, cuántas suscripciones son visibles y si existe una configuración llamada exactamente `Marketing`. NO se examina el consentimiento de ningún cliente ni se activa una campaña.

## Distinción importante: Core no equivale a Growth consent

- El objeto Core `CommSubscription` es una fuente posible de configuración, con campos estándar `Name` y, cuando Salesforce lo permite, `IsDefault`. Su consulta NO certifica que esa suscripción esté habilitada en Marketing Cloud Growth.
- La fuente de consentimiento de envío de Marketing Cloud Growth es **Communication Subscription Consent DMO**, en Data Cloud, con canal, contacto y suscripción. El envío aplica controles a través del servicio y caché de consentimiento de Salesforce. No basta tener un registro en un DMO ni newsletter=1.
- El método canónico para confirmar **la suscripción comercial real y su canal** es Marketing → **Consent → Preference Pages and Subscriptions → Subscriptions**. Verificar el nombre y canal Email allí, sin crear suscripciones nuevas.

Fuentes Salesforce:
- https://developer.salesforce.com/docs/platform/object-reference/references/standard-objects/object-commsubscription.html
- https://developer.salesforce.com/docs/platform/field-reference/references/field-reference/salesforce-field-reference-commsubscription.html
- https://help.salesforce.com/s/articleView?id=mktg.mktg_consent_comm_sub_create.htm&type=5
- https://help.salesforce.com/s/articleView?id=005316463&type=1
- https://partnerworkshops.salesforce.com/workshops/marketing-cloud-next/consent.html

## Primera comprobación técnica en el tenant, sin clientes

Tras fusionar el PR y pasar CI + PrestaShop runtime, abrir [G6 Core Subscription Inventory Read Only](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/workflows/g6-core-subscription-inventory.yml) y seleccionar **Run workflow → main → readonly_core_config_only**.

El script, con las mismas credenciales OAuth Core protegidas que ya existen, hace como máximo dos **GET**:
1. `GET /services/data/v68.0/sobjects/CommSubscription/describe`, verifica que `Name` existe y si `IsDefault` está disponible. Sin exponer la lista de campos.
2. `GET /services/data/v68.0/query/?q=SELECT ... FROM CommSubscription LIMIT 20` — únicamente `Name` y opcionalmente `IsDefault`, jamás valores o IDs de consentimiento y nunca personas ni emails.

El informe se limita a cifras, estado de cobertura, booleano `marketing_name_seen_in_bounded_page` y cantidad de defaults si el campo existe; **no contiene nombres arbitrarios, IDs, URL, host, tokens, headers ni respuestas brutas**. Si la API devuelve 403/404, JSON corrupto, lista incompleta o error, no declara inexistencia ni confirma Growth. El workflow falla de forma segura ante inventario incompleto.

## Resultado y siguiente acción

- Si el Core catalog devuelve `Marketing`, comprobar en **Marketing → Consent → Subscriptions** que realmente existe la suscripción para ABH y que tiene canal Email. No asumir coincidencia por nombre si hay distintas Business Units.
- Si no devuelve `Marketing`, o si el inventario no es accesible, revisar únicamente la pantalla Consent; no crear más apps ni cambiar scopes.
- Una vez confirmada la suscripción, preparar una lectura de consentimiento efectiva y verificación por destinatario para el gate `GrowthCommunicationConsentHoldGate`. El callback actual solo está simulado, no conectado a Salesforce.
- G5 continúa en cuarentena sin POST por el incidente HTTP 202; **este inventario no llama a Ingestion API**. Barcelona Confort sigue con 0.7.1 Test, sin ingesta ni envíos.

## Pruebas locales

`php tests/g6-core-subscription-inventory-contract.php` cubre GET allowlist, response redaction, list truncation, 403/404 diferenciados, esquema de metadatos, conversiones inválidas y URL externa. CI PHP 7.1 y 8.5, runtime PrestaShop 1.7/8/9.
