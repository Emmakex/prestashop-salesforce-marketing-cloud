# G6 — Consentimiento Growth y supresión por suscripción

**Estado:** preparación read-only, sin hooks, cron, worker ni envío. PrestaShop Barcelona Confort sigue en 0.7.1 Test.

## Modelo real de Salesforce Marketing Cloud Growth

El estado de consentimiento que se debe respetar al enviar comunicaciones de Growth se administra mediante **Communication Subscription Consent (CSC)**. No debemos confundirlo con el DMO Individual, Contact Point Email, newsletter u optin de PrestaShop, ni con importar directamente un registro de consentimiento externo en un DMO. Salesforce indica que el envío comercial requiere su modelo de consentimiento nativo.

Fuentes oficiales del producto Growth/Marketing Cloud Next:

- https://help.salesforce.com/s/articleView?id=005316463&type=1
- https://help.salesforce.com/s/articleView?id=sf.mktg_consent_considerations.htm&type=5
- https://help.salesforce.com/s/articleView?id=sf.mktg_consent_data.htm&type=5
- https://help.salesforce.com/s/articleView?id=platform.flow_ref_elements_mktg_consent_request.htm&type=5

## Implementación

El nuevo componente PHP GrowthCommunicationConsentHoldGate une dos pruebas independientes sin tráfico externo:

1. **Estado local PrestaShop:** lectura acotada por tienda y cliente de los campos active, deleted, is_guest, newsletter, optin, sin email ni PII. Cualquier baja, cuenta inactiva/borrada/guest, fuente corrupta o error bloquea **antes de solicitar evidencia CSC**. Un newsletter=1 jamás demuestra consentimiento Growth.
2. **Snapshot CSC (read-only, hoy simulado):** callback inyectado para un único contact point por namespace/tienda/cliente, un Communication Subscription específico y canal email. Verifica alcance, estructura, fecha de observación UTC (máximo 5 minutos de antigüedad, tolerancia futura 30 s), estado opt_in u opt_out, origen CSC. Falta de registro, conflicto/duplicados, baja remota, campo inesperado, otra tienda/suscripción/canal, fecha inválida o error bloquean.

Incluso un CSC positivo y actual produce **review_candidate_only**, nunca autorización para ingresar o enviar, porque aún no hay adaptador de lectura CSC certificado en ABH ni barrera transaccional de exclusión previa al despacho. En todos los resultados son false las banderas salesforce_csc_reader_certified_for_bc, salesforce_send_time_consent_certified, external_suppression_sources_complete, final_atomic_exclusion_fence_ready, data_cloud_ingestion_authorized y marketing_sends_authorized. La respuesta no expone email ni IDs.

El método inspectPrestaShop ejecuta SELECT con Db::executeS(sql, true, false), sin caché. No existe una conexión nueva a Salesforce ni autorización por los flags PrestaShop.

## Pruebas

Ejecutar **php tests/g6-growth-csc-suppression-contract.php**: comprueba opt-in/opt-out, ausencia/duplicados, lectura fallida, scope entre tiendas, suscripción distinta, fuente incorrecta, estado desconocido, fechas imposibles/caducadas, PII extra, negativas locales, cuenta ausente y lectura PrestaShop sin caché. CI PHP 7.1/8.5 y matriz de runtime PrestaShop 1.7/8/9.

## Siguiente paso

Identificar una **Communication Subscription real** en Growth para ABH y comprobar el estado actual de consentimiento y su vía de actualización soportada en Salesforce. Implementar el adaptador de lectura con evidencia actual y la barrera final de exclusión en el futuro envío de G7, sin trasladar consentimiento de newsletter por inferencia. G5 sigue aislado por la anomalía HTTP 202; su prueba no debe relanzarse hasta resolverla. BC se mantiene 0.7.1 Test.
