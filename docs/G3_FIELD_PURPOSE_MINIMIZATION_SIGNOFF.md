# G3-02 y G3-07 · Revisión concreta de finalidad y minimización de datos

**Comercio:** ABH / Barcelona Confort. **Destino:** Marketing Cloud **Growth/Next + Data 360**, NO Engagement.  
**Estado:** `PENDING_DATA_PRIVACY_OWNER`. **No es consentimiento, revisión legal aprobada ni autorización para exportar datos.**

Este documento concreta las **24 decisiones de campo** que el responsable de datos y privacidad debe resolver antes de publicar `PSCustomer` y `PSOrder` en la fuente `PST` de Data 360. El registro versionado es [`config/g3-field-purpose-owner-decisions.json`](../config/g3-field-purpose-owner-decisions.json); el inventario base y las huellas de los dos YAML siguen **sin modificaciones**, conforme al [snapshot G3](G3_REVIEW_FINGERPRINT_SNAPSHOT.md).

## Decisión de minimización propuesta — no aprobada

Separar tres ámbitos: **identificación técnica de origen**, **datos con finalidad analítica o comercial por justificar** y **autorización de contacto/marketing**. La necesidad de identificar un registro no demuestra base jurídica para subir su email, nombre, importe ni preferencias. Tampoco implica que debamos crear un perfil unificado por dirección de email.

| Objeto | Campo | Propuesta para decisión del responsable | Evidencia que falta | Estado |
|---|---|---|---|---|
| `PSCustomer` | `source_key` | Identidad fuente; revisar necesidad | Approve globally stable pseudonymous source key and use without cross-merchant merges | Pendiente |
| `PSCustomer` | `source_namespace` | Identidad fuente; revisar necesidad | Approve external registry and collision handling per installation | Pendiente |
| `PSCustomer` | `shop_id` | Identidad fuente; revisar necesidad | Prove shop scope and lack of cross-shop joins | Pendiente |
| `PSCustomer` | `customer_id` | Identidad fuente; revisar necesidad | Prove necessity of source ID and lifecycle after deletion | Pendiente |
| `PSCustomer` | `email` | Retener fuera de exportación | Approve distinct contact purpose, lawful basis, suppression and erasure; no upload before review | Pendiente |
| `PSCustomer` | `first_name` | Excluir por defecto | Justify personalization purpose independently of email | Pendiente |
| `PSCustomer` | `last_name` | Excluir por defecto | Justify necessity; not automatically needed for segmentation | Pendiente |
| `PSCustomer` | `newsletter_flag` | Señal fuente, no permiso de envío | Audit per-purpose provenance; never equate source flag with Growth send authorization | Pendiente |
| `PSCustomer` | `partner_optin_flag` | Señal fuente, no permiso de envío | Prove distinct third-party offer purpose; cannot authorize first-party cart recovery | Pendiente |
| `PSCustomer` | `active_flag` | Justificar necesidad comercial | Explain whether source active state is needed for suppression/data quality | Pendiente |
| `PSCustomer` | `source_updated_local` | Solo diagnóstico, no Record Modified | Prove historical timezone; not a Data 360 Record Modified field | Pendiente |
| `PSCustomer` | `source_updated_utc` | Cuarentena de fecha no probada | Validate provenance of each absolute instant, DST and refresh before choosing v0.3 | Pendiente |
| `PSOrder` | `source_key` | Identidad fuente; revisar necesidad | Prove stable global order key and no cross-installation collisions | Pendiente |
| `PSOrder` | `source_namespace` | Identidad fuente; revisar necesidad | Approve external namespace registry and per-installation ownership | Pendiente |
| `PSOrder` | `shop_id` | Identidad fuente; revisar necesidad | Prove shop scope for order/customer joins | Pendiente |
| `PSOrder` | `order_id` | Identidad fuente; revisar necesidad | Approve order source identity and deletion handling | Pendiente |
| `PSOrder` | `customer_source_key` | Revisar relación y alcance | Prove same installation/shop relationship and mapping DLO to DMO | Pendiente |
| `PSOrder` | `cart_source_key` | Excluir por defecto | No PSCartEvent approved; justify reference without publishing cart stream | Pendiente |
| `PSOrder` | `currency` | Justificar necesidad comercial | Show approved reporting/segmentation purpose for currency | Pendiente |
| `PSOrder` | `total_paid` | Justificar necesidad comercial | Justify commercial value, amount precision and data minimization | Pendiente |
| `PSOrder` | `paid_flag` | Justificar necesidad comercial | Prove transactional status mapping without inferring marketing consent | Pendiente |
| `PSOrder` | `state_id` | Justificar necesidad comercial | Prove per-store source status semantics and explicit cross-shop mapping | Pendiente |
| `PSOrder` | `source_updated_local` | Solo diagnóstico, no Record Modified | Historical DATETIME origin unverified; never choose as Record Modified | Pendiente |
| `PSOrder` | `source_updated_utc` | Cuarentena de fecha no probada | Require verified source modification semantics, DST and replay policy | Pendiente |

El registro marca para **cada campo**: `decision=pending`, `approved_processing_purpose=null`, `lawful_basis_verified=false`, `retention_approved=false`, `export_to_data_360_authorized=false` y `growth_send_authorized=false`.

La recomendación **“considerar para identidad” no significa permitir su subida**. Los campos `source_updated_local` no pueden ser `Record Modified`; `source_updated_utc` v0.3 queda pendiente de procedencia histórica fiable. Cualquier reconsideración de nombres, email, importes o referencias al carrito requiere aprobar su necesidad antes de escoger un esquema final. El propio `newsletter_flag` o `partner_optin_flag` jamás da autorización Growth por sí solo.

## G3-07: conservación, oposición, borrado y recuperación

No existe ninguna cifra de días aprobada. Para cada clase de datos y finalidad, el responsable debe definir **plazo, inicio del cómputo, excepción legal justificada, ubicación, borrado, supresión de envíos, trazas y reconciliación**.

Esto incluye PrestaShop, el futuro outbox Growth, Data 360, posibles DLO/DMO, logs, backups y destinos de marketing. La baja de newsletter y el borrado de perfil no equivalen automáticamente a eliminar un pedido con obligaciones de conservación; debe existir una política específica. El prototipo de CI conserva observaciones bloqueadas, pero su retención no está autorizada en BC.

## Qué responder para desbloquear la revisión

El [issue #45](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/issues/45) sigue siendo el registro único del gate humano G3. Se necesita una decisión por campo: **conservar, excluir, sustituir, o pendiente**, con finalidad/base, responsable y justificación. Por clase de datos también falta aprobar retención y el proceso de erasure. Solo después puede diseñarse **una versión final** del YAML y someterla a revisión de huella SHA-256. Publicar schema, crear streams, hacer ingesta y activar campañas requieren **autorizaciones distintas**.

## Control automático y límites

El verificador `python3 scripts/ci/verify-g3-field-purpose-owner-decisions.py` compara el listado con los 24 campos reales del inventario G3. Rechaza ausencia o reordenación de campos, falsas aprobaciones, nueva habilitación de Data 360, cambio de significado de preferencias, exposición accidental de datos personales de muestra y la selección prematura del esquema. Los tests negativos son `tests/test_g3_field_purpose_owner_decisions.py`.

**Sin llamadas a Salesforce, SQL de BC, secretos, contactos reales o modificación de `pssalesforcebridge/`.** El módulo distribuido permanece en **0.7.1**, Test. Ni se instala un ZIP ni se desbloquea G3-02 o G3-07 automáticamente.
