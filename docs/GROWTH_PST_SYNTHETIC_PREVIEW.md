# Growth → PST v0.2: vista previa sintética, sin ingesta

**Corte técnico 14/11/2026.** El producto y el único destino siguen siendo Marketing Cloud Growth + Data Cloud. El módulo 0.8.0 queda sin remitente, sin creación de streams y sin captura duradera de clientes. BC permanece 0.7.1 Test.

## Qué se ha implementado

- `pssalesforcebridge/src/Growth/GrowthPstSyntheticPreview.php` compone el **router Growth-only** con los mapeadores **PST v0.2** y su validador de payload ya existentes. Acepta únicamente objetos mínimos sintéticos de los hooks `actionCustomerAccountAdd`, `actionCustomerAccountUpdate` y `actionValidateOrder`.
- Puerta doble: CLI + `G3_PST_SYNTHETIC_PREVIEW=yes_disposable_ci_only`; marcadores internos y datos reservados `synthetic@fixture.example.invalid` / `Synthetic Fixture`. Rechaza objetos de cliente reales, más campos, tiendas cruzadas, pedidos huérfanos, IDs ambiguos, fechas imposibles, moneda/importe y flags inválidos.
- Produce **solo en memoria** dos envelopes `{"data":[...]}`, `PSCustomer` y `PSOrder`, con los **22 campos propuestos** de `schemas/ABH_PST_SCHEMA_REVIEW.yaml`. No guarda el payload ni invoca servicios externos. `paid_flag=false` es una contención conservadora **solo para el test**, no mapeo oficial de estados. `newsletter_flag` y `partner_optin_flag` siguen siendo atributos, **nunca consentimiento marketing**. `source_updated_local` no se interpreta como UTC ni Record Modified.
- CI comprueba multitienda, PHP 7.1/8.5, familias PrestaShop 1.7/8/9, reintentos deterministas, formato exacto de ambos objetos y negativos de seguridad.

## Divergencia de identidad **descubierta**, no aprobada

El router ya propone `namespace:shop_id:customer:entity_id` / `namespace:shop_id:order:entity_id`, mientras el mapeador PST v0.2 todavía produce `namespace:ps_shop_id_entity_id` en ambos objetos. Por ejemplo, el cliente 44 y el pedido 44 comparten el mismo `source_key` de v0.2, aunque sus claves tipadas sean distintas. **Esto NO autoriza** unir registros ni interpretar un campo de relación como aprobado. G3 debe cerrar el contrato de PK, FK y coexistencia antes de G4. No se modifica el YAML v0.2 ni su SHA.

## Estado

`identity_contract_approved=false`, `source_updated_utc_verified=false`, `marketing_consent_approved=false`, `schema_upload_authorized=false`, `data_cloud_ingestion_authorized=false`, `marketing_sends_authorized=false`.

Prueba aislada (sin credenciales): `php tests/growth-pst-synthetic-preview-contract.php`. **No ejecutar contra clientes ni en producción**. No hay nuevo flujo de importación, actualización de BC, acceso SQL ni necesidad de repetir OAuth.

**Continuación técnica:** [propuesta local de claves tipadas v0.4](G3_PST_TYPED_IDENTITY_PROPOSAL.md), validada con fixtures y sin alterar el contrato v0.2 congelado ni las autorizaciones G3.
