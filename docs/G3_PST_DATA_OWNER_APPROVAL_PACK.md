# G3 · Dossier para aprobar PSCustomer / PSOrder — ABH (PST)

**Estado: PENDIENTE DE APROBACIÓN. Uso: revisión humana y pruebas offline únicamente.** Fecha: 13/11/2026. Proyecto multiproveedor PrestaShop → **Marketing Cloud Growth + Data 360**, no Engagement.

## Qué está verificado y qué no

- **Verificado:** Salesforce Core OAuth, exchange Data 360 sin escrituras, fuente PST (`ABH_PrestaShop_Data_Cloud`) observada en estado **Schema Required**, Data Space observado `default`, BC módulo 0.7.1 activo en `test`, tienda `Europe/Madrid`.
- **Verificado en código:** YAML 0.2 `schemas/ABH_PST_SCHEMA_REVIEW.yaml` (11 + 11 campos); YAML v0.3 `schemas/ABH_PST_SCHEMA_UTC_CANDIDATE.yaml` (12 + 12, con fecha UTC). El mapper de v0.3 es offline y no está conectado al worker.
- **Sin verificar/aprobar:** finalización de campos, finalidad y retención, fuente de verdad de UTC histórico, namespace definitivo y unicidad global, categorías/PK, refresh, Record Modified, mapeo DLO→DMO, bajas y permisos de envío. Ninguno de los dos YAML se puede subir aún.
- **No confundir:** el historial de BC conserva 99 emails con algún indicador positivo, pero no constituye una audiencia Growth autorizada.

### Preflight de BC confirmado sin acceso SQL del usuario

Captura del Back Office del módulo 0.7.1, 13/11/2026: tienda **1**,
`PS_TIMEZONE=Europe/Madrid`, **3/3 controles locales PASS**,
`customer.date_add/date_upd` y `orders.date_add/date_upd`
como `DATETIME`. El panel permanece **BLOCKED — review only** por
diseño. Esto certifica **metadatos actuales**, NO la zona horaria
histórica usada al escribir cada fila, la legalidad de envíos,
la aprobación de UTC v0.3 ni los Data Streams.

El responsable no tiene acceso SQL directo a producción; no se le debe
volver a exigir phpMyAdmin. Evidencia detallada y opción SQL
solo para DBA autorizado en
[`BC_G3_TIMESTAMP_PROVENANCE_AUDIT.md`](BC_G3_TIMESTAMP_PROVENANCE_AUDIT.md).
La auditoría de fechas históricas queda pendiente; el avance técnico
se centrará en capturas futuras con UTC fiable y separación explícita
de históricos de procedencia desconocida.

## Inventario completo y clasificación

Este inventario procede exactamente de los dos YAML del repositorio y del mapper. El fichero `config/g3-pst-schema-review.json` lo mantiene sincronizado mediante CI y marca las decisiones como propuestas. Ningún campo aparece como autorización comercial por defecto.

### PSCustomer (12 campos propuestos)

| Campo | Versión | Clasificación | Motivo de revisión |
|---|---|---|---|
| `source_key` | v0.2 y v0.3 | Identificador | Proposed PK; must be globally stable by installation/shop/customer. Never dedupe by email. |
| `source_namespace` | v0.2 y v0.3 | Aislamiento | Proposed immutable merchant installation namespace; registry and format not signed off. |
| `shop_id` | v0.2 y v0.3 | ID fuente | Shop-scoped PrestaShop ID, not globally unique. |
| `customer_id` | v0.2 y v0.3 | ID fuente | Per-shop source ID; never a Salesforce ID. |
| `email` | v0.2 y v0.3 | Dato personal | Optional in source schema; lawful purpose, contact suppression, retention and mapping need approval. |
| `first_name` | v0.2 y v0.3 | Dato personal | Review necessity/minimization before any upload. |
| `last_name` | v0.2 y v0.3 | Dato personal | Review necessity/minimization before any upload. |
| `newsletter_flag` | v0.2 y v0.3 | Señal comercial | PrestaShop source flag is not permission to send; purpose, provenance and Growth suppression unresolved. |
| `partner_optin_flag` | v0.2 y v0.3 | Señal comercial | Partner offers flag; never infer first-party cart recovery marketing consent. |
| `active_flag` | v0.2 y v0.3 | Estado fuente | PrestaShop customer active, not Salesforce campaign activation. |
| `source_updated_local` | v0.2 y v0.3 | Tiempo ambiguo | Timezone and DST provenance unknown; TEXT only, never Record Modified. |
| `source_updated_utc` | Solo v0.3 | Fecha candidata | v0.3 only; historical offset and DST must be audited before conversion/Record Modified. |

### PSOrder (12 campos propuestos)

| Campo | Versión | Clasificación | Motivo de revisión |
|---|---|---|---|
| `source_key` | v0.2 y v0.3 | Identificador | Proposed PK; installation + shop + order ID. |
| `source_namespace` | v0.2 y v0.3 | Aislamiento | Same immutable namespace as linked customer. |
| `shop_id` | v0.2 y v0.3 | ID fuente | Source shop ID; cross-installation collision protection required. |
| `order_id` | v0.2 y v0.3 | ID fuente | Source order identifier, not Salesforce CRM ID. |
| `customer_source_key` | v0.2 y v0.3 | Identificador | Must resolve inside same installation/shop. No relation inferred by email. |
| `cart_source_key` | v0.2 y v0.3 | Identificador | Reference only; there is no approved PSCartEvent stream. |
| `currency` | v0.2 y v0.3 | Dato comercial | Three-character currency code; retention/mapping pending. |
| `total_paid` | v0.2 y v0.3 | Dato comercial | Commercial totals; lawful basis, retention, taxes/precision and DMO mapping pending. |
| `paid_flag` | v0.2 y v0.3 | Estado fuente | Transactional state, not marketing eligibility. |
| `state_id` | v0.2 y v0.3 | ID fuente | Native order status ID, not cross-shop semantic status without mapping. |
| `source_updated_local` | v0.2 y v0.3 | Tiempo ambiguo | Timezone/DST provenance unknown. No ordering/Record Modified use. |
| `source_updated_utc` | Solo v0.3 | Fecha candidata | v0.3 only; must not be derived from historical local time without provenance. |


> **G3-03, evidencia técnica adicional:** en PrestaShop 1.7.8.11 las
> fechas normales de ObjectModel se escriben con `date()` tras fijar
> `PS_TIMEZONE` en PHP. Esto respalda una hipótesis de hora local,
> pero no prueba el origen de importaciones e históricos de BC.
> Hemos preparado la [auditoría agregada sin PII](BC_G3_TIMESTAMP_PROVENANCE_AUDIT.md)
> y su [SQL de solo lectura](../scripts/sql/bc_g3_timestamp_provenance_aggregate_readonly.sql).
> G3-03 **sigue pendiente** y el esquema v0.3 **no está autorizado**.
> **Sin acceso SQL directo a producción:** no se pedirá al responsable
> ejecutar consultas. El módulo BC 0.7.1 ya muestra el panel BO
> `Data Cloud - installation preflight` (zona horaria y metadatos de
> columnas), sin consultar filas. El SQL agregado es **opcional para DBA**,
> no un paso obligatorio. Diseñar una fuente UTC nueva y verificable
> para futuros eventos sin reinterpretar los DATETIME históricos.

## Ocho decisiones pendientes del responsable

**1. Identidad multiinstalación.** Conservar el formato `source_namespace:ps_{shop_id}_{entity_id}`. Exigir namespace inmutable asignado por un registro entre instalaciones; no usar email para fusionar personas. **Evidencia necesaria:** Confirmar namespace exclusivo de la instalación BC y política de cambios/colisiones.

**2. Minimización y finalidad.** Revisar necesidad de `email`, `first_name`, `last_name` y los dos indicadores comerciales por separado. `newsletter_flag` y `partner_optin_flag` NO son permisos de envío. **Evidencia necesaria:** Responsable de datos/privacidad aprueba propósito y base jurídica por campo, incluida conservación de históricos.

**3. Fechas y actualizaciones.** `source_updated_local` se conserva como texto diagnóstico; v0.3 agrega `source_updated_utc` ISO UTC, pero el normalizador no puede probar que el histórico se grabó en la zona actual. **Evidencia necesaria:** Auditar escrituras históricas, DST otoño/primavera, reimportaciones, monotonicidad y manejo de cuarentena.

**4. Refresh Mode.** Elegir Full vs Partial antes de crear los streams. Partial exige estrategia de Record Modified y orden fiable; Full sin timestamp no evita obsolescencia por sí mismo. **Evidencia necesaria:** Propietario de datos + arquitecto Data 360 documentan política de reintentos, versión y conservación.

**5. Categoría y Data Space.** Propuesta `PSCustomer` Profile, `PSOrder` Other, PK `source_key`, Data Space observado `default`. No son ajustes aprobados. **Evidencia necesaria:** Arquitecto valida interfaz real y política de objetos y claves.

**6. Relación y mapeo DLO→DMO.** `PSOrder.customer_source_key` debe corresponder a la instalación/tienda del cliente; `cart_source_key` es referencia sin PSCartEvent publicado. **Evidencia necesaria:** Arquitecto valida DLO/DMO y deduplicación de perfiles por identidad, no por email.

**7. Retención, bajas y borrados.** No existe plazo aprobado; requieren política documentada de supresión/objeción, privacidad y erasure por tipo de dato. **Evidencia necesaria:** Responsable privacidad define duración, sujeto de datos, baja/cambio y reconciliación.

**8. Versión final y publicación.** El archivo v0.2 (22 campos) y el v0.3 (24 campos) siguen inactivos. El siguiente artefacto debe ser una única versión firmada (hash SHA256) y vista previa de Salesforce. **Evidencia necesaria:** Aprobación de responsable y luego autorización **distinta** para subir esquema y crear streams.

## Comparativa: qué versión enviaríamos a revisión final

| Elección | Ventaja | Riesgo o bloqueo |
|---|---|---|
| **v0.2 (22 campos)** | No inventa fecha absoluta UTC; sirve para validar modelo conceptual | No hay Record Modified fiable; una actualización atrasada o un replay puede sobrescribir datos. No es autorización para activar cargas |
| **v0.3 (24 campos)** | Incluye campo UTC tipado ISO 8601 y una vía futura para controlar actualizaciones | La fecha de origen está almacenada sin zona; aunque el normalizador rechaza solapamientos DST, necesitamos **probar que la zona usada al escribir fue esa**. El mapper v0.3 solo es un prototipo |

**Recomendación de diseño, no decisión aprobada:** conservar ambas versiones sin publicar; orientar el diseño final hacia una marca UTC fiable para nuevos eventos **solo después de demostrar su origen**, y definir tratamiento independiente de registros históricos dudosos. Si no se demuestra, no usar `source_updated_utc` derivado de históricos ni activar una estrategia de Partial Refresh basada en él.

## Aceptación G3 · registro para firmar

| Decisión | Responsable | Estado |
|---|---|---|
| `G3-01` installation namespace global registry | data owner and technical owner | **Pendiente** |
| `G3-02` field minimization and processing purpose | data owner privacy | **Pendiente** |
| `G3-03` timezone historical storage provenance | technical owner | **Pendiente** |
| `G3-04` record modified and refresh mode | data owner and data 360 architect | **Pendiente** |
| `G3-05` categories pk and data space | data 360 architect | **Pendiente** |
| `G3-06` dlo dmo mapping and order relationship | data 360 architect | **Pendiente** |
| `G3-07` retention erasure and deletion | privacy and data owner | **Pendiente** |
| `G3-08` final yaml hash and salesforce preview | data owner and data 360 admin | **Pendiente** |

Para cerrar G3 se requieren **todas** las decisiones con evidencia trazable y un único esquema final. El responsable del proyecto debe aprobar el esquema y el responsable de datos/arquitecto los aspectos de privacidad, identidad y stream. Se registrará la decisión en una ADR y en el contrato versionado. **Aprobar G3 no autoriza G4, ingesta de datos reales ni campañas.**

## Puertas de ejecución después de G3

1. Congelar archivo, confirmar hash SHA256 y previsualizar objetos/campos en la interfaz PST **sin guardar**.
2. Solo mediante autorización específica: subir schema a `PST`, configurar PK/categoría/refresh y crear hasta un stream por objeto.
3. Ejecutar una validación `/api/v1/ingest/sources/{name}/{object-name}/actions/test` con registros **sintéticos**, sin persistirlos. Salesforce exige todos los campos del esquema en el payload; su validación no equivale a ingesta. Una respuesta 202 al endpoint de escritura es aceptación asíncrona, no certificación de procesamiento.
4. Diseñar reconciliación/retención e ingesta sintética por separado, con aprobación. Después consentimiento/supresión y Flow **Growth**, no SOAP/Journey Engagement.

## Fuentes oficiales contrastadas

- [Requisitos de esquema Ingestion API](https://developer.salesforce.com/docs/data/data-cloud-int/guide/c360-a-ingestion-api-schema-req.html): OpenAPI 3.0.x, límites, no cambiar tipos/eliminar campos, datetime UTC `yyyy-MM-dd'T'HH:mm:ss.SSS'Z'`.
- [Crear Data Streams](https://developer.salesforce.com/docs/data/data-cloud-int/guide/c360-a-create-ingestion-data-stream.html): objeto, PK, categoría, Record Modified y modo de refresco.
- [Growth: streaming a Data 360](https://developer.salesforce.com/docs/marketing/marketing-cloud-growth/guide/mc-manage-objects-create-streaming-data-cloud.html): payloads completos y prueba sintética.
- [Validación sin persistencia](https://developer.salesforce.com/docs/data/data-cloud-int/references/data-cloud-ingestionapi-ref/c360-a-api-synchronous-record-validation.html).

**No se ha solicitado a Salesforce subir el YAML ni realizar escrituras en este corte.**
