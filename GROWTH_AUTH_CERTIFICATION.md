# Marketing Cloud Growth — certificación OAuth sin escrituras

La organización ABH utiliza Salesforce Marketing Cloud Growth (Marketing Cloud Next), no Marketing Cloud Engagement. No utilizar el workflow de SFMC Engagement ni sus tokens /v2/token ni sus Data Extensions/Journey API para Growth.

## Datos verificados

- Dominio Salesforce de ABH: https://alfabh.my.salesforce.com
- Endpoint OAuth: https://alfabh.my.salesforce.com/services/oauth2/token
- Aplicación cliente externa: ABH PrestaShop Marketing Cloud Growth
- Scope OAuth configurado: api
- Client Credentials activado
- Usuario Run As aprobado para esta prueba: marketing@alfabh.com
- Política: usuarios preautorizados por administrador

## Configuración segura en GitHub

En Emmakex/prestashop-salesforce-marketing-cloud → Settings → Environments, crear un entorno llamado **salesforce-growth-certification**.

- Variable del Environment: **SF_GROWTH_ORG_URL** = https://alfabh.my.salesforce.com
- Secret del Environment: **SF_GROWTH_CLIENT_ID** = Consumer Key de Salesforce
- Secret del Environment: **SF_GROWTH_CLIENT_SECRET** = Consumer Secret de Salesforce

No compartir, versionar, registrar ni adjuntar claves o tokens. No guardar las credenciales en el módulo 0.7.0 Engagement.

Ejecutar manualmente Actions → **Salesforce Growth OAuth Auth Only** → Run workflow → main. Antes, comprobar que el usuario Run As tiene permisos API y acceso a esta aplicación mediante perfil/conjunto de permisos cuando la política es preautorización de administrador.

El workflow efectúa únicamente una solicitud POST HTTPS de tipo client_credentials al endpoint /services/oauth2/token. No consulta ni modifica objetos, Data 360, contactos, pedidos ni campañas de Salesforce. El artefacto JSON se limita a status, flags, SHA, run ID y fechas; no contiene secretos ni respuestas OAuth.

Si el token falla, la evidencia recoge únicamente un identificador de diagnóstico saneado: estado HTTP y, si Salesforce lo devuelve, un código OAuth de una lista cerrada (`invalid_client`, `invalid_grant`, etc.). Nunca contiene `error_description`, cuerpo de respuesta, credenciales ni tokens. El resultado antiguo #42048133903 falló en autenticación, pero su evidencia genérica no permitió identificar el motivo. Verificar el dominio, los secretos, permisos del Run As y la asignación de acceso a la aplicación. No desactivar las restricciones como atajo.

## Frontera de certificación

Un auth success NO certifica la integración Marketing Cloud Growth. La selección de API de entrada (Salesforce REST/objetos frente a Data 360 Ingestion), consentimientos, identificación de contactos, automatizaciones de abandono, envío de correos y recuperación end-to-end siguen pendientes en issue #12.

Documentación Salesforce:
- https://help.salesforce.com/s/articleView?id=xcloud.remoteaccess_oauth_client_credentials_flow.htm&type=5
- https://help.salesforce.com/s/articleView?id=sf.preauth_user_app_access_through_eca.htm&type=5
