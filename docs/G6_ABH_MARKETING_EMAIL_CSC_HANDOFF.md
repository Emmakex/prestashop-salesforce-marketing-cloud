# G6 — Identificación de Marketing email y siguiente metadato CSC (ABH)

**Evidencia de la interfaz (16/11/2026):** en Marketing Cloud Growth de ABH, ruta `Marketing → Consentimiento → Suscripciones`, aparece exactamente **Marketing** con canal **Correo electrónico**. Última modificación visible 16/07/2026. La lectura del inventario de configuración Core [#43859152306](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/runs/43859152306) encontró un registro y el nombre `Marketing` de forma completa. Los dos resultados se corroboran. Referencia estructurada: [config/g6-abh-marketing-email-ui-evidence.json](../config/g6-abh-marketing-email-ui-evidence.json).

**Estado alcanzado:** suscripción y canal Growth confirmados **visualmente**. No se ha visto su ID técnico, Business Unit, consentimiento de clientes, reglas de identidad, ni estado de opt-in/opt-out. La pestaña no muestra audiencias ni habilitación de envío.

## Qué falta para integrar el gate G6

Salesforce Marketing Cloud Growth guarda el consentimiento efectivo en el DMO **Communication Subscription Consent**, no en el booleano de newsletter de PrestaShop. El DMO documentado es `ssot__CommunicationSubscriptionConsent__dlm`, que relaciona suscripción, estado de consentimiento, punto de contacto, canal y la persona. La presencia de campos no demuestra que existan registros ni que se puedan utilizar para un envío.

Referencias oficiales:
- https://help.salesforce.com/s/articleView?id=sf.mktg_consent_data.htm&type=5
- https://developer.salesforce.com/docs/data/data-cloud-dmo-mapping/guide/c360dm-communication-subscription-consent-dmo.html
- https://help.salesforce.com/s/articleView?id=005316463&type=1

## Comprobación técnica aislada

El nuevo `G6CscDmoMetadataProbe` está **fuera del módulo PrestaShop instalable**. Reutiliza OAuth Core protegido y hace **un único GET de metadatos** a `/services/data/v68.0/ssot/data-model-objects/ssot__CommunicationSubscriptionConsent__dlm`. Comprueba la clave primaria y ocho nombres de campo publicados, sin leer filas, email, personas, IDs privados ni valores de consentimiento.

Workflow manual para después del merge:

[GitHub Actions — G6 CSC DMO Schema Metadata Read Only](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/workflows/g6-csc-dmo-metadata.yml)

Ejecutar **Run workflow → main → mode: metadata_get_no_records → Run workflow**. No crea ni envía mensajes, no modifica objetos ni configuración, no utiliza PST Ingestion API y no toca Barcelona Confort. El artifact exporta exclusivamente presencia de nombres de campo de la documentación pública y booleanos de seguridad. Ante 401/403/404 o error de estructura, la respuesta es *metadata_unverified* o *metadata_invalid*: no equivale a ausencia de consentimientos.

## Siguiente fase después del metadato

Verificar la identidad efectiva de la suscripción Marketing (ID/binding técnico, canal y ámbito ABH) y determinar un método admitido de lectura **actual** por contacto y canal en Salesforce, con consentimiento de la fuente y permisos apropiados. Solo entonces conectar el callback de `GrowthCommunicationConsentHoldGate`. Con independencia del resultado, la verificación local sigue siendo *review_candidate_only* y **no** autoriza ingesta, importaciones, Flow ni campañas. G5 permanece bloqueado para POST después del HTTP 202; BC continúa 0.7.1 Test.
