# G6 — vínculo técnico de Marketing / Email en Salesforce Core

**16/11/2026.** Siguiente corte operativo después de las pruebas del lector CSC y de los dos GET de topología. El último diagnóstico G6 real [#43866286020](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/runs/43866286020) observó `404` para la consulta de mapping CSC y un `timeout` para la página de streams. La respuesta 404 no certifica ausencia del DMO; su esquema ya había respondido 200. El envío, el onboarding de PSCart y G5 siguen bloqueados.

## Operación distinta, sobre Core, sin PII ni registros de consentimiento

Con la evidencia ya existente de la UI **Marketing → Suscripciones: Marketing / Correo electrónico**, este corte prepara una comprobación de la **asociación técnica** de configuración en Core. No toca Data Cloud DLOs/DMOs, no reintenta los dos GET problemáticos y tampoco registra identidades de clientes.

**Solo 1 GET**, SOQL inmutable y limitada a dos filas:

```soql
SELECT CommunicationSubscriptionId,
       CommunicationSubscription.Name,
       EngagementChannelType.ContactPointType
FROM CommSubscriptionChannelType
WHERE CommunicationSubscription.Name = 'Marketing'
  AND EngagementChannelType.ContactPointType = 'Email'
LIMIT 2
```

Salesforce documenta `CommSubscriptionChannelType.CommunicationSubscriptionId` y la referencia `EngagementChannelType`, además de `EngagementChannelType.ContactPointType` con valor `Email`. Las relaciones a Core son **configuración de suscripción/canal**, no consentimiento de una persona. Fuentes:

- https://developer.salesforce.com/docs/platform/object-reference/references/standard-objects/object-commsubscriptionchanneltype.html
- https://developer.salesforce.com/docs/platform/object-reference/references/standard-objects/object-engagementchanneltype.html
- https://help.salesforce.com/s/articleView?id=mktg.mktg_consent_tools_ref.htm&type=5

`G6MarketingEmailCoreBindingReview` comprueba conexión TLS, URL en dominio Salesforce, scope exacto, tipo de respuesta, `done=true`, cardinalidad y asociación anidada. Una única fila con relación correcta devuelve `marketing_email_core_binding_observed_review_only`. Cero filas es `not_observed`; más de una es **ambiguo**; cualquier 4xx, timeout, error de permisos o forma no verificada bloquea. No se infiere que una fila de otra unidad de negocio esté dentro de ABH.

El reporte solo contiene estados predefinidos y booleanos: **no emite `CommunicationSubscriptionId`, host, token, SOQL, email ni valores arbitrarios**. Un resultado favorable sigue `hold_for_review`, `csc_effective_consent_certified=false`, `business_unit_scope_certified=false` y `marketing_sends_authorized=false`.

## Ejecutar manualmente después de CI + runtime y merge

Ir a [G6 Marketing Email Core Binding Read Only](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/workflows/g6-marketing-email-core-binding-readonly.yml). Seleccionar **Run workflow → main → `marketing_email_config_get_only`**. El job comprueba repositorio, SHA y `workflow_dispatch`, reutiliza las credenciales OAuth Core protegidas y genera un artefacto JSON saneado, sin datos personales y sin escritura. No modificar las credenciales ni intentar comandos directos.

**Condición de cierre G6:** este paso verifica como máximo una asociación de configuración. Faltan el ámbito de Business Unit y un método admitido de consulta del consentimiento CSC por destinatario y finalidad, con revalidación de envío. Nunca convertir `newsletter` / `optin` en autorización Growth.

**Frontera vigente:** Barcelona Confort en **0.7.1 Test**, 0.8.0 no instalada, G5 `/actions/test` 202 sin resolver y ningún Salesforce POST/Flow/campaña autorizado.
