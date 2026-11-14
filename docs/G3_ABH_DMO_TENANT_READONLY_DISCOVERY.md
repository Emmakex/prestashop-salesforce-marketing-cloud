# G3-05/G3-06 · Verificación del catálogo DMO real de ABH, solo lectura

**13/11/2026, ampliación dirigida del catálogo.** **Comercio:** ABH / Barcelona Confort. **Producto:** Marketing Cloud Growth/Next sobre Core + Data 360, **NO** Engagement.  
**Gate:** G3 permanece bloqueado por aprobaciones del responsable; ningún catálogo consultado autoriza cambiar el esquema o los mapeos.

## Qué se va a inspeccionar realmente

La API oficial **Salesforce Data 360 Connect REST** permite consultar metadatos de DMO y relaciones mediante `GET /services/data/v68.0/ssot/data-model-objects/{apiName}`. En este proyecto, versión REST v68.0 ya observada. El [workflow protegido](../.github/workflows/g3-dmo-catalog-readonly.yml) ejecuta **tres GET de esquema DMO y tres familias GET de relaciones con paginación acotada** usando la misma External Client App Growth y el mismo entorno GitHub `salesforce-growth-certification`. No solicita secretos nuevos ni modifica permisos.

| Consulta | Qué se comprueba | Qué no demuestra |
|---|---|---|
| `GET …/ssot__Individual__dlm` | Existencia/lectura de Individual, categoría `PROFILE`, Data Space `default`, `ssot__Id__c` PK | Que sea el DMO aprobado para PSCustomer o que existan datos |
| `GET …/ssot__SalesOrder__dlm` | Existencia/lectura de Sales Order, categoría `OTHER`, PK, posibles campos `SoldToCustomerId` / `BillToContactId` | Que un campo concreto sea el destino correcto de `PSOrder.customer_source_key` |
| `GET …/ssot__SalesOrder__dlm/relationships` | Cuenta las relaciones visibles desde Sales Order, sin publicar sus nombres ni IDs; reconoce exclusivamente los campos candidatos hacia Individual | Que una relación no visible esté ausente en la definición estándar |
| `GET …/ssot__Individual__dlm/relationships` | Segunda perspectiva de las relaciones visibles que involucren Individual y Sales Order | Que exista ya un mapeo DLO→DMO o una relación activa en ABH |
| `GET …/ssot__Account__dlm` | Si es accesible, categoría, PK y campo `Account.IndividualId` | Que debamos crear/importar Accounts a partir de PSCustomer |
| `GET …/ssot__Account__dlm/relationships` | Metadatos y flags fijos de los posibles enlaces `SalesOrder.BillToAccountId → Account.Id` y `Account.IndividualId → Individual.Id` | Que sean relaciones activas, que PSCustomer esté mapeado, ni que la ruta de dos saltos esté aprobada |

Las respuestas se reducen **solo** a indicadores booleanos conocidos, categoría explícita de la allowlist `PROFILE / OTHER / ENGAGEMENT / UNASSIGNED / UNRECOGNIZED` y recuentos limitados de relaciones: ningún nombre de campo personalizado, objeto desconocido, dato individual, ID de Salesforce, token, URL real del tenant ni respuesta HTTP original se publica. Errores `401`, `403`, `404` o formatos no admitidos quedan como código fijo, sin cuerpo de error. Si la consulta de relaciones devuelve `403/404`, la evidencia indicará *metadata_accessible=false*, **no** “no existe relación”.

## Evidencia del primer GET real y por qué se amplía la lectura

El [run #43488387453](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/runs/43488387453) en el commit `e4435ee` confirmó `Individual` con categoría `PROFILE` y PK visible, además de `SalesOrder` con PK y dos campos candidatos visibles, pero su categoría **no coincidió** con la propuesta `OTHER`. El lector inicial ocultaba el valor concreto y consultaba relaciones solo desde `SalesOrder`, sin encontrar una relación hacia `Individual` con esos campos.

La segunda lectura reportará únicamente la categoría de Salesforce como **enum saneado** y recuentos de relaciones en ambas direcciones. Salesforce indica que relaciones estándar pueden permanecer inactivas hasta mapear los campos pertinentes; **cero relaciones visibles no es prueba de inexistencia**. La categoría observada del **DMO** tampoco debe confundirse con una autorización de categoría para el **Data Stream** PST. No se elegirán ni guardarán mapeos como consecuencia de la lectura.

Documentación: [Get DMO/relationships, Connect REST](https://developer.salesforce.com/docs/data/connectapi/references/spec?meta=type%3AOutput+D360+Node+Input) y [relaciones estándar activas/inactivas](https://help.salesforce.com/s/articleView?id=data.c360_a_data_model_object_relationships.htm&language=en_US&type=5).

## Segunda observación real de ABH (13/11/2026)

El [run #43490356308](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/runs/43490356308) sobre `81042f9` leyó **ambos** extremos correctamente: `Individual=PROFILE`, `SalesOrder=UNRECOGNIZED` según la allowlist inicial, 20 relaciones en la respuesta de cada DMO y **ninguna relación directa detectada** entre ambos mediante los campos candidatos. Esto no prueba que no exista una relación estándar inactiva: puede no mostrarse hasta mapear los dos campos. No se guardó un mapeo ni se autorizó ninguna operación.

La [referencia oficial de DMO Sales Order](https://developer.salesforce.com/docs/data/data-cloud-dmo-mapping/guide/c360dm-si-salesorderdmo-dmo.html) documenta que un estándar puede tener categoría **`UNASSIGNED`**, y la [referencia de Connect REST](https://developer.salesforce.com/docs/data/connectapi/references/spec?meta=type%3AOutput+D360+Node+Input) incluye respuestas con `category: UNASSIGNED`. Se añade **exclusivamente** ese valor a la allowlist sanitizada y se repite la consulta para observar si ABH devuelve ese valor exacto. La categoría de un DMO no es una aprobación de categoría del Data Stream PST.

**Tercera observación real:** el [run #43491126597](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/runs/43491126597) confirmó `SalesOrder=UNASSIGNED`, `Individual=PROFILE`, PK y Data Space `default` en ambos; 20 relaciones visibles en cada extremo, ninguna directa demostrada. La siguiente inspección acotada se limita a comprobar el [posible puente Account](G3_CUSTOMER_ORDER_RELATION_TOPOLOGY_DECISION.md) sin activarlo.

## Corrección de completitud: la primera página NO era el catálogo completo

La [referencia Connect REST de Salesforce](https://developer.salesforce.com/docs/data/connectapi/references/spec?meta=type%3AOutput+D360+Node+Input) indica que `GET …/{DMO}/relationships` devuelve **20 relaciones por defecto** y expone `limit`, `offset`, `totalSize` y enlaces de continuación. En los [runs #43490356308](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/runs/43490356308) y [#43492920228](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/runs/43492920228) se observaron **20 relaciones exactas en cada DMO**. El lector anterior solo descargaba la primera página; por ello **no es válido afirmar que no existen relaciones SalesOrder↔Account↔Individual en todo ABH**. Los resultados previos solo indican que no se detectaron en esa **primera página**.

El nuevo preflight conserva los tres GET de esquema (`Individual`, `SalesOrder`, `Account`) y utiliza consultas de relaciones con **`limit=100` y `offset` local, entero y acotado**, sin usar `nextPageUrl` ni `pageToken` recibidos. Máximo **cinco páginas por DMO**, **500 metadatos de relación por objeto**, hasta **18 GET en total**. Si `totalSize` excede lo examinado, no se puede probar continuidad, Salesforce rechaza la paginación o repite páginas, el indicador `*_relationship_scan_complete` queda **false** y no puede interpretarse una ausencia. Se siguen emitiendo solo indicadores predefinidos y recuentos, nunca los cuerpos de respuesta.

Ni siquiera un catálogo completo y una arista estándar visible autoriza por sí mismo `PSOrder→SalesOrder`: el arquitecto debe decidir mapeo, clave calificada y finalidad para ABH. Ninguna acción de escritura o permiso se añade.

## Seguridad y activación

- El workflow usa `permissions: contents: read`, checkout del SHA exacto y `persist-credentials: false`.
- Se ejecuta **solo en `main` del repositorio autorizado**, al integrarse este código o mediante `workflow_dispatch` en main. No ejecuta secretos en PR.
- Usa la cuenta y el entorno protegidos ya existentes. Si GitHub exige revisión del entorno, el job puede quedar en `waiting`; no se amplían permisos para evitar esa revisión.
- El inspector `tests/review/G3TenantDmoCatalogProbe.php` es de uso **exclusivo en certificación** y nunca forma parte de `pssalesforcebridge/`. Solo acepta origen HTTPS `*.salesforce.com`, sin credenciales/puertos/paths arbitrarios, rechaza redirects, valida TLS, limita cuerpos a 1 MiB por consulta y no acepta rutas no previstas.
- El script `tests/live/g3-tenant-dmo-catalog.php` produce `g3-abh-dmo-catalog-sanitized.json`; el artifact permanece 14 días. Cualquier error de API deja `status=failed` y diagnóstico sanitario, sin poner un gate como aprobado.
- `tests/g3-tenant-dmo-catalog-contract.php` prueba entradas sintéticas, errores, inyección de hosts, parámetros, archivos, ausencia de datos sensibles, y compatibilidad PHP 7.1/8.5 sin acceder a Salesforce.

## Lectura resumida en Actions para que el responsable pueda decidir G3-06 (14/11/2026)

El workflow protegido `ABH Data 360 DMO Catalog Read Only` publica ahora una pestaña **Summary / Resumen de ejecución** generada únicamente desde `g3-abh-dmo-catalog-sanitized.json`. No hace nuevas consultas ni usa permisos adicionales. Su lector `scripts/ci/render-g3-dmo-review-summary.py` acepta solo el archivo local fijo y descarta cualquier dato que no sea uno de los **cinco nombres de DMO** predefinidos, categoría permitida, booleanos de metadatos o recuentos acotados a 500. No copia tokens, correos, respuestas crudas, URL del tenant, IDs de registros ni campos propios. Si el resultado es incorrecto, falta o anuncia permisos indebidos, el resumen informa **«No hay evidencia validada»**, sin interpretar el silencio como ausencia de relaciones.

La tabla presenta **acceso, categoría, PK y completitud de relaciones por DMO**, seguida de las tres rutas `SalesOrder→Account→Individual`, `SalesOrder→AccountContact→Individual` y `SalesOrder→ContactPointEmail→Individual`. Distingue cada tramo entre `Visible ACTIVE`, `Visible; ACTIVE no confirmado` y `No observada (no equivale a ausencia)`. Los indicadores son **solo metadatos de relaciones y nunca significan aprobación de mapping PST ni de destinatarios**; el texto recuerda expresamente que una relación inactiva podría no estar visible y que G3 sigue pendiente de propietario + arquitecto.

El resumen se genera incluso si la inspección termina con error, pero **no suprime el error del job**. El artifact saneado original sigue disponible durante 14 días. Las pruebas sintéticas offline `tests/test_g3_dmo_review_summary.py` comprueban valores maliciosos/extraños, respuestas no certificadas, gating de permisos, categorías inválidas y que jamás se impriman emails ni atributos inesperados.

**Acción concreta:** abrir `Actions → ABH Data 360 DMO Catalog Read Only → run de main → Summary`. No hace falta descargar un JSON para ver el estado técnico. Si hay resultados, incorporarlos a la decisión **sin marcarlos como aprobación**; si el job requiere autorización del entorno protegido, mantenerla y no ampliar permisos para evitarla.

## Cómo consultar el resultado

En GitHub, `Actions → ABH Data 360 DMO Catalog Read Only`, abrir el run que corresponda a **`main`**. Si se ejecuta correctamente, revisar el artifact `g3-abh-dmo-catalog-readonly-...` y sus flags sanitizados. No copiar secretos ni respuestas completas. Un resultado **metadata_read_success_review_only** certificará únicamente los endpoints y campos presentes en ese instante.

En caso de error `g3_dmo_http_403` o `g3_dmo_http_404`, no es prueba de que ABH carezca de licencias, DMOs o relación; puede significar falta de acceso, versión o recurso no expuesto por esa identidad. Consultar con administrador sin crear nuevas credenciales por defecto.

Fuentes oficiales:
- [Data 360 Connect REST: Get DMO, relationships](https://developer.salesforce.com/docs/data/connectapi/references/spec?meta=type%3AOutput+D360+Node+Input)
- [DMO metadata / relations version](https://developer.salesforce.com/docs/data/connectapi/references/spec?meta=type%3AData%2BCloud%2BIdentity%2BResolution%2BConfig%2BPatch%2BInput)

## Qué seguirá bloqueado

Aunque los catálogos consultados respondan, **G3-05/G3-06 seguirán pendientes** hasta que propietario y arquitecto Data 360 confirmen categoría, PK, Data Space, mapeo DLO→DMO por campo, DMO relación correcta, huéspedes/borrados y supresiones. G3-01/02/03/04/07/08 necesitan aprobaciones independientes; G4–G8 aún bloqueados.

**Ningún cambio al módulo BC 0.7.1 Test, schemas YAML, SQL, cliente, pedido, importación, fuente PST, Data Stream, campañas ni permisos. Sin ZIP ni reinstalación.**


## Inspección extendida de puentes comprador–pedido (14/11/2026, PR pendiente de CI)

La documentación oficial de Salesforce describe **dos enlaces estándar adicionales** hacia `Individual`, sin construir una unión por el contenido del email:

1. `SalesOrder.BillToContactId → AccountContact.Id`, después `AccountContact.IndividualId → Individual.Id`.
2. `SalesOrder.BilltoEmailId → ContactPointEmail.Id`, después `ContactPointEmail.PartyId → Individual.Id`, **solo cuando PartyId esté tipado contra Individual**, porque el mismo campo puede referir a Account. El identificador de correo no es la dirección textual.

Fuentes oficiales: [Sales Order DMO](https://developer.salesforce.com/docs/data/data-cloud-dmo-mapping/guide/c360dm-si-salesorderdmo-dmo.html), [Account Contact DMO](https://developer.salesforce.com/docs/data/data-cloud-dmo-mapping/guide/c360dm-si-accountcontactdmo-dmo.html) y [Contact Point Email DMO](https://developer.salesforce.com/docs/data/data-cloud-dmo-mapping/guide/c360dm-si-contactpointemaildmo-dmo.html). El **DMO estándar** AccountContact aparece como `UNASSIGNED` y ContactPointEmail como `RELATED` en las referencias actuales. Estas categorías **no** aprueban la categoría del stream PST ni acreditan el estado real ABH.

Se amplía el inspector de **solo metadatos** con dos DMO opcionales: `ssot__AccountContact__dlm` y `ssot__ContactPointEmail__dlm`; añade solo sus `/relationships` paginados, sin leer registros. Los nombres de API son literales permitidos; no admite parámetros de origen, path ni query de Salesforce. El número de GET de metadata queda entre **5 y 30**, según la paginación (5 objetos y hasta 5×5 páginas de 100 relaciones). **Ningún GET de clientes, pedidos o emails.**

El artifact mantiene **solo** categorías permitidas, campos fijos `ssot__Id__c`, `IndividualId`, `AccountId`, `ContactEmailId`, `PartyId` y `BilltoEmailId`, flags de relación exacta, acceso/completitud y recuentos. No expone emails, tokens, URL del tenant, nombres de campos custom ni cuerpos de respuesta. Un `403/404` sobre DMO opcional significa **no comprobado**, no inexistente. El límite de 500 relaciones por DMO impide inferir ausencia si el catálogo está truncado; incluso 500/500 sin arista visible **no prueba ausencia de relaciones estándar inactivas**, ni da permiso para mapear.

**Diferencia de estado corregida (14/11/2026, revisión CI):** para cada uno de los cuatro tramos nuevos de `SalesOrder → AccountContact → Individual` y `SalesOrder → ContactPointEmail → Individual`, el inspector conserva `*_relation_seen` y añade `active_*_relation_seen`. La presencia de una relación `INACTIVE`, un valor inesperado o un estado ausente **no** marca `active_*=true`; únicamente el literal `ACTIVE` permite ese indicador. Se prueba igualmente la orientación inversa del catálogo (FK en `targetField`). **Estos flags son observaciones, no garantía de mapping activo ni permiso**: `account_contact_bridge_approved` y `contact_email_bridge_approved` permanecen `false` en todos los casos. Los valores desconocidos son revisión pendiente, no evidencia negativa del estándar.

**Lo que se espera del siguiente run protegido de `main`:** si son visibles los dos DMO opcionales, si aparecen sus PK/FK candidatas, los recuentos y la relación tipada observada. Aun si ambas aristas se observan, las salidas `account_contact_bridge_approved`, `contact_email_bridge_approved`, `tenant_mapping_approved` e ingesta/envío siguen **false**. El arquitecto debe verificar correspondencia real de claves, namespace, cardinalidad, huérfanos, finalidad y mapping en ABH; la edición de relación inactiva en UI sigue siendo revisión humana sin guardar.

**Pruebas:** `tests/g3-tenant-dmo-catalog-contract.php` genera fixtures y relaciones sintéticas de los nuevos caminos, otros PartyId no Individual, paginación, datos confidenciales descartados y garantías GET-only. Compatible PHP 7.1/8.5. La ejecución en CI de PR no usa secretos; solo el workflow protegido de `main` puede leer catálogo real.

