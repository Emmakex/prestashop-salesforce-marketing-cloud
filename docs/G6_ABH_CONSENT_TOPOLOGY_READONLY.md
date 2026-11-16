# G6 — Topología de consentimiento, sin registros ni envíos

## Evidencia vigente (16/11/2026)

En ABH Marketing Cloud Growth está comprobada visualmente la suscripción **Marketing / Correo electrónico**. El inventario Core anterior identificó su nombre. El GET de metadatos del DMO estándar `ssot__CommunicationSubscriptionConsent__dlm` devolvió HTTP 200 y la clave primaria más 8 campos esperados.

En cambio, en Salesforce Data Cloud, espacio `default`, el propietario comprobó que:
- En el Explorador de datos, búsquedas `con` y `co` no ofrecen Communication Subscription Consent.
- En **Modelo de datos → Asignado**, las búsquedas `Consent` y `Subscription` muestran **0 elementos**.

**Una vista Asignado vacía NO demuestra que el DMO no exista ni que falten permisos.** El DMO estándar pertenece a la categoría Unassigned según Salesforce. No tenemos evidencia de asignaciones ni de registros individuales; no hay que repetir búsquedas ni crear un DMO.

## Incidencia del primer diagnóstico y corrección (16/11/2026)

La ejecución real [#43864960781](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/runs/43864960781) acabó **FAILURE** tras el éxito de los contratos sintéticos. El artefacto saneado registró exclusivamente `g6_topology_http_read_failed` (cURL devolvió false), sin una respuesta HTTP ni resultado parcial de los GET. El paso real consumió aproximadamente un timeout de 20 segundos. **No se conoce qué GET falló** ni puede atribuirse el problema a permisos, mapeos ausentes o inexistencia de transmisiones.

La corrección mantiene los **dos GET** y los ejecuta de forma independiente; si uno falla, el otro se sigue evaluando. Cada resultado registra `state` específico de mapeos o streams, HTTP de la lista segura (si lo hay), y una categoría saneada de transporte: `timeout`, `dns`, `connect`, `tls` u `other`. No se emite error bruto de cURL, URL, host, token ni respuesta privada. El runner distingue `diagnostic_observed_review_only` (ambas fuentes legibles), `diagnostic_partial_review_only` (una) y `diagnostic_unverified` (ninguna). Una lectura parcial **no certifica el consentimiento ni autoriza envíos**.

Se elimina el parámetro de ordenación innecesario del GET de streams y se mantiene la primera página de 200 registros de metadatos, con timeout limitado por petición y **sin reintentos**. El objetivo es diagnosticar sin inventar conclusiones sobre Salesforce.

## Corte técnico nuevo: dos GET de metadatos

El workflow manual protegido [G6 Consent Source Topology Read Only](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/workflows/g6-consent-source-topology.yml) reutiliza la conexión Salesforce Core ya existente, y realiza exactamente estas operaciones:

1. `GET /services/data/v68.0/ssot/data-model-object-mappings?dmoDeveloperName=ssot__CommunicationSubscriptionConsent__dlm&dataspace=default`: observa **cantidad visible de asignaciones** y número en estado ACTIVE, sin devolver nombres de DLO, mapeos, registros ni campos privados. HTTP 403/404 es **no verificado**, nunca cero.
2. `GET /services/data/v68.0/ssot/data-streams?limit=200&offset=0&orderBy=DeveloperName`: inspecciona solo la **primera página** de transmisiones (hasta 200), con cuatro categorías de nombre conocidas del kit de consentimiento Growth. Registra booleanos de presencia en esa página de `MessagingConsentV2-MessagingConsent`, `ConsentAuditTrailV2-ConsentAuditTrail`, `CommSubscription_<OrgID>` y `CommSubscriptionChannelType_<OrgID>`. No devuelve nombres del tenant ni identificadores de organización. Si no aparece un stream en la página limitada, **no se certifica ausencia**.

Se usan dos GET exactos y una salida saneada. No hay SOQL, contacto, email, consulta de registros CSC, acceso a personas, cambios de mapeos, cambios en consentimientos, envío ni ingesta. Los caminos del GET están fijados en el código, sin aceptar rutas del usuario ni seguir redirecciones. La clase se encuentra en `tests/review`, fuera del módulo PrestaShop instalable.

**Ejecución tras fusionar el PR:** GitHub Actions → **G6 Consent Source Topology Read Only** → Run workflow → main → `two_metadata_gets_no_records`.

## Cómo interpretar el resultado

- Mapeo visible y ACTIVE: **metadatos observados**, no confirma que exista un estado de opt-in, que el DMO contenga filas ni que el servicio de consentimiento lo respete al enviar.
- Mapeos no visibles o 403/404: la API no ha certificado asignación; no crearla automáticamente.
- Transmisión de `MessagingConsentV2` visible en la primera página: pista sobre el kit, no permiso para escribir ni reutilizar acciones de consentimiento antiguas.
- Sin transmisiones visibles en la página: resultado parcial que puede depender de paginación, permisos o filtros; no se declara que estén ausentes.
- Ningún resultado habilita `GrowthCommunicationConsentHoldGate` para producción: el callback de consentimiento por persona sigue simulado y mantiene todas las autorizaciones de envío a false.

## Restricción de arquitectura: usar métodos de consentimiento admitidos

Salesforce explica para Marketing Cloud Growth/Next que escribir directamente datos externos al DMO CSC, o manipular directamente su DLO, **puede dejar desalineado el servicio de consentimiento utilizado en los envíos**. Las vías admitidas para crear/actualizar consentimiento son la gestión de consentimiento en la interfaz, importaciones compatibles o las acciones **Create Consent / Consent Request** en los tipos de Flow admitidos. No utilizaremos ni dispararemos acciones antiguas de MessagingConsent, aunque Salesforce tenga streams internos con esos nombres. Tampoco convertiremos automáticamente un `newsletter=1` en consentimiento para Growth.

Fuentes:
- https://help.salesforce.com/s/articleView?id=005316463&type=1
- https://help.salesforce.com/s/articleView?id=002722664&type=1
- https://developer.salesforce.com/docs/data/connectapi/references/spec?meta=type%3AOutput+D360+Node+Input
- https://developer.salesforce.com/docs/data/data-cloud-dmo-mapping/guide/c360dm-si-communicationsubscriptionconsentdmo-dmo.html

**Estado:** G4 completado, G5 sin nuevos POST por HTTP 202 no explicado, G6 lectura CSC por persona todavía no certificada. Barcelona Confort sigue con el módulo **0.7.1 Test** y no debe actualizarse por este corte.
