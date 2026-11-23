# Cierre operativo G8 0.9.6 — supervisión sin envíos

**Alcance:** beta protegida del conector PrestaShop → Salesforce Marketing Cloud Growth + Data Cloud. El propietario confirmó la instalación 0.9.6 en staging PrestaShop 9.1.5. No se repetirán las pruebas anteriores de OAuth y recepción individual cliente/pedido en DLO/DMO.

## 1. Desarmar el par anterior

En Back Office de staging, sección **Sincronización automática G8 · Staging**, pulsar **Desactivar autorización G8 de staging**. Esto pone la aprobación en off sin resetear G3, sin POST y sin eliminar recibos. La captura del 23/11 mostraba la autorización activa; no suponer que se haya desactivado hasta confirmar el administrador.

Tras la desactivación, se puede usar **Retirar selección de UAT G8** para despejar el selector sin borrar el ledger. Nunca rearmar o reenviar el par con resultado incierto.

## 2. Verificar cron protegido: solo lectura

Operaciones existentes del cron:

| Operación | Alcance | Uso ahora |
| --- | --- | --- |
| status | Responde blocked_test; no ingesta | Sí |
| inspect | Lee G3 de staging y devuelve contadores acotados (hasta 10) | Sí |
| dispatch, dispatch_pair | Puede iniciar ingesta real solo tras autorización exacta | NO para el par ambiguo |

Se añade **GitHub Actions → G8 Staging Cron Read-only Check**, manual y de solo lectura. No tiene schedule, no está conectado al transporte de ingesta y no admite comandos dispatch. La Action requiere que el administrador configure estos dos **Actions secrets**, nunca en chat, issues o logs:

- G8_STAGING_CRON_ENDPOINT: URL HTTPS del staging terminada en /module/pssalesforcebridge/cron. Sin token, sin parámetros ni fragmentos.
- G8_STAGING_CRON_TOKEN: valor secreto de staging de PS_SFMC_CRON_TOKEN.

Para ejecutar: Actions → G8 Staging Cron Read-only Check → Run workflow → status. Una vez validado, se puede usar inspect para revisar solo los recuentos G3. Si no hay secrets, la Action termina bloqueada sin tocar la tienda. Un error de contrato termina en rojo y nunca autoriza un envío.

**No confundir:** status / inspect verifican acceso y estado G3, no que Salesforce haya recibido datos ni que exista un programador automático. No habilitan la ingesta.

## 3. Estado real tras beta

La 0.9.6 implementa una ruta opcional de cron para un **par PSCustomer → PSOrder** de staging, con CAS de un intento, Data Cloud DLO y consentimiento local. No programa cron al actualizar, no procesa automáticamente todos los nuevos eventos de cualquier tienda y no es una certificación E2E productiva.

Queda pendiente, bajo decisión separada del responsable, implementar o habilitar el drenaje general de eventos actuales de G3, con policy/consentimiento revisado, autorización por tienda y un único cierre E2E de nuevos registros. Nunca reutilizar un intento G8 ambiguo. Sin campañas Growth activadas. Sin modificaciones en Barcelona Confort producción.

## 4. Entrega operativa 0.9.7: copiar el token sin SQL

Con G8 desactivada y en una tienda de staging **no multitienda**, instalar la actualización 0.9.7 **sin desinstalar**. La actualización no genera ni rota el token, no activa cron ni cambia G3/G7.

En Back Office → Configurar módulo → Sincronización automática G8 · Staging, el **superadministrador** verá el desplegable `Token protegido de cron para GitHub Actions (sin enviar)`. Pulsar `Mostrar token protegido para copiar`. Esta operación requiere el CSRF de AdminModules, Test, captura OFF y aprobación G8 en `off`; entrega el token existente en un campo de contraseña de solo lectura únicamente en esa respuesta POST, con cabeceras `no-store`. Copiarlo **directamente** al secreto `G8_STAGING_CRON_TOKEN` en GitHub, sin pegarlo en chats, capturas, ficheros ni issues.

En GitHub → Settings → Secrets and variables → Actions → New repository secret, guardar `G8_STAGING_CRON_TOKEN`; en un segundo secreto guardar `G8_STAGING_CRON_ENDPOINT` con el HTTPS limpio del staging que termina en `/module/pssalesforcebridge/cron`, sin token ni query.

Después ejecutar manualmente el workflow `G8 Staging Cron Read-only Check` con operación `status`, no `dispatch` ni `dispatch_pair`. La acción no transmite registros y solo valida el endpoint protegido. El secreto no se muestra más al recargar el Back Office.

**No reutilizar** el par UAT ambiguo ni habilitar envíos. Para entornos multitienda, este revelado está expresamente bloqueado por el token global heredado y requiere un diseño distinto por tienda.
