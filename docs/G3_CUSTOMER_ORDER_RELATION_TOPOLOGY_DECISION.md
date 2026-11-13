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

## Decisión humana imprescindible para pasar a G3-08

Arquitecto Data 360: seleccionar **A/B/C/D**, categoría del futuro Data Stream, DMO(s) destino, campos PK/FK y relaciones, semántica por `source_namespace` y `shop_id`; comprobar entidades necesarias en ABH y cómo manejar invitados, pedidos históricos y borrados. Responsable de datos: finalidad y necesidad de cada campo, consentimientos distintos, retención/supresión; resolver **G3-02/G3-07**. Responsable de implementación: fuente de fecha real de modificación **G3-03/G3-04**, identidad G3-01 y reversibilidad.

Solo tras firmar esas decisiones se fija `v0.2/v0.3` y SHA-256 en **G3-08**. **G4+** requiere aprobación independiente para subir esquema, crear streams, mapear, ingerir o enviar. Barcelona Confort permanece `pssalesforcebridge 0.7.1` **Test**; ninguna reinstalación.
