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
2. **G5 Actions Test manual:** usa OAuth Core + intercambio token Data Cloud YA configurados, hace exactamente 2 llamadas `/actions/test` y registra únicamente código HTTP y nombres de objeto. No imprime el token, el host del tenant, el JSON de entrada, el cuerpo de error ni PII.

## Una ejecución manual, sin solicitar credenciales

Una vez fusionado el PR y con CI/runtime verde, abrir:

[**GitHub Actions · G5 PST Synthetic Salesforce Actions Test Only**](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/workflows/g5-pst-actions-test-only.yml)

Seleccionar **Run workflow → main → confirm_validation_only: yes_synthetic_actions_test_only → Run workflow**.

Se reutiliza el entorno protegido existente `salesforce-growth-certification` y sus variables/secretos. No abrir el archivo de secretos ni copiarlos al chat. El workflow **no se ejecuta automáticamente en push o PR**; la conexión de GitHub disponible en esta sesión no tiene acción para lanzar `workflow_dispatch`.

Resultado esperado:
- Job completo **success** si ambos endpoints devuelven HTTP 200 y no hay informe de errores.
- Artifact `g5-pst-actions-test-sanitized-...` con `PSCustomer` y `PSOrder`, código 200 y estado, sin datos privados.
- Si falla, status y código de error saneado. El cuerpo de validación puede contener datos y nunca se publica.

## Limitación deliberada y siguiente corte

**Pasar `/actions/test` no prueba ingesta real ni que la relación N:1 esté materializada con filas en Salesforce.** La comprobación de joins requiere registros posteriores y una autorización específica de ingreso sintético y borrado, separada de este workflow. En Barcelona Confort continúa 0.7.1 Test, Growth 0.8.0 sigue sin instalar y no hay campañas activas.
