# ABH PST — PrestaShop Data Cloud source schema review gate

Status: **REVIEW ONLY — DO NOT UPLOAD TO DATA CLOUD YET.**

ABH created an Ingestion API connector:
- Name: ABH PrestaShop Data Cloud
- Alias: \`PST\`
- Source API name: \`ABH_PrestaShop_Data_Cloud\`
- Connector state in Salesforce: \`Se requiere esquema\` (Schema Required).

\`schemas/ABH_PST_SCHEMA_REVIEW.yaml\` is the new *minimal candidate* for two
Data Cloud source objects only: \`PSCustomer\` and \`PSOrder\`. This isn't the
old, more speculative \`data-cloud-ingestion-DRAFT.yaml\` with \`PSCartEvent\`.
Do not upload either file until the ABH Data Cloud Architect approves the
field dictionary and stream categories/keys.

## Offline code-to-schema gate

The schema uses OpenAPI 3.0.3, flat scalar fields, safe ASCII API names, no
reserved words/double underscores, and the Salesforce Documentation's
conservative 39-character field / 79-character object name limits.

\`scripts/ci/validate-pst-ingestion-schema.py\` parses the YAML with PyYAML,
checks schema constraints and synthetically generated records from
\`IngestionRecordMapper\` (via \`tests/data-cloud-schema-fixture.php\`),
then exercises failure cases. The review gate forbids a third event object,
unmapped fields, identity changes, nested records, unsupported types,
email sends and recovery tokens.

Customer fields (11): source_key, source_namespace, shop_id, customer_id,
email, first_name, last_name, newsletter_flag, partner_optin_flag,
active_flag, source_updated_local.

Order fields (11): source_key, source_namespace, shop_id, order_id,
customer_source_key, cart_source_key, currency, total_paid, paid_flag,
state_id, source_updated_local.

Each payload uses source key \`{namespace}:ps_{shop_id}_{id}\`, isolating
merchant installations and multishop data. \`source_updated_local\` remains
a string, *not* a Record Modified timestamp; don't activate partial refresh
or treat it as UTC until timezone and ordering rules are certified.
newsletter_flag and partner_optin_flag are raw source booleans, not a legal
permission to send campaigns.

Object category proposals:
- PSCustomer → Profile; primary key \`source_key\`.
- PSOrder → Other (pending admin confirmation); primary key \`source_key\`.
- PSCartEvent → not created, because event time (UTC ISO 8601 Zulu), event
  lifecycle, dedupe/suppression and consent remain design work.

## ABH admin acceptance before upload

1. Confirm the chosen **Data Space** and data residency/retention policy.
2. Sign off on the full field dictionary and exact source schema.
3. Confirm if \`PSOrder\` should be Other or a business-appropriate alternative;
   choose refresh strategy and timezone handling before stream deployment.
4. Confirm target Data Model Object mapping, relationship, source
   deduplication, deletion strategy and access controls.
5. Verify Data Cloud OAuth scope \`cdp_ingest_api\` / token exchange
   with a dedicated identity (Core Salesforce OAuth with \`api\`
   scope alone is insufficient).
6. Only after the above sign-off, upload the approved YAML from a
   versioned commit and *preview* before saving; then create/deploy the
   corresponding Data Streams and run a separate synthetic API validation
   without writing customer records.

Until this gate passes, **no schema upload, no data streams, no record writes,
no email marketing activation**. Existing PrestaShop Engagement release 0.7.0
remains unchanged.

Salesforce references:
- https://developer.salesforce.com/docs/data/data-cloud-int/guide/c360-a-ingestion-api-schema-req.html
- https://developer.salesforce.com/docs/data/data-cloud-int/guide/c360-a-create-ingestion-data-stream.html
- https://developer.salesforce.com/docs/data/data-cloud-int/references/data-cloud-ingestionapi-ref/c360-a-api-get-started.html
