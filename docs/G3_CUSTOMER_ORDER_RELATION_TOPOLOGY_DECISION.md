# G3-06 · Decisión de topología cliente–pedido en ABH (sin publicar)

**Fecha:** 2026-11-13  
**Producto:** Salesforce Marketing Cloud Growth/Next + Data 360. **No** Marketing Cloud Engagement.  
**Estado:** EVIDENCIA REAL + ALTERNATIVAS, **firma pendiente**. Datos de PSCustomer/PSOrder no enviados.  
**Bloqueo:** issue [#45](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/issues/45).

## Qué se ha comprobado en el tenant

Tres consultas sanitizadas de metadatos ABH: [#43488387453](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/runs/43488387453), [#43490356308](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/runs/43490356308) y [#43491126597](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/runs/43491126597). Core REST v68.0, OAuth de entorno protegido, sin datos de clientes.

- `Individual`: **PROFILE**, PK `ssot__Id__c`, Data Space `default` observados.
- `SalesOrder`: **UNASSIGNED**, PK `ssot__Id__c`, Data Space `default`; campos `ssot__SoldToCustomerId__c` y `ssot__BillToContactId__c` presentes.
- Relaciones visibles: 20 desde `SalesOrder`, 20 desde `Individual`; **cero enlaces detectados con esas dos columnas entre ambos objetos**. No demuestra inexistencia de una relación estándar inactiva.
- **No** existe mapeo PST DLO→DMO aprobado; el stream/categoría `Other` es propuesta **distinta** del estado `UNASSIGNED` del DMO estándar.

## Evidencia oficial que corrige la inferencia anterior

La referencia estándar de Salesforce identifica **`SalesOrder.BillToAccountId → Account.Id`** y **`SalesOrder.BillToContactId → AccountContact.Id`**, no como referencias explícitas a `Individual.Id`. La nomenclatura `SoldToCustomerId` existe y los bundles B2C Commerce pueden mapear `visitorId` a ese campo, **pero ese bundle NO es el conector personalizado PST de PrestaShop**.

- [Sales Order DMO oficial, relaciones tipadas](https://developer.salesforce.com/docs/data/data-cloud-dmo-mapping/guide/c360dm-si-salesorderdmo-dmo.html).
- [Account DMO y la relación Individual](https://developer.salesforce.com/docs/data/data-cloud-dmo-mapping/guide/c360dm-account-dmo.html).
- [B2C Commerce Sales Order: ejemplo de mapeo, no certificación para ABH](https://developer.salesforce.com/docs/data/data-cloud-dmo-mapping/guide/c360dm-b2ccommerce-salesorder.html).
- [Requisitos de mapeo/PK y relaciones de fuentes personalizadas](https://help.salesforce.com/s/articleView?id=data.c360_a_required_data_mappings.htm&type=5).

**Corrección de interpretación (13/11/2026):** las consultas de relaciones de los runs anteriores devolvieron exactamente **20**, que es el **límite por defecto** de Connect REST. Por tanto, el resultado “cero enlaces” solo describe **las primeras páginas**: no es evidencia de ausencia en el catálogo completo. [El preflight paginado](G3_ABH_DMO_TENANT_READONLY_DISCOVERY.md) repetirá la consulta con límite/offset acotados e indicador de completitud por DMO. No elegir A/B/C/D sin revisar el resultado completo y la semántica del modelo.

## Opciones pendientes de firma

| Opción | Técnica | Lo que exige | Estado |
|---|---|---|---|
| **A — Sales Order con referencia directa a Individual** | `PSOrder.customer_source_key → SalesOrder` en campo verificado de relación con `Individual.Id` | Obtener prueba de **relación válida en ABH** y exactitud semántica, PK/scope/source key; no basta el nombre `SoldToCustomerId` | **No confirmada** |
| **B — Sales Order → Account → Individual** | `SalesOrder.BillToAccountId → Account.Id`; `Account.IndividualId → Individual.Id` si ambas relaciones existen y se activan | Account DMO habilitado, claves de Account con origen coherente, **nueva entidad/mapeo** con finalidad/retención aprobada; no inventar Accounts de compradores | **Verificación de metadata adicional** |
| **C — Mantener PSOrder en DLO separado sin enlace a Individual** | Identidad, pedido, cálculo y estado preservados en origen, sin joins ni segmentación cruzada | Definir qué uso comercial es posible **sin relacionar pedidos a personas**; podría ser insuficiente para campañas | **Alternativa de contención** |
| **D — Relación / DMO personalizado** | Modelo propio y clave compuesta verificable para pedidos–personas | Gobernanza del tenant, categorías, campos, costes, modelo de identidad, supresión y permiso explícito para crear | **No autorizada** |

**Propuesta de mínima exposición:** elegir el camino menos expansivo que demuestre la relación real, no construir Account o DMO personalizado por defecto. Un pedido huérfano queda en cuarentena: sin fusión por email, sin relleno artificial y sin promoción a audiencia. `cart_source_key` sigue sin crear un stream de carritos.

## Nueva comprobación del catálogo — acotada y real

Se amplía `tests/review/G3TenantDmoCatalogProbe.php`, ya autorizado **solo lectura**, con **dos GET estáticos nuevos**: `Account` y `Account/relationships`. Total **6 GETs fijos** con TLS, sin redirects, límite de 1 MiB por respuesta, sin acceso a registros ni secretos extra. Respuesta saneada:

- Presencia, categoría, PK y campos **fijos** `Account.Id`, `Account.IndividualId`, `SalesOrder.BillToAccountId`.
- Números acotados (máximo 250) de relaciones visibles y flags específicos para `BillToAccountId→Account` y `Account.IndividualId→Individual`, indicando si alguna relación declara `ACTIVE`.
- Respuestas `403/404` de Account/relaciones quedan como **no verificadas**, no como prueba de ausencia. Una relación visible tampoco constituye un mapeo PST aprobado.

La misma conexión del entorno GitHub `salesforce-growth-certification` ejecutará el workflow `g3-dmo-catalog-readonly.yml` en `main` cuando el cambio se integre. No se crea otra app ni se amplían privilegios; **la evidencia irá al artefacto saneado, no al log de API**. La nueva ejecución podrá confirmar o descartar visibilidad de la topología B, pero nunca aplicarla.

## Evidencia posterior y alcance comercial (13/11/2026)

La [inspección con paginación verificada #43495007989](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/runs/43495007989), integrada por el [PR #69](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/pull/69), examinó **64 relaciones SalesOrder, 137 Individual y 326 Account**, todas con completitud declarada. **No detectó** las aristas candidatas, aunque esto no prueba que no puedan configurarse o activarse mediante un mapping futuro.

**Decisión técnica temporal, sin aprobación:** mantener separados los pedidos y los perfiles evita una unión falsa (opción C). No es una solución final de negocio: no resuelve en Data 360 la segmentación persona–compra. Tampoco entrega **recuperación de carritos**, ya que PSCustomer/PSOrder v0.2/v0.3 no incluyen PSCart ni existe un Flow Growth aprobado. [Control ejecutable de cobertura del objetivo comercial](G3_08_BUSINESS_GOAL_COVERAGE.md). Los checks locales de pedido antes de enviar deben diseñarse y certificarse en el futuro adaptador Growth, no inferirse del flujo histórico Engagement.

## Aclaración oficial 14/11/2026 — relación estándar inactiva no es relación ausente

**Nueva evidencia del fabricante; no es un nuevo resultado de lectura del tenant ABH.** Salesforce explica expresamente que una relación DMO estándar puede estar **inactiva hasta que se mapean ambos campos**, y que en la pestaña `Relationships` de un DMO solo aparecen por defecto las relaciones activadas. Las inactivas pueden requerir `Show inactive relationships` en la ventana `Edit Relationships`. También cita la cardinalidad **muchos pedidos por individuo** como ejemplo de relación `SalesOrder` / `Individual`. Estas afirmaciones de producto **no demuestran** que un campo directo específico PST→Individual exista, esté disponible o sea activable en ABH.

- [Salesforce Help · Data Model Object Relationships (estados y cardinalidad)](https://help.salesforce.com/s/articleView?id=data.c360_a_data_model_object_relationships.htm&language=en_US&type=5).
- [Salesforce Developers · Sales Order DMO, campos y FK tipadas](https://developer.salesforce.com/docs/data/data-cloud-dmo-mapping/guide/c360dm-si-salesorderdmo-dmo.html).
- [Salesforce Developers · Account DMO](https://developer.salesforce.com/docs/data/data-cloud-dmo-mapping/guide/c360dm-account-dmo.html).

**Interpretación correcta del scan ABH #43495007989:** `64 SalesOrder + 137 Individual + 326 Account = 527` metadatos leídos con paginación completa bajo los filtros del lector. No se detectaron las **aristas candidatas activas/visibles**, pero la completitud de esa API no implica que incluya todas las **relaciones estándar inactivas o sin mapping**. El resultado permite bloquear una unión no verificada; **no** permite declarar imposible la topología estándar ni imponer C/D.

### Orden de comprobación G3-06, sin escrituras ni nuevos permisos

| Prioridad | Ruta a revisar en la definición del modelo Salesforce | Condición para aceptar como futuro diseño | Estado ABH |
| --- | --- | --- | --- |
| 1 | `SalesOrder.BillToAccountId → Account.Id` y `Account.IndividualId → Individual.Id` | El arquitecto debe comprobar ambos extremos y sus mappings, clave calificada por origen/instalación, correspondencia 1:N y compatibilidad con finalidad/retención. **La existencia de Account en el catálogo no autoriza fabricar Accounts desde PSCustomer** | Referencias de modelo documentadas; relaciones/mapping PST **no verificados ni aprobados** |
| 2 | Otras FK **tipadas** de Sales Order: `BillToContactId → AccountContact.Id`, `BilltoEmailId → ContactPointEmail.Id` | Confirmar si los DMOs intermedios existen y se pueden mapear con **identificadores** válidos, no con texto de email ni por similitud nominal. Si se usa contacto o dirección, finalidad y consentimiento se revisan aparte | **No verificado** para PST |
| 3 | Eventual relación directa `SalesOrder → Individual` | Pedir al arquitecto **nombres exactos de ambos campos, cardinalidad y fuente de mapping**; la mención genérica de cardinalidad en Salesforce Help NO certifica una FK directa en ABH | **No confirmada** |
| 4 | Contención C (pedido aislado) o relación personalizada D | C impide segmentar compradores por sus pedidos; D necesita nueva revisión de esquema, modelo, coste y autorización. Elegir solo cuando la revisión de rutas estándar justifique la decisión | **No seleccionadas ni firmadas** |

**Secuencia de decisión:** primero ver el catálogo del DMO en modo solo lectura, incluidas las **relaciones inactivas** cuando la UI las muestre, y el estado del mapping de cada extremo. A continuación, documentar **qué field ID realmente representa al comprador**, cómo se conserva `source_namespace:shop_id`, y qué hacer con invitados, relaciones huérfanas o cruzadas. Solo entonces el arquitecto firma una opción A/B/C/D. La revisión de interfaz debe ser **sin guardar, habilitar, mapear, crear ni borrar** ninguna relación; los permisos G4 son independientes.

**Límite:** `PSOrder.customer_source_key` sigue siendo exclusivamente una FK fuente candidata. No mapearla por defecto a `SoldToCustomerId`, `BillToContactId`, `BilltoEmailId` ni `BillToAccountId`: son campos semánticamente distintos. Un valor de email coincidente no es una FK; usar `ContactPointEmail.Id` exige un identificador válido y un modelo de contacto aprobado.

## Decisión humana imprescindible para pasar a G3-08

Arquitecto Data 360: seleccionar **A/B/C/D**, categoría del futuro Data Stream, DMO(s) destino, campos PK/FK y relaciones, semántica por `source_namespace` y `shop_id`; comprobar entidades necesarias en ABH y cómo manejar invitados, pedidos históricos y borrados. Responsable de datos: finalidad y necesidad de cada campo, consentimientos distintos, retención/supresión; resolver **G3-02/G3-07**. Responsable de implementación: fuente de fecha real de modificación **G3-03/G3-04**, identidad G3-01 y reversibilidad.

Solo tras firmar esas decisiones se fija `v0.2/v0.3` y SHA-256 en **G3-08**. **G4+** requiere aprobación independiente para subir esquema, crear streams, mapear, ingerir o enviar. Barcelona Confort permanece `pssalesforcebridge 0.7.1` **Test**; ninguna reinstalación.


## Dos puentes estándar adicionales investigables sin crear Accounts ni unir por email (14/11/2026)

A partir de las referencias oficiales de [Sales Order](https://developer.salesforce.com/docs/data/data-cloud-dmo-mapping/guide/c360dm-si-salesorderdmo-dmo.html), [Account Contact](https://developer.salesforce.com/docs/data/data-cloud-dmo-mapping/guide/c360dm-si-accountcontactdmo-dmo.html) y [Contact Point Email](https://developer.salesforce.com/docs/data/data-cloud-dmo-mapping/guide/c360dm-si-contactpointemaildmo-dmo.html), el arquitecto debe considerar, **sin selección automática**, estas variantes de la topología con DMO intermedio:

| Ruta | Relación estándar indicada por Salesforce | Riesgo que impide aprobarla aún |
| --- | --- | --- |
| **B2 — AccountContact** | `SalesOrder.BillToContactId → AccountContact.Id → AccountContact.IndividualId → Individual.Id` | `AccountContact` es una entidad distinta de PrestaShop Customer. Hay que demostrar IDs, origen, cardinalidad y mapeo del contacto; no crear uno ficticio por pedido |
| **B3 — ContactPointEmail** | `SalesOrder.BilltoEmailId → ContactPointEmail.Id → ContactPointEmail.PartyId → Individual.Id` | `PartyId` puede referirse **a Individual o a Account**. La ruta solo sirve si está tipada contra Individual y se demuestran **IDs reales**, nunca coincidencia de texto de email. Es un dato relacionado con contacto y requiere minimización/finalidad |

El inspector G3 ahora puede observar, mediante metadatos limitados, `AccountContact` y `ContactPointEmail`, sus campos fijos y las relaciones visibles (ambos extremos; hasta 500 por DMO). **No lee direcciones de correo, pedidos ni personas**, no crea un DMO, no guarda mapeos y no asigna finalidades. `403/404` o ausencia de arista equivale a **no demostrado**, especialmente para relaciones inactivas.

Se conserva el expediente A/B/C/D: **B2/B3 son rutas candidatas dentro del análisis de intermediación estándar**, no nuevas opciones decididas. Ninguna equivale a autorización comercial, unión de identidades o prueba de que ABH tenga ya una configuración operativa.

