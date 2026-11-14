# G3 — PSCustomer / PSOrder: propuesta de claves tipadas (sin publicar)

**Estado al 14/11/2026:** contrato fuente sintético validable, **NO seleccionado por el arquitecto Data Cloud**. Único destino: Marketing Cloud Growth + Data Cloud. El piloto BC sigue con 0.7.1 Test. Este corte no ejecuta G4/G5.

## Problema resuelto a nivel de propuesta local

El esquema PST congelado v0.2 emite `ns:ps_shop_id` para ambas entidades. Los identificadores numéricos de clientes y pedidos pueden coincidir. Esa coincidencia **no demuestra por sí sola un fallo funcional en Salesforce**, porque los DLO tienen ámbitos separados, pero complica la identidad al crear relaciones PK/FK y mantener consistencia con el router Growth.

**Contrato candidato v0.4 (22 campos, misma estructura que v0.2):**

| Elemento | Ejemplo para namespace `growth_ci_abhtwo`, tienda 2, IDs 44 | Decisión de seguridad |
| --- | --- | --- |
| `PSCustomer.source_key` | `growth_ci_abhtwo:2:customer:44` | PK tipada del cliente, sin email como identificador |
| `PSOrder.source_key` | `growth_ci_abhtwo:2:order:44` | PK tipada del pedido, disjunta de la anterior |
| `PSOrder.customer_source_key` | `growth_ci_abhtwo:2:customer:44` | FK de origen exactamente igual a la PK del cliente, mismo namespace y tienda |
| `PSOrder.cart_source_key` | `growth_ci_abhtwo:2:cart:91` | Solo referencia de origen, **sin** PSCart Data Stream ni unión autorizada |

La capa pura `pssalesforcebridge/src/DataCloud/GrowthTypedIdentityCandidate.php` recibe **únicamente el preview sintético v0.2 gated** y produce dos envelopes de propuesta v0.4 en memoria. Revalida el contrato base con el builder v0.2, que sigue rechazando identidades tipadas hasta que haya una selección aprobada del esquema. Rechaza PII real, diferencias de namespace/tienda, huérfanos, claves manipuladas, tipos/campos nuevos, cambios de router y cualquier bandera de autorización distinta de `false`.

La simulación conserva `newsletter_flag` como atributo sin autorización de campañas, `paid_flag=false` como contención sintética y `source_updated_local` sin inferir UTC ni usarlo como `Record Modified`. No se conectan los hooks del módulo, no hay SQL, transporte, persistencia, flujo Growth ni envío.

## Revisión de los dos contratos

- **v0.2 congelado:** `schemas/ABH_PST_SCHEMA_REVIEW.yaml`, SHA-256 `8595c2f9acb5be388ba3b2ec156ec65379ab04bf5ebf3718e63231cb06b43e9d`. No se altera ni cambia su aprobación pendiente.
- **v0.4 solo candidato:** `schemas/ABH_PST_TYPED_IDENTITY_CANDIDATE.yaml`. Mismos 22 nombres/tipos de campos; cambia el **formato de PK/FK** y sus restricciones para firma. **No sustituye al contrato v0.2** ni es un fichero apto para publicar en PST mientras siga G3.
- **Prueba PHP:** `php tests/g3-growth-typed-identity-contract.php` (requiere únicamente el fixture sintético interno). Incluye multiinstalación/multitienda, IDs cliente/pedido iguales y distintos, reintentos, fuente huérfana/cruzada, alteración de permisos y coexistencia de v0.2/v0.4; PHP 7.1+ y familias PrestaShop 1.7/8/9.
- **Prueba YAML/SHA:** `python -m unittest discover -s tests -p 'test_g3_typed_identity_schema.py'`.

## Única decisión técnica que sigue abierta en este punto

El arquitecto debe confirmar (a) el formato tipado como **PK de cada DLO** con namespace global único gestionado; (b) la **FK cliente–pedido** solo en mismo origen/tienda; y (c) cómo mapear a objetos/relaciones **reales** en el Data Space de ABH, sin interpretar `customer_source_key` como enlace directo a `Individual` por defecto, ni crear cuentas intermedias ficticias. También debe validar categoría del stream, estrategia Full/Record Modified, invitados y borrados.

**No se deduce** del test local que existan relaciones activas `SalesOrder`–`Individual` en el tenant. Mientras no consten las tres conformidades del expediente #45, G3 permanece pendiente y todas las autorizaciones G4/G5/G6/G7 son `false`. No repetir OAuth/Data Cloud, ni pedir SQL de producción.
