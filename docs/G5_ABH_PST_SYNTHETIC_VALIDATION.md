# G5 — Validación sintética ABH PST en Salesforce Data Cloud

**Estado en la rama G5:** G4 en tenant Salesforce ya completado con evidencia visual; ver `config/g4-abh-tenant-ui-evidence.json`. Son dos Data Streams activos en Data Space `default`, ambos mapeados `LISTO`, con relación `PST-PSOrder.customer_source_key` N:1 `Individual.Individual Id`. El propietario aprobó los tres puntos G3; no volver a pedir esas decisiones.

## Objetivo práctico

Ejecutar únicamente **validación síncrona de la Ingestion API**, sin guardar registros. La API oficial es:

`POST /api/v1/ingest/sources/ABH_PrestaShop_Data_Cloud/{PSCustomer|PSOrder}/actions/test`

Salesforce documenta que `/actions/test` **valida y no persiste registros en el lago de datos**. Se diferencia de la ingesta real `POST /api/v1/ingest/sources/.../{objeto}`, que devuelve `202 Accepted` y sí puede ingresar registros. Esta segunda ruta no está implementada ni autorizada aquí.

Documentación oficial:
- https://developer.salesforce.com/docs/data/data-cloud-int/references/data-cloud-ingestionapi-ref/c360-a-api-synchronous-record-validation.html
- https://developer.salesforce.com/docs/data/data-cloud-int/references/data-cloud-ingestionapi-ref/c360-a-api-streaming-ingestion.html

## Qué probará la ejecución

El mismo código que certificó el esquema v0.4 (PHP del módulo, sin conectar hooks reales) crea exactamente **dos filas sintéticas**:

- `PSCustomer`: email reservado `synthetic@fixture.example.invalid`, nombre Synthetic Fixture, namespace `growth_ci_abhtwo`, tienda `2`, ID `44`, PK `growth_ci_abhtwo:2:customer:44`.
- `PSOrder`: namespace `growth_ci_abhtwo`, tienda `2`, ID `44`, PK `growth_ci_abhtwo:2:order:44`, FK `growth_ci_abhtwo:2:customer:44`, `paid_flag=false`, EUR, sin inferencia de consentimiento.

Son 11 campos planos por objeto, con los nombres del esquema PST publicado, **no** nombres de la API del DLO terminados en `__c`. La validación local rechaza datos de clientes reales, mutaciones de identidad, más de una fila, objetos diferentes, endpoints arbitrarios, respuestas 202, errores y el uso sin bandera explícita.

El trabajo técnico de G5 se reparte en dos evidencias distintas:

1. **CI exact-head:** usa transporte simulado para demostrar contrato, payloads y rutas. Sin conexión al tenant.
2. **G5 Actions Test manual (en cuarentena por 202):** el antiguo workflow intentó validar, devolvió 202 en PSCustomer y se bloqueó. El dispatch actual permite **únicamente** comprobar el host con OAuth; sin payload y sin llamadas al endpoint de datos.

## Incidente HTTP 202 y modo seguro vigente (16/11/2026)

La ejecución manual [#43849592253](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/runs/43849592253) **NO pasó**. En el primer `/actions/test` (PSCustomer) se recibió **202 Accepted** y el ejecutor hizo fail-closed; PSOrder no se llegó a llamar. Salesforce documenta 200 como éxito de validación síncrona, y 202 como aceptación de una ingesta asíncrona. **No convalidar 202, no reintentar el POST**, no afirmar que se ingirió ningún cliente. En las capturas del propietario los dos DLO y los DLO de problemas mostraban 0 registros, pero su última actualización observada era anterior al workflow. Evidencia saneada en `config/g5-pst-incident-202.json`.

El archivo oficial *Object Endpoints* descargado del conector PST enumera `POST /PSCustomer` y `POST /PSOrder` con 202 para **upsert real**. No enumera `/actions/test` porque el YAML solo representa la ruta de ingesta. Un error 202 en la ruta de validación **sigue sin estar explicado**; el YAML no justifica aceptar el status.

## Siguiente acción permitida: attestation del host, sin datos ni solicitudes al endpoint

Se ha sustituido temporalmente el dispatch de validación por una comparación read-only del hostname. Abrir la [acción G5 PST Host Diagnostic (No Data)](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/workflows/g5-pst-actions-test-only.yml) después de integrar el PR correctivo; seleccionar:

**Run workflow → main → `action: diagnose_host_no_payload` → Run workflow**.

El job hace exclusivamente **Core OAuth + Data Cloud token exchange** (G1/G2 ya configurados), compara la URL normalizada del token de Data Cloud con el **SHA-256 del origen exacto** descargado del conector PST, y reconstruye por código las rutas estrictas con `/actions/test`. No hace HTTP POST de datos, no solicita al endpoint `/actions/test` y **no ejecuta ni siquiera una petición de validación**. No muestra ni almacena host, tokens, respuesta de Salesforce ni datos personales. El artifact `g5-pst-host-diagnostic-...` solo contiene `tenant_host_matches_official_pst_yaml: true/false`, `test_paths_verified_offline: true/false`, y códigos de estado saneados.

**Interpretación**:
- `host_matches=false`: posible discrepancia entre el tenant que devuelve OAuth y el origen del conector PST; no continuar, revisar la configuración de integración y permisos.
- `host_matches=true`: host coincide; queda una anomalía de enrutamiento/respuesta en Salesforce. No se arregla aceptando 202. El siguiente paso será un diagnóstico con soporte Salesforce y evidencia de respuesta **sin valores sensibles**, o un ensayo explícitamente aislado con aprobación adicional, no un nuevo POST a ciegas.
- Ambos resultados **mantienen G5 sin validar** hasta recibir evidencia real de 200 de `/actions/test`.

El antiguo ejecutor `tests/live/g5-pst-actions-test-only.php` se conserva para auditoría de contrato, pero el workflow manual ya **no lo invoca**, y el gate exige que no quede ningún modo de dispatch para hacer POST sintéticos mientras permanezca el incidente.

## Limitación deliberada y siguiente corte

**Pasar `/actions/test` no prueba ingesta real ni que la relación N:1 esté materializada con filas en Salesforce.** La comprobación de joins requiere registros posteriores y una autorización específica de ingreso sintético y borrado, separada de este workflow. En Barcelona Confort continúa 0.7.1 Test, Growth 0.8.0 sigue sin instalar y no hay campañas activas.
