# G6 — vínculo Core de suscripción Marketing con un tipo de canal

**16/11/2026 · Solo lectura.** La UI de Growth ya mostró **Marketing / Correo electrónico**. El diagnóstico de topología G6 [#43866286020](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/runs/43866286020) registró mapping CSC HTTP 404 y stream timeout. No inferir ausencia del DMO, reintentar esos GET ni consultar consentimientos de personas.

## Objetivo de esta pieza

Verificar que Salesforce Core contiene un vínculo de configuración entre la suscripción **Marketing** y al menos un tipo de canal asociado. La consulta Core **no** vuelve a verificar el tipo de canal *Email* de forma técnica: esa evidencia sigue siendo visual en Growth. Tampoco demuestra ámbito Business Unit ni opt-in de destinatarios.

Se ejecuta **un GET** a `/services/data/v68.0/query/?q=...` con SOQL fija:

```sql
SELECT Id, CommunicationSubscriptionId, CommunicationSubscription.Name
FROM CommSubscriptionChannelType
WHERE CommunicationSubscription.Name = 'Marketing'
LIMIT 2
```

Fuente oficial: [CommSubscriptionChannelType](https://developer.salesforce.com/docs/platform/object-reference/references/standard-objects/object-commsubscriptionchanneltype.html).

El componente `tests/review/G6MarketingEmailCoreBindingReview.php` valida dominio HTTPS oficial, cuerpo acotado, respuesta íntegra, exactamente una relación y formato de IDs **solo en memoria**. Si hay cero, más de una, HTTP anómalo, timeout, error de permisos o estructura inesperada, responde con un estado de revisión no verificado. El resultado jamás expone IDs, URLs, tokens o filas.

Aun con un vínculo único, conserva `hold_for_review`, `email_channel_type_technically_verified=false`, `business_unit_scope_certified=false`, `csc_effective_consent_certified=false`, `marketing_sends_authorized=false` y `data_cloud_ingestion_authorized=false`. No existe envío, consentimiento por contacto ni lectura de cliente.

## Uso del workflow

Tras fusionar el PR con CI exact-head verde y runtime 1.7/8/9 verde: [G6 Marketing Email Core Binding Read Only](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/workflows/g6-marketing-email-core-binding-readonly.yml) → **Run workflow** → rama **main** → `marketing_email_config_get_only`.

La ejecución protegida usa OAuth Core ya conectado, un único GET acotado y publica solo flags de diagnóstico. No requiere acceso SQL ni nuevas credenciales. Nunca cambia la instalación de Barcelona Confort.

## Faltante para G6

Acreditar el vínculo del canal exacto y del ámbito de la suscripción en el tenant, y después disponer de un método de verificación del consentimiento **efectivo** por finalidad, contacto y canal que corresponda con Growth. No convertir `newsletter` ni `optin` en permiso de envío. G5 HTTP 202 sigue bloqueado para POST, y Barcelona Confort permanece **0.7.1 Test**.
