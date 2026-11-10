# Multi-installation source identity and UTC staging contract

Status: OFFLINE ONLY - no live Data Cloud ingestion or marketing sends.

Two PrestaShop websites can both have shop_id=2 and customer_id=17.
Each website must use a different, stable installation namespace.
The resulting source_keys are site_alpha:ps_2_17 and site_beta:ps_2_17.
Global uniqueness across the target Salesforce space must be maintained
by an operator-controlled registry; uniqueness is not achievable by merely
validating a locally configured string.

Rules:

1. Require an explicit immutable installation namespace, 2-32 safe chars.
   Never derive it automatically from a DB name, table prefix or domain.
2. Resolve shop timezone by source shop ID and the installation's own
   PrestaShop PS_TIMEZONE context. No global Europe/Madrid fallback.
3. Use identical site/shop-scoped keys in source and relationship fields.
4. Reject missing zone, ambiguous DST wall-clock times, cross-site
   relationship keys and duplicate IDs before constructing a payload.
5. The optional StagedInstallationPipeline is pure offline code and is
   NOT wired to current live PrestaShop workers or Data Cloud transports.
6. A verified current store timezone does not prove that historical
   DATETIME rows were written in that timezone. Audit per installation.
7. Uploading any schema and enabling records/campaigns needs an
   independently approved policy for consent, retention, erasure,
   source key uniqueness, data stream refresh, DLO/DMO and replay safety.

For a later commercial release, add a setup/admin page that provisions
and persists a stable namespace, discovers shops and their timezones,
and performs read-only metadata checks before allowing synchronization.

Salesforce Ingestion API documentation:
https://developer.salesforce.com/docs/data/data-cloud-int/guide/c360-a-create-ingestion-data-stream.html
