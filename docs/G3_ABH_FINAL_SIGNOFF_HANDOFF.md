# G3 — Acta única de conformidades pendientes (ABH / PST)

**Fecha del corte:** 14/11/2026. **Estado:** propuesta técnica preparada; **G3 no está aprobado ni cerrado**. Único expediente: [issue #45](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/issues/45). Contrato verificable: [`g3-abh-signoff-handoff.json`](../config/g3-abh-signoff-handoff.json).

## Decisiones técnicas conservadoras listas para revisión

**1. Identidad, finalidad, privacidad.** Clave `source_namespace:shop_id:entity_type:entity_id`; namespace único e inmutable asignado por un registro entre instalaciones que **aún debe certificarse**, sin derivarlo de email/dominio/BD. No fusionar contactos por email ni usar `newsletter`/`optin` como permiso de Growth. No exportar nombres, correos, flags, carritos ni registros históricos de procedencia incierta. **Datos personales de clientes y pedidos no se envían mientras no se aprueben por campo la finalidad y base jurídica, plazo de conservación, supresión/borrado y custodia del namespace.**

**2. Datos cliente–pedido, actualización y topología.** Clave de relación local `PSOrder.customer_source_key` → `PSCustomer.source_key` solo en el mismo namespace y tienda; huérfanos o cruces se ponen en cuarentena. **Propuesta**, no selección del arquitecto: esquema v0.2 con **Full** de filas completas y **sin Record Modified**, condicionado a un proceso de relectura/reconciliación de cambios; nunca usar `date_upd` de fecha histórica incierta, `captured_at_utc` ni `accepted_sequence` como versión fuente. Conservar **C solo como contención provisional**: no relacionar `SalesOrder` con `Individual` hasta comprobar mapping y aristas estándar activas/inactivas tipadas, incluidos posibles objetos intermedios. C no permite segmentación de compradores por pedidos y no completa por sí sola el objetivo comercial. El arquitecto debe dejar constancia de PK/FK, categoría de cada Data Stream, Data Space, modo de actualización y tratamiento de invitados/borrados.

**3. Esquema.** Candidato técnico v0.2 `schemas/ABH_PST_SCHEMA_REVIEW.yaml`, **22 campos**, `PSCustomer` y `PSOrder`, huella SHA-256 `8595c2f9acb5be388ba3b2ec156ec65379ab04bf5ebf3718e63231cb06b43e9d`. **No es esquema definitivo ni permiso de publicación**. Antes de finalizar G3: revisar contenido completo y preview sin guardar de `PST`, aprobar SHA exacto y conformidad de responsables. Si cambia un campo, hay que congelar una nueva huella.

## Tres constancias necesarias, sin repetir auditorías

| Firma requerida | Contenido que debe confirmar y quedar documentado | Estado |
|---|---|---|
| Responsable de datos + privacidad | Namespace asignado, finalidad/base por campo, política de minimización, plazos de conservación y ciclo de borrado/supresión | **Pendiente** |
| Arquitecto Data 360 | Categorías PK/FK, relación cliente/pedido ABH, manejo de referencias sin relación, Full/revisión causal, Data Space; sin crear ni activar nada | **Pendiente** |
| Responsable de datos + administrador Data 360 | Aceptación explícita de v0.2 o revisión posterior, SHA-256 definitivo y evidencia de preview PST | **Pendiente** |

Esta hoja **no** registra nombre ni firma del aprobador porque en el chat únicamente se ha indicado «continuemos». Autorizar el trabajo para preparar la decisión **no equivale a aprobar la política de privacidad o el mapping de Salesforce**. No inventaremos fechas de firma ni días de conservación.

## Mecanismo de cierre y seguridad

Las tres constancias se anotarán en el expediente #45 y en una ADR G3 definitiva con identidad/rol, fecha, decisión, límites y evidencia. El estado machine-readable de [`config/g3-abh-signoff-handoff.json`](../config/g3-abh-signoff-handoff.json) permanecerá `pending_formal_signoff` hasta que estén todas; CI `verify-g3-signoff-handoff.py` rechaza cualquier modificación que intente llamar aprobación a esta **versión pendiente**. Una aprobación real requerirá actualizar el contrato y sus pruebas en un PR separado, sin convertir automáticamente esa aprobación en permisos de G4.

**Sin excepción**: `schema_upload=false`, `create_data_streams=false`, `save_dlo_dmo_mapping=false`, `data_360_ingestion=false`, `historical_backfill=false`, `identity_stitching=false`, `marketing_sends=false` y `outbound_worker=false`. Tampoco se habilita producción.

**Hechos ya revisados que NO hay que repetir:** OAuth Core y token Data 360; PST `Schema Required`; BC 0.7.1 Test con `Europe/Madrid` y consent mode `marketing_optin`; inventarios; YAML v0.2/v0.3 y SHA; relación local source-to-source; 527 relaciones observadas en el catálogo DMO con paginación completa; Runtime PrestaShop 1.7.8.11/8.2.8/9.1.5. Las 527 relaciones visibles no son prueba de que las relaciones estándar inactivas sean imposibles.

**Instalación BC inalterada.** No se requiere SQL, ZIP nuevo ni credenciales. **Se congela G4/G8** hasta que se cierre G3.
