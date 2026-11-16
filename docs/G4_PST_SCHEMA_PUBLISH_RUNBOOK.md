# G4 — Publicación del esquema PST v0.4 en Salesforce Data Cloud

**ACTUALIZACIÓN de estado (16/11/2026):** las pantallas reales aportadas por el propietario verifican que el esquema ya está guardado, los dos streams están activos y ambos mapeos DLO→DMO aparecen `LISTO`; la relación `PST-PSOrder.customer_source_key` ManyToOne `Individual.Individual Id` está guardada. Referencia: `config/g4-abh-tenant-ui-evidence.json`. **No repetir los pasos de publicación descritos abajo**: quedan como runbook histórico reproducible para otras tiendas. El siguiente paso del proyecto es G5 validación sintética `/actions/test`. Sin ingesta real ni campañas.


**Conector existente:** `ABH_PrestaShop_Data_Cloud` (alias PST).
**Producto exclusivo:** Salesforce Marketing Cloud Growth + Data Cloud.
**Decisión del propietario:** los tres grupos G3 están aceptados; ver `config/g3-owner-acceptance.json`. No volver a solicitar esa aceptación genérica.

## Artefacto exacto

`schemas/ABH_PST_G4_SCHEMA_UPLOAD.yaml`

- OpenAPI 3.0.3, **2 objetos / 22 campos planos**, nombres físicos `PSCustomer` y `PSOrder`.
- `info.version: "0.4.0"`.
- `source_key` siempre requiere un identificador distinto por namespace, tienda y tipo de entidad:
  - cliente: `source_namespace:shop_id:customer:customer_id`
  - pedido: `source_namespace:shop_id:order:order_id`
  - relación `PSOrder.customer_source_key`: misma instalación y tienda que PSCustomer.
- El schema define tipos; **no** puede obligar a usar claves tipadas por formato. La validación PHP estricta `GrowthTypedPstPayloadBuilder` y su test sintético de 3 versiones hacen cumplir esa parte.
- No hay `PSCart`, URL de recuperación, consentimiento derivado, ni un `source_updated_utc` inventado.
- La huella exacta SHA256 se produce en `build/g4-pst-typed-preview/handoff.json` durante CI. El artifact también contiene una copia de la versión comprobada.

## Paso 1 — En el conector PST ya existente

1. Entrar en **Salesforce Data Cloud → Setup → Data Cloud Setup → Ingestion API**.
2. Abrir **ABH_PrestaShop_Data_Cloud**. **No crear un segundo conector**.
3. Seleccionar **Upload Schema** y elegir `ABH_PST_G4_SCHEMA_UPLOAD.yaml`.
4. En el preview confirmar **PSCustomer (11 campos)** y **PSOrder (11 campos)**, nombres/tipos y `source_key` en ambos.
5. Guardar el esquema si el preview del tenant coincide. Esto es una **escritura duradera en Salesforce**; registrar fecha, estado PST y captura/snapshot del preview, sin secretos ni datos personales.

**No afirmar que estos pasos se ejecutaron** si solo existe CI, YAML o registro de aceptación del propietario.

## Paso 2 — Crear los Data Streams (operación posterior)

Previamente **comprobar si ya existen streams del mismo objeto y conector**; Salesforce admite como máximo un stream por objeto y conexión.

En Data Cloud → Data Streams → New → Ingestion API, seleccionar el conector PST existente y configurar por objeto:

| Campo | PSCustomer | PSOrder |
|---|---|---|
| Primary Key | `source_key` | `source_key` |
| Categoría técnica propuesta | **Profile** | **Other** |
| Refresh Mode propuesto | **Full** | **Full** |
| Record Modified Field | **Sin seleccionar** (no hay UTC verificado) | **Sin seleccionar** |
| Data Space | Data Space real de ABH, verificar en la pantalla | El mismo Data Space de ABH |
| Mapping DLO→DMO | Separado, pendiente de comprobar en tenant | Separado, pendiente de comprobar en tenant |

La decisión de **Refresh Mode no se puede cambiar después** de crear el stream. **No elegir Partial** por comodidad: requiere estrategia verificada de timestamp/source revision. `source_updated_local` está en hora local sin zona y no puede tratarse como UTC/Record Modified.

Después del resumen, revisar los valores que Salesforce muestra antes de pulsar **Deploy**. Crear un Data Stream no ingiere automáticamente registros de PrestaShop; el mapeo DLO→DMO es un paso separado.

## Puertas que no se confunden

- **Aceptación del propietario G3:** registrada, no se solicita nuevamente.
- **Esquema YAML:** listo y certificado por CI offline.
- **Upload Schema / Save en Salesforce:** operación real, requiere prueba del tenant.
- **Deploy de Data Streams:** operación real, requiere configuración real de PK/categoría/refresh/Data Space y prueba del tenant.
- **DLO→DMO:** no inferir asociación por email ni relaciones activas no observadas.
- **Ingesta de clientes/pedidos, campañas, carritos:** continúan desactivadas por separado. El código de 0.8.0 no ejecuta envíos.
- **Barcelona Confort:** permanece instalada 0.7.1 en Test sin reinstalación ni acceso SQL de producción.

## Referencias Salesforce

- https://developer.salesforce.com/docs/data/data-cloud-int/guide/c360-a-connect-an-ingestion-source.html
- https://developer.salesforce.com/docs/data/data-cloud-int/guide/c360-a-ingestion-api-schema-req.html
- https://developer.salesforce.com/docs/data/data-cloud-int/guide/c360-a-create-ingestion-data-stream.html

**Siguiente corte real:** evidenciar en PST el esquema guardado y, posteriormente, crear los dos streams con su configuración confirmada, sin abrir G3 de nuevo.
