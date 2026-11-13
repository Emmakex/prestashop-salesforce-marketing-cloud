# G3 · Hoja de decisión de propietario — PSCustomer / PSOrder (ABH)

**Estado: PENDIENTE DE APROBACIÓN · REVIEW ONLY · NO PUBLICAR.**  
**Comercio:** ABH / Barcelona Confort. **Destino:** Salesforce Marketing Cloud **Growth/Next + Data 360** (no Engagement).  
**Referencia operativa:** [issue #45](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/issues/45).  
**Fuente contractual:** [dossier campo por campo](G3_PST_DATA_OWNER_APPROVAL_PACK.md), [inventario versionado](../config/g3-pst-schema-review.json) y [snapshot de integridad](G3_REVIEW_FINGERPRINT_SNAPSHOT.md).

## Qué necesita decidir cada responsable

Esta hoja separa **propuesta técnica**, **evidencia necesaria** y **decisión humana**. No atribuye permisos al indicador newsletter, no fija plazos legales, no transforma fechas históricas inciertas, no elige el esquema automáticamente y no invoca ningún servicio de Salesforce.

| Gate | Propuesta técnica para revisar (no aprobada) | Evidencia / respuesta imprescindible | Responsable que debe validar |
|---|---|---|---|
| **G3-01 · Identidad** | Registrar un `source_namespace` **inmutable y globalmente exclusivo por instalación**, asignado desde un inventario, no derivado de dominio/DB/email; componer `source_namespace:shop_id:entity_type:entity_id`. | Identificador asignado a BC sin colisión, custodia del registro, qué ocurre al clonar/migrar una tienda y tratamiento multishop. | Responsable técnico + de datos |
| **G3-02 · Finalidad y minimización** | Mantener IDs internos separados de perfil/contacto. Tratar `newsletter_flag` y `partner_optin_flag` como señales fuente, **nunca consentimiento de envío**. Mantener nombres y email fuera de cargas hasta acreditar necesidad. | Finalidad y base aplicable **por campo** para los 24 candidatos; justificación de email/nombres, oposición y estado actual del permiso específico Growth. | Responsable de datos + privacidad |
| **G3-03 · UTC y origen** | Conservar `source_updated_local` solo para diagnóstico. No convertir el `DATETIME` histórico a UTC por asumir `Europe/Madrid`; cuarentena de históricos sin procedencia. `captured_at_utc` de hooks futuros **no** equivale a modificación fuente. | Origen de escrituras históricas, importaciones, zonas previas, DST y sincronización del reloj que escribe eventos nuevos. | Responsable técnico |
| **G3-04 · Actualización / refresh** | No elegir aún Full ni Partial. Un contador de aceptación local no es versión de origen; Partial exige `Record Modified` fiable y control de replays/obsolescencia. Full también requiere un contrato de reconciliación. | Política verificable de causalidad, revisiones, reintentos, borrados y frescura; elección explícita de modo y `Record Modified`. | Responsable de datos + arquitecto Data 360 |
| **G3-05 · PK / categorías / Data Space** | Propuesta: `PSCustomer` Profile, `PSOrder` Other, PK `source_key`, Data Space observado `default`; **ninguna opción aprobada**. | Validación de la configuración de objetos y claves en el tenant concreto, sin crear streams. | Arquitecto Data 360 |
| **G3-06 · Relaciones y mapeo** | Relacionar pedido y cliente solo por `customer_source_key` dentro de la **misma instalación y tienda**; no unir por email. `cart_source_key` es referencia, sin PSCartEvent aprobado. | Mapeo DLO→DMO por campo, relación y reglas de identidad/dedupe con pruebas sintéticas. | Arquitecto Data 360 |
| **G3-07 · Privacidad y conservación** | Política por tipo de dato y finalidad; no retener indefinidamente ni conservar secretos tras retirada. Separar bajas, erasure en tienda, cola, Data 360, logs y backups. | Plazos reales aprobados por clase de dato; supresiones, derechos, eliminación, responsabilidades y reconciliación. **No existe cifra de días aprobada**. | Privacidad + responsable de datos |
| **G3-08 · Esquema final** | Comparar **v0.2 (22 campos)** y **v0.3 (24 campos con UTC)**; no seleccionar ninguna hasta resolver G3-01…07. Previsualizar en PST **sin guardar**. | Una versión única, SHA-256 exacto, revisiones previas cerradas y conformidad de propietario + arquitecto. La autorización G4 de publicación será **separada**. | Responsable de datos + administrador Data 360 |

## Dos opciones de esquema, ninguna autorizada

| Archivo | Huella SHA-256 del borrador actual | Principal cautela |
|---|---|---|
| `schemas/ABH_PST_SCHEMA_REVIEW.yaml` — v0.2 | `8595c2f9acb5be388ba3b2ec156ec65379ab04bf5ebf3718e63231cb06b43e9d` | Sin `source_updated_utc`; no existe Record Modified fiable aprobado |
| `schemas/ABH_PST_SCHEMA_UTC_CANDIDATE.yaml` — v0.3 | `3405a884d9cbe7691ad363825f3a730cf88a6742117925113a4f36633cda05f0` | `source_updated_utc` no se puede derivar de históricos ambiguos; aún no hay procedencia/refresh aprobados |

**Inventario G3 (24 campos propuestos):** `config/g3-pst-schema-review.json`, SHA-256 `e1b1894cce21afce84a5806edca4ea5645a672a041c46805340895a189fcc4db`.

## Registro de respuesta humana — plantilla, sin firmas

El responsable debe contestar en el [issue #45](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/issues/45), o dejar una ADR revisada que identifique **cada G3-01…G3-08**, seleccionando **aceptar propuesta / modificar / rechazar / pendiente**, explicando razón y evidencia. El registro deberá indicar el rol aprobador, fecha y **commit + huella exacta**; no publicar identificadores de clientes, credenciales ni capturas con datos personales.

**No hay decisiones aprobadas en esta hoja.** Las dudas legales, de retención, identidad y zonas horarias exigen evidencia y validación humana; no se sustituyen por tests técnicos ni por el silencio del propietario.

## Estado verificable y límites de este corte

- **Verificado:** OAuth Salesforce Core y token exchange Data 360 sin escrituras; connector PST observado `Schema Required`; 24 campos candidatos inventariados y 8 decisiones G3 pendientes; PrestaShop 0.7.1 BC en Test, zona actual `Europe/Madrid`.
- **Propuesto:** namespace registrado, minimización, cuarentena de timestamps inciertos, categorías/PK y mapa DLO→DMO.
- **Bloqueado:** publicar v0.2/v0.3, crear streams, Data 360 ingest, backfill, unir contactos por email, Flow, audiencias y envíos Growth.

**Cerrar G3 no autoriza G4 ni G5–G8. No se necesita SQL de producción ni reinstalar el módulo 0.7.1.**
