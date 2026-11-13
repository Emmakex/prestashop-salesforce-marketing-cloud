# G3-05/G3-06 · PSCustomer y PSOrder: DLO → DMO, claves y relaciones

**13/11/2026 · ABH / Barcelona Confort · Marketing Cloud Growth/Next + Data 360.**  
**Estado: propuesta técnica OFFLINE; aprobación del arquitecto Data 360 y del propietario de datos PENDIENTE.**  
**Conector de origen:** `PST` / `ABH_PrestaShop_Data_Cloud` observado como `Schema Required`.

## 1. Diferenciar fuente, DLO y DMO

Cada objeto del esquema de Ingestion API se convertirá, una vez publicado y aprovisionado por separado, en un **Data Lake Object (DLO)**. La correspondencia con un **Data Model Object (DMO)** y sus relaciones de identidad requieren un mapeo aprobado y guardado en el tenant. Que un campo tenga un nombre similar no demuestra que el mapeo exista.

| Origen propuesto | Categoría de Data Stream | Campo PK DLO | DMO candidato oficial | Campo PK DMO candidato | Estado |
|---|---|---|---|---|---|
| `PSCustomer` | `Profile` | `source_key` | `Individual` (`ssot__Individual__dlm`) | `ssot__Id__c` | Sin verificar en ABH; no guardar |
| `PSOrder` | `Other` | `source_key` | `Sales Order` (`ssot__SalesOrder__dlm`) | `ssot__Id__c` | Sin verificar en ABH; no guardar |

**Data Space:** `default` observado, **sin aprobación de uso**. Las categorías, PK y DMO son **candidatos**, no una configuración aplicada.

Se mantienen dos espacios de identidad **por objeto**: un cliente y un pedido de una tienda pueden tener textualmente el mismo `source_key`, pero eso **no** autoriza guardar ambas entidades en el mismo DMO, ni mezclar sus identificadores. El namespace debe ser único por instalación (G3-01 aún pendiente).

**Hallazgo real en ABH (13/11/2026):** la lectura GET de metadatos [run #43488387453](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/runs/43488387453) vio el DMO `SalesOrder` pero **su categoría no coincidió** con `OTHER`; los campos `SoldToCustomerId` y `BillToContactId` existen, pero la relación con Individual **no quedó confirmada**. La categoría **DMO** leída no implica por sí misma categoría **Data Stream**. Las relaciones estándar pueden estar inactivas hasta completar mapeos de campos. Se prepara una segunda lectura estrictamente de metadatos para registrar la categoría exacta saneada y las relaciones visibles desde ambos lados. Hasta entonces no cambiar ni aprobar `config/g3-pst-dlo-dmo-mapping-candidate.json`.

## 2. Relación 1:N propuesta, no activada

`PSOrder.customer_source_key` debe coincidir exactamente con `PSCustomer.source_key` y pertenecer al **mismo** `source_namespace` y `shop_id`. Una cadena `ps_1_17` por sí sola no identifica globalmente un cliente, por lo que nunca debemos unir relaciones por email, nombres, `customer_id` aislado o coincidencias entre comerciantes.

Propuesta lógica: un cliente de origen puede asociarse a varios pedidos de la misma instalación/tienda. El destino Salesforce podría necesitar `Sales Order.Sold To Customer` (`ssot__SoldToCustomerId__c`) o `Bill To Contact` (`ssot__BillToContactId__c`), pero **no se ha decidido cuál representa correctamente al cliente de PrestaShop**, ni verificado que el tenant tenga habilitados esos campos y la relación a `Individual`. No seleccionar uno automáticamente.

`cart_source_key` es **solo referencia**. No existe flujo PSCartEvent aprobado y no se establece relación con carritos.

## 3. Qué hace el ensayo ejecutable

`tests/review/G3DloDmoRelationshipPreview.php` inspecciona únicamente seis o cuatro **campos técnicos** de filas sintéticas generadas por el mapper existente. Rechaza cualquier campo adicional como emails, nombres, importes o consentimientos y produce exclusivamente **recuentos agregados**, sin identificadores de cliente o pedido en salida.

- Verifica formato y coherencia de `source_namespace:ps_{shop_id}_{id}` contra el objeto adecuado, evitando duplicados dentro de cada DLO.
- Comprueba relaciones 1:N dentro del ámbito de la misma instalación y tienda.
- Pone en cuarentena pedidos huérfanos, sin clave de cliente, con referencias a otra tienda/instalación o con referencia a carrito de otro ámbito.
- Distingue la consistencia local de una **relación DMO realmente validada**: todos los permisos de mapeo, ingestión, identity resolution y envíos permanecen en `false`.
- Ensayo reproducible: tres clientes sintéticos, ocho pedidos, **cuatro relaciones coherentes y cuatro en cuarentena**. Estas cifras **no** son datos reales de BC.

`config/g3-pst-dlo-dmo-mapping-candidate.json` registra los 24 campos PSCustomer/PSOrder del inventario G3: cada campo queda como PK propuesta, referencia extranjera propuesta o excluido **sin autorización de escritura**. El verificador `scripts/ci/verify-g3-dlo-dmo-mapping-candidate.py` contrasta el listado contra los dos YAML congelados, las categorías, PK, nombres de DMO y prohibiciones de publicación. `tests/test_g3_dlo_dmo_mapping_candidate.py` y `tests/g3-dlo-dmo-relationship-contract.php` ejercitan rechazos de malas relaciones, aprobaciones simuladas y cambios de campos.

## 4. Decisiones humanas que faltan (G3-05 y G3-06)

1. **Arquitecto Data 360:** comprobar en el tenant **ABH** que `Individual` y `Sales Order` están disponibles, sus campos exactos y tipos; aprobar la categoría, Primary Key y Data Space por objeto.
2. Elegir la correspondencia precisa DLO → DMO por campo, incluidas las claves `source_key` y `customer_source_key`, y verificar el **destino realmente relacionado** de `Sales Order` con el cliente. No deducirla solo de nombres de columnas.
3. Definir cómo tratar pedidos de invitados, clientes borrados, pedidos históricos huérfanos y errores de sincronización, sin crear perfiles ficticios ni unirlos por email.
4. Mantener sin publicar los atributos de email, nombres, indicadores, importes y estado cuya finalidad/retención aún dependen de G3-02/G3-07. Solo después se podrá seleccionar el YAML final G3-08; **G4 exigirá autorización separada** para guardar mapeos/streams.

Fuentes oficiales Salesforce: [Data Mapping](https://help.salesforce.com/s/articleView?id=c360_a_data_mapping.htm&type=5), [Individual DMO](https://developer.salesforce.com/docs/data/data-cloud-ref/guide/c360dm-individual-dmo.html), [Sales Order DMO](https://developer.salesforce.com/docs/data/data-cloud-dmo-mapping/guide/c360dm-sales-order-dmo.html), [Category](https://help.salesforce.com/s/articleView?id=data.c360_a_category.htm&type=5).

**Límite:** no se han inspeccionado DMOs del tenant con credenciales nuevas, no se ha subido el esquema, creado un Data Stream, guardado un mapeo, leído SQL de BC, realizado ingestión ni enviado correos. `pssalesforcebridge` sigue en **0.7.1, Test**, sin reinstalación ni nuevo ZIP.
