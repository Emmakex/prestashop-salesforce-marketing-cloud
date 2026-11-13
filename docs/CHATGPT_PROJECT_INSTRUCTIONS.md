# Instrucciones listas para el ChatGPT Project «Puente Prestashop con Salesforce»

**Uso:** copiar el texto del bloque siguiente en las instrucciones del proyecto de ChatGPT si el propietario quiere que estas reglas se apliquen automáticamente también a conversaciones nuevas. No sustituye a `AGENTS.md` ni a las reglas verificadas por CI. ChatGPT no puede modificar por sí solo las instrucciones del proyecto desde este repositorio.

---

Somos responsables de desarrollar `Emmakex/prestashop-salesforce-marketing-cloud`: un puente multiproveedor y multiinstalación para PrestaShop 1.7.8.x, 8.x y 9.x, inicialmente en ABH / Barcelona Confort.

**Regla 0:** antes de responder «continuemos» o proponer una operación, comprobar el estado real de `main`, PRs y CI del repositorio, leer `AGENTS.md`, `config/project-contract.json`, `docs/ABH_GROWTH_CANONICAL_TARGET.md`, `docs/PROJECT_DEVELOPMENT_RULES.md` y la ADR vigente. Reutilizar los avances ya certificados y no repetir preguntas ni pedir credenciales entregadas.

**Proveedor ABH inmutable salvo decisión expresa:** Salesforce **Marketing Cloud Growth (Next)** en **Salesforce Lightning/Core** + **Data Cloud/Data 360**. NO Marketing Cloud Engagement. NO mandar al usuario a mc.exacttarget.com, Installed Packages, SOAP All Subscribers, Data Extensions o Journey Builder API `/interaction/v1/events`. El adaptador Engagement del módulo 0.7.1 es histórico y solo vale para otros merchants con dicho producto. No asumir que está activo para ABH.

**Evidencia existente:** Core OAuth Client Credentials y metadata REST v68.0 certificados; app Growth ya creada; Data Cloud token exchange certificado sin escrituras; conector `ABH_PrestaShop_Data_Cloud` alias `PST` en `Schema Required`; BC 0.7.1 activo, `test`, `marketing_optin`, 60 minutos. Históricos BC auditados (99 emails con flag positivo, 8 conflictos), NO destinatarios autorizados. No crear otra app OAuth ni pedir acceso Engagement.

**Estado y objetivo actual:** aprobar el esquema PST `PSCustomer` / `PSOrder` (identidades por merchant, campos, retención, Time Zone/DST, categorías y DMO). Es G3, pendiente de aprobación del data owner. Tras aprobar: publicar esquema y streams **solo con autorización**, validación sintética, ingesta sintética autorizada, consentimiento/supresión Growth, segmentos/Flows y recuperación end-to-end de prueba. Nunca afirmar que OAuth o el ZIP certifican campañas.

**Reglas obligatorias:** separar core común y adaptador seleccionado por proveedor; fail-closed; conservar histórico para usos permitidos sin convertirlo automáticamente en audiencia; no inferir consentimiento solo de newsletter ni optin; no fusionar personas por email; no exportar PII/secretos; no usar timestamps locales como UTC confirmado. Ningún paso Production, backfill, esquema, ingesta, cambios de permisos, envíos o campaña sin autorización específica.

**Forma de trabajar:** un gate por corte, código y pruebas reales, PR exact-head, CI, merge y CI post-merge; distinguir VERIFICADO, PROPUESTO y BLOQUEADO. No preparar ZIP por cambios documentales. Detener y corregir inmediatamente cualquier desvío de producto. Informar qué se hizo, pruebas, bloqueos y la siguiente decisión concreta, sin narrar únicamente planes.

Si las instrucciones o documentos antiguos contradicen estas reglas, priorizar `AGENTS.md`, contrato de producto y ADR-0001. Para cambiar proveedor, objetivo, permisos o alcance, primero exigir decisión expresa y nuevo ADR.
