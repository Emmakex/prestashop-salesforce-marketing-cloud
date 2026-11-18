## G6 — aceptación administrativa para continuar (18/11/2026)

**Decisión vigente del propietario:** el administrador de ABH ha confirmado que la configuración y validación de consentimiento ya se realizaron en la integración previa con el CRM. El propietario solicita **dar G6 por validado y seguir sin más rondas de verificación de pantallas**. Registramos **G6 como aceptado administrativamente para avanzar a G7**, sin repetir configuración de Marketing/Email, Business Unit ni pedir capturas de la página de preferencias. La evidencia anterior del tenant ya muestra suscripción `Marketing` única, canal `Email` y Consent Check promocional habilitado, además del vínculo técnico y objetos relacionados.

**Alcance explícito:** esta aceptación se basa en la confirmación del administrador transmitida por el propietario, no en haber observado aquí una prueba negativa de opt-out por cada contacto, su Business Unit, procedencia/legalidad de listas ni consentimiento actual para campañas nuevas. Se conserva `marketing_sends_authorized=false`, `data_cloud_ingestion_authorized=false` y las comprobaciones automáticas que evitan interpretar `newsletter/optin` de PrestaShop como consentimiento. Es correcto marcar el **hito de proyecto G6 como validado**, pero **no convertirlo en autorización de envío** ni certificar técnicamente una prueba no presenciada.

**Próximo trabajo:** avanzar G7 con la misma outbox G3, relectura nativa PSCustomer/PSOrder probada en PS9, lease/cancelación/reconciliación, selección de fuente y revalidación en el instante de envío. Mantener toda salida real en Test/bloqueada hasta un pilotaje G8 expresamente autorizado con política de contacto y bajas aplicable. BC producción 0.7.1 Test y staging 0.8.7 Test intactos. No instalar otro ZIP por esta decisión.

---

# G6 — ruta nativa y mínima de consentimiento de Marketing Cloud Growth

**16/11/2026 · Decisión técnica; no certificación del tenant.**

## Problema que se elimina

Hasta ahora G6 estaba esperando una lectura directa de consentimientos personales desde el DMO CSC antes de permitir cualquier Flow. Esa lectura puede servir para auditoría, pero **no sustituye la comprobación efectiva de consentimiento que hace Marketing Cloud Growth cuando va a enviar**. Una lectura de Data Cloud podría retrasarse frente a cambios de baja; construir otro motor de consentimiento en PrestaShop introduciría divergencias y una dependencia innecesaria.

**Elección: usar el Consent Check nativo obligatorio de Growth al enviar mensajes promocionales**, configurado para:

- Suscripción **Marketing** (observada en la UI de ABH).
- Canal **Email** (observado visualmente en esa suscripción).
- Propósito de mensaje **Promotional**.
- El propio motor de consentimiento de Growth determina la elegibilidad del *contact point*, no `newsletter` ni `optin` de PrestaShop.

Esto no sustituye los controles locales de carrito comprado, cuenta inactiva, bajas locales, exclusión, frecuencia, conversión y deduplicación, que permanecen en nuestro puente. El consentimiento remoto efectivo **no queda certificado por el vínculo Core GET** de G6 (run #43888866974).

## 1. Qué se exige antes de activar un solo envío

1. El administrador comprueba **Marketing → Consentimiento → Suscripciones → Marketing → Email** y el ámbito correcto en ABH. No crear una suscripción nueva por duplicado.
2. En **Salesforce Setup → Marketing / Consent Check**, confirmar que **los mensajes promocionales exigen consentimiento** y que el envío de la campaña/Flow usa la suscripción Marketing. La etiqueta exacta de Setup varía por edición.
3. Comprobar, usando solo un **contacto sintético o de prueba expresamente autorizado**, que sin consentimiento o con baja el Consent Check nativo impide el envío. Este procedimiento **NO** crea campañas ni dispara envíos desde el módulo; requiere aprobación aparte para la prueba de mensajes.
4. Acreditar las finalidades, bases legales, conservación, derechos de oposición y origen histórico antes de incorporar opt-ins de BC. Sin evidencia legal aprobada, **ningún cliente real es elegible**.
5. Completar una ruta **admitida por Salesforce** para incorporar consentimiento real; cualquier ausencia de consentimiento se interpreta como opt-out. No confundir conexión PST/mapeo DMO con esta etapa.

Hasta completar todos estos puntos, `native_send_time_check_verified_in_tenant=false` y `marketing_sends_authorized=false` en `config/g6-growth-native-consent-policy.json`. Estos flags **son un registro de política**, no la configuración real del tenant ni una llave de activación.

## 2. Cómo incorporar o actualizar consentimiento, cuando se autorice

- **Importación de consentimientos desde la pestaña Consentimiento de Growth** (CSV) después de validar finalidad y procedencia. No importar todos los clientes por el hecho de tener newsletter/optin activado.
- Para cambios futuros/eventos: **Consent Request** en un Flow de automatización o un **On-Demand Flow** que puede dispararse mediante REST, si el admin ha definido el flujo y el responsable de datos ha aprobado la señal; o **Create Consent** en un Flow disparado por Data Cloud.
- Preference Page y componente Consent Status también son métodos admitidos.

**Nunca** escribir directamente al DMO CSC ni al DLO de consentimiento por Ingestion API, ni establecer un mapeo de PST hacia el DMO CSC para simular una baja/alta. Salesforce explica que esas vías no actualizan necesariamente el servicio/caché nativo y pueden generar estados incompatibles con los envíos.

## 3. Lo que ya está hecho y lo que falta

**Hecho:** UI Marketing/Email, inventario Core, vínculo Core a un canal HTTP 200, código local de supresión y revisión de baja/final de carrito; módulo 0.8.0 Growth-only probado 1.7/8/9.

**Pendiente antes de certificar G6:** comprobar configuración **real** de Consent Check en el tenant ABH, su ámbito, un contacto autorizado con opt-in/opt-out y una ruta soportada para consentimientos con procedencia aprobada. El lector opcional `GrowthCommunicationConsentHoldGate` puede seguir bloqueando por motivos negativos, pero **no autoriza** envíos ni se conectará directamente como fuente de altas.

**Secuencia corta:** G5 validar transporte → G6 Consent Check nativo → G7 campaña/Flow Growth con exclusión local y outbox existente → G8 pilotaje y despliegue reversible. Los bloqueos G5/G6 se pueden verificar en paralelo sin realizar POST adicionales desde este repositorio.

## 4. Referencias de Salesforce

- [Understanding Consent Concepts](https://help.salesforce.com/s/articleView?id=mktg.mktg_consent_tools_ref.htm&language=en_US&type=5): el Consent Check revisa la suscripción y el contact point al enviar, y requiere opt-in en mensajes promocionales.
- [How Consent is Stored and Written (05/10/2026)](https://help.salesforce.com/s/articleView?id=005316463&language=en_US&type=1): métodos permitidos y riesgo de escribir directo en DMO/DLO.
- [Considerations for Managing Marketing Consent](https://help.salesforce.com/s/articleView?id=sf.mktg_consent_considerations.htm&language=en_US&type=5): ausencia de consentimiento es opt-out por defecto y CRM no se reconoce automáticamente.
- [Create Consent Flow Action](https://help.salesforce.com/s/articleView?id=platform.flow_ref_elements_mktg_consent_request.htm&language=en_US&type=5).
- [On-Demand Flows](https://help.salesforce.com/s/articleView?id=platform.flow_concepts_trigger_on_demand.htm&language=en_US&type=5).

**Frontera:** Barcelona Confort mantiene **0.7.1 Test**; **0.8.0 no instalada**; G5 `/actions/test` 202 en cuarentena; **0 llamadas de escritura a Salesforce y 0 campañas** desde esta decisión.
