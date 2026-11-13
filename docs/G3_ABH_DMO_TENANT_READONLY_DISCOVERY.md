# G3-05/G3-06 · Verificación del catálogo DMO real de ABH, solo lectura

**13/11/2026.** **Comercio:** ABH / Barcelona Confort. **Producto:** Marketing Cloud Growth/Next sobre Core + Data 360, **NO** Engagement.  
**Gate:** G3 permanece bloqueado por aprobaciones del responsable; ningún catálogo consultado autoriza cambiar el esquema o los mapeos.

## Qué se va a inspeccionar realmente

La API oficial **Salesforce Data 360 Connect REST** permite consultar metadatos de DMO y relaciones mediante `GET /services/data/v68.0/ssot/data-model-objects/{apiName}`. En este proyecto, versión REST v68.0 ya observada. El [workflow protegido](../.github/workflows/g3-dmo-catalog-readonly.yml) ejecuta **solo tres consultas GET fijas** usando la misma External Client App Growth y el mismo entorno GitHub `salesforce-growth-certification`. No solicita secretos nuevos ni modifica permisos.

| Consulta | Qué se comprueba | Qué no demuestra |
|---|---|---|
| `GET …/ssot__Individual__dlm` | Existencia/lectura de Individual, categoría `PROFILE`, Data Space `default`, `ssot__Id__c` PK | Que sea el DMO aprobado para PSCustomer o que existan datos |
| `GET …/ssot__SalesOrder__dlm` | Existencia/lectura de Sales Order, categoría `OTHER`, PK, posibles campos `SoldToCustomerId` / `BillToContactId` | Que un campo concreto sea el destino correcto de `PSOrder.customer_source_key` |
| `GET …/ssot__SalesOrder__dlm/relationships` | Si es accesible, detecta solo las relaciones **del DMO Sales Order hacia Individual** y los campos candidatos | Que esa relación esté usada por el mapeo PST o que se haya guardado el DLO→DMO |

Las respuestas se reducen **solo** a indicadores booleanos conocidos: ningún nombre de campo personalizado, objeto desconocido, dato individual, ID de Salesforce, token, URL real del tenant ni respuesta HTTP original se publica. Errores `401`, `403`, `404` o formatos no admitidos quedan como código fijo, sin cuerpo de error. Si la consulta de relaciones devuelve `403/404`, la evidencia indicará *metadata_accessible=false*, **no** “no existe relación”.

## Seguridad y activación

- El workflow usa `permissions: contents: read`, checkout del SHA exacto y `persist-credentials: false`.
- Se ejecuta **solo en `main` del repositorio autorizado**, al integrarse este código o mediante `workflow_dispatch` en main. No ejecuta secretos en PR.
- Usa la cuenta y el entorno protegidos ya existentes. Si GitHub exige revisión del entorno, el job puede quedar en `waiting`; no se amplían permisos para evitar esa revisión.
- El inspector `tests/review/G3TenantDmoCatalogProbe.php` es de uso **exclusivo en certificación** y nunca forma parte de `pssalesforcebridge/`. Solo acepta origen HTTPS `*.salesforce.com`, sin credenciales/puertos/paths arbitrarios, rechaza redirects, valida TLS, limita cuerpos a 1 MiB por consulta y no acepta rutas no previstas.
- El script `tests/live/g3-tenant-dmo-catalog.php` produce `g3-abh-dmo-catalog-sanitized.json`; el artifact permanece 14 días. Cualquier error de API deja `status=failed` y diagnóstico sanitario, sin poner un gate como aprobado.
- `tests/g3-tenant-dmo-catalog-contract.php` prueba entradas sintéticas, errores, inyección de hosts, parámetros, archivos, ausencia de datos sensibles, y compatibilidad PHP 7.1/8.5 sin acceder a Salesforce.

## Cómo consultar el resultado

En GitHub, `Actions → ABH Data 360 DMO Catalog Read Only`, abrir el run que corresponda a **`main`**. Si se ejecuta correctamente, revisar el artifact `g3-abh-dmo-catalog-readonly-...` y sus flags sanitizados. No copiar secretos ni respuestas completas. Un resultado **metadata_read_success_review_only** certificará únicamente los endpoints y campos presentes en ese instante.

En caso de error `g3_dmo_http_403` o `g3_dmo_http_404`, no es prueba de que ABH carezca de licencias, DMOs o relación; puede significar falta de acceso, versión o recurso no expuesto por esa identidad. Consultar con administrador sin crear nuevas credenciales por defecto.

Fuentes oficiales:
- [Data 360 Connect REST: Get DMO, relationships](https://developer.salesforce.com/docs/data/connectapi/references/spec?meta=type%3AOutput+D360+Node+Input)
- [DMO metadata / relations version](https://developer.salesforce.com/docs/data/connectapi/references/spec?meta=type%3AData%2BCloud%2BIdentity%2BResolution%2BConfig%2BPatch%2BInput)

## Qué seguirá bloqueado

Aunque los tres GET respondan, **G3-05/G3-06 seguirán pendientes** hasta que propietario y arquitecto Data 360 confirmen categoría, PK, Data Space, mapeo DLO→DMO por campo, DMO relación correcta, huéspedes/borrados y supresiones. G3-01/02/03/04/07/08 necesitan aprobaciones independientes; G4–G8 aún bloqueados.

**Ningún cambio al módulo BC 0.7.1 Test, schemas YAML, SQL, cliente, pedido, importación, fuente PST, Data Stream, campañas ni permisos. Sin ZIP ni reinstalación.**
