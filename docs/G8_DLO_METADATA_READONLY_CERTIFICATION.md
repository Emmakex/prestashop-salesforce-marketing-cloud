# G8 — Resolución real de objetos y campos DLO (20/11/2026)

## Motivo

El lector de duplicados por clave exacta (PR #164) ya está certificado, pero **no debía adivinar** que los nombres mostrados en Data Explorer `PST-PSCustomer` y `PST-PSOrder` fuesen los nombres SQL API reales. La integración ha utilizado nombres internos distintos a los rótulos de pantalla. No reutilizar jamás un nombre supositorio de otra tienda.

## Implementado

`GrowthG8DloMetadataResolver` reutiliza el patrón de `G7ExistingCanaryReadOnly`: identifica en el catálogo GET `/api/v1/metadataEntities?entityType=DataLakeObject` **exactamente una** entrada con cada displayName histórico (`PST-PSCustomer`, `PST-PSOrder`) y a continuación valida el nombre de la entidad en sus metadatos GET `/api/v1/metadata?entityType=DataLakeObject&entityName=...`.

En el detalle localiza por igualdad exacta **una sola columna `source_key` o `source_key__c`** para ambas entidades y **`customer_source_key` o `customer_source_key__c`** para pedido; rechaza duplicados, catálogos paginados, nombres SQL inseguros, ausencia de campo, confusión con objetos DMO o respuestas incompletas. El resultado interno aporta únicamente los nombres API que requiere el lector Query V2, no perfiles.

El workflow **G8 PST DLO Exact Metadata Read Only** se ejecuta en `main` con el entorno protegido `salesforce-growth-certification` y las credenciales ya existentes. Realiza:

1. OAuth Core + intercambio Data Cloud que ya estaban certificados.
2. **GET únicamente** del catálogo DLO y dos metadatos de entidad (sin datos de clientes).
3. **SELECT en Query API V2 únicamente para los canarios sintéticos preexistentes**, nunca el cliente 6031 ni pedido 612 de BC. Verifica si el lector exacto devuelve `present`.
4. Guarda un artifact JSON con solo indicadores booleanos de metadata/canarios, SHA/ID de ejecución y garantía de que no hubo ingesta ni campañas. Oculta nombres API, SQL, claves, correos y tokens.

Cualquier error de permisos, campos, catálogo, OAuth o resultado Query genera **no verificado** y no desbloquea envíos. Los tests sintéticos cubren duplicados, catálogo parcial, mismatch de metadata, inyección de nombres SQL, columnas faltantes y excepciones privadas. El workflow no se conecta al cron de PrestaShop y su ejecución nunca reclama entradas ni cambia la outbox.

## Límite que aún queda

Resolver metadatos y leer un canario sintético **no demuestra que un DLO pertenezca al origen de cualquier nuevo comercio**. Para BC existe evidencia real de que PSCustomer/PSOrder se mapean desde `ABH_PrestaShop_Data_Cloud` (cliente 6031 y pedido 612, DLO+DMO). Para DOABH y futuras tiendas se necesitará un origen y vinculación de metadatos propios; no heredar el catálogo BC ciegamente.

**Próxima integración técnica única:** enlazar de forma protegida esa resolución al `recordDloStatus` y `customerDloVerified` de `GrowthAutomaticOneShotCoordinator`, y conectar los adaptadores G3 a sus reservas CAS de eventos. Después probar la transición 202→conciliación en staging y una ejecución del cron con rollback, antes de solicitar habilitación de envío automático acotado. La instalación BC 0.9.4 permanece en Test, sin actualización ni nuevas campañas.

## Referencias oficiales

- [Get Metadata Entities](https://developer.salesforce.com/docs/data/data-cloud-query-guide/references/data-cloud-query-api-reference/c360a-api-v1-metadata-entities.html)
- [Get Metadata](https://developer.salesforce.com/docs/data/data-cloud-query-guide/references/data-cloud-query-api-reference/c360a-api-v1-metadata.html)
- [Query API V2](https://developer.salesforce.com/docs/data/data-cloud-query-guide/references/data-cloud-query-api-reference/c360a-api-query-v2.html)

## Consulta de canarios sintéticos y diagnóstico de solo lectura (23/11/2026)

Una vez certificados los nombres DLO y campos, el informe G8 distingue por canario los estados cerrados `present`, `absent_confirmed` y `unknown`, además de `not_checked` cuando no se ejecutó la consulta. Los motivos son categorías de lista blanca: HTTP numérico, JSON inválido, respuesta incompleta/paginada, fila no verificable, excepción de transporte, coincidencia exacta o respuesta completa sin filas.

El artifact no contiene la respuesta, SQL, nombre API, namespace, clave, datos de personas, host ni token. Un HTTP 200 vacío **solo** permite `absent_confirmed` si `done=true`, `rowCount=0` y no hay paginación. `unknown` bloquea cualquier envío. No se repite el POST de los canarios G5 ni las pruebas reales BC 6031/612. El cron y las campañas siguen desconectados.

### Diagnóstico de fila sin contenido (23/11/2026)

La certificación de Query V2 ahora diferencia, sin imprimir columnas ni valores, `row_not_object`, `row_field_missing`, `row_value_not_string` y `row_key_not_exact`. Los cuatro casos mantienen el resultado `unknown` y prohíben reingesta o autorización de un worker. El HTTP 200 con filas, por sí solo, no equivale a clave exacta. Esta distinción se añade para explicar el `row_unverified` observado simultáneamente para ambos canarios en el run #45136049771.
