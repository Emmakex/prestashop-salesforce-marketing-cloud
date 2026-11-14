# AGENTS — PrestaShop ↔ Marketing Cloud Growth + Data Cloud (único producto)

**Decisión del propietario 14/11/2026:** se elimina la arquitectura multiproveedor. Este proyecto conecta tiendas PrestaShop **1.7.8.x, 8.x y 9.x exclusivamente** con Salesforce **Marketing Cloud Growth sobre Salesforce Core + Data Cloud**. No ofrecer otro destino, selector de proveedores, clientes SOAP, Data Extensions, Journey API ni rutas de fallback.

## 1. Fuente de verdad

- Piloto: **ABH / Barcelona Confort**. El módulo instalado y verificado allí es **0.7.1 Test**. Este repositorio prepara **0.8.0 Growth-only**, **no instalado todavía**.
- Org Salesforce Core y aplicación OAuth existentes: autenticación certificada, **no volver a crear conexiones, pedir secretos ni cambiar scopes**.
- **Data Cloud conectado y probado para intercambio de token sin escritura**, conector `ABH_PrestaShop_Data_Cloud` (PST). Se observó `Schema Required`. No hay stream, DMO ni ingesta aprobados.
- BC: `PS_SFMC_MODE=test`, consentimiento local `marketing_optin`, abandono 60 minutos. La auditoría de newsletter contiene contradicciones y no define audiencia autorizada.
- Identidad de instalación/tienda/entidad, consentimiento por finalidad, bajas, retención y fechas locales `Europe/Madrid`: **G3 pendiente de aprobación**. Los históricos sin UTC verificado no son `Record Modified`.

## 2. Producto nuevo

- Solo un provider: `marketing_cloud_growth`. Núcleo genérico por comercio, instalación y tienda; nunca agrupar por email ni compartir un namespace entre instalaciones.
- Código de producto en `pssalesforcebridge`: PrestaShop 0.8.0 preparado en rama, sin rutas ni credenciales del antiguo transporte.
- Mantener la **reversibilidad sin pérdida de datos**: una actualización desde 0.7.1 conserva tablas históricas y configuraciones; no hace `DROP`, no reenvía eventos antiguos y fuerza Test. La limpieza física de datos productivos exige backup y autorización aparte.
- Compatibilidad real PS 1.7/8/9 con CI exact-head y pruebas de actualización; no confundir lint, token o preview con ejecución end-to-end.

## 3. Puertas obligatorias, sin excepciones

**Sin aprobación específica y pruebas separadas**: NO publicar el esquema PST, crear Data Streams, guardar mapeos, ingerir clientes/pedidos, activar backfill, resolver identidad, enviar campañas, lanzar recovery, ni cambiar BC a Production. No revelar tokens/PII, ni considerar `newsletter=1` permiso de email. El código Growth actual no puede enviar.

**Secuencia real:** G0 producto → G1 Core OAuth → G2 Data Cloud token (certificados) → **G3 contrato de esquema/privacidad/relación (bloqueado)** → G4 streams/mapeos autorizados → G5 validación sintética Data Cloud → G6 identidad/consentimiento → G7 Flow/campaña Growth → G8 despliegue/golive certificado.

## 4. Modo de trabajo

Antes de cada PR: leer `AGENTS.md`, `config/project-contract.json`, `docs/PROJECT_DEVELOPMENT_RULES.md`, `ROADMAP.md`, `docs/ABH_GROWTH_CANONICAL_TARGET.md`; comprobar main/PRs/CI y el último gate. Hacer un corte funcional medible, con pruebas de fallo/cancelación y PHP 7.1+. PR exact-head, no fusionar rojo, comprobar push/base. **No generar ZIP ni pedir instalación** por cambios de documentación. Informar verificado vs pendiente sin sobreprometer. No reabrir la conexión Core/Data Cloud probada.

## 5. Contrato y trazabilidad

`config/project-contract.json` fija único proveedor, BC 0.7.1 observado, 0.8.0 candidato, y prohibiciones de ingesta/envío. `scripts/ci/verify-project-focus.py` rechaza regresar a doble proveedor, cambios de configuración engañosos o distribución de archivos ejecutables de la antigua integración. Las evidencias históricas del repositorio no son un roadmap vigente.
