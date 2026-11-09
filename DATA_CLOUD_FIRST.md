# ABH: Data Cloud first integration

As of 2026-11-09 ABH has confirmed it has Data Cloud. Salesforce renamed
Data Cloud to Data 360 on 2025-10-14: they are not separate products.
Data Cloud availability alone does not certify Marketing Cloud Growth / Next
campaign sending, licensing, messaging credits or activation.

## Architecture, not yet enabled

PrestaShop normalized events -> existing queues, retries, consent and order checks
-> future Data Cloud adapter (disabled) -> Salesforce OAuth with cdp_ingest_api
scope -> Data Cloud token exchange (/services/a360/token) -> Ingestion API
connector and deployed Data Streams -> Data Lake Objects -> mapped Data Model
Objects -> optionally segments / activation ONLY with verified marketing rights.

The existing OAuth with api scope and Core REST sObject Describe certifications
do not prove access to the Data Cloud Ingestion API. No live record writes have
been attempted, and the existing Engagement adapter remains isolated.

## Offline draft delivered

- pssalesforcebridge/src/DataCloud/IngestionRecordMapper.php maps normalized
  PrestaShop customer and order arrays into flat candidate records. No
  network code, secrets, queue hookup, emailing or data writes.
- schemas/data-cloud-ingestion-DRAFT.yaml proposes PSCustomer (Profile),
  PSOrder (Other), PSCartEvent (Engagement) using OpenAPI 3.0.3. The cart
  event mapper is intentionally NOT implemented until revalidation,
  conversion suppression and UTC event-time rules are approved.
- tests/data-cloud-mapping-contract.php checks deterministic keys,
  cross-shop and cross-installation separation, zero consent inference,
  source timestamps and invalid records.
- Merchant namespace is required: ABH may host several independent
  PrestaShop installations and shop IDs are not globally unique.

newsletter_flag and partner_optin_flag are raw PrestaShop values,
NOT proof of permission to send any marketing email.
source_updated_local is unzoned TEXT: do not treat it as an approved
Data Cloud record-modified datetime.
No recovery URLs or single-use tokens should be ingested as plain data.

## Data Cloud Architect gate (DO NOT upload this draft yet)

1. Verify Data Cloud Setup > Ingestion API and the target Data Space.
2. Agree source object names, field types, retention, identity model,
   primary keys, event time conversion, source data space and mappings.
   Schema fields and types are difficult or impossible to remove/change after
   upload, so this document is deliberately NOT tenant-ready.
3. After approval, upload finalized OpenAPI 3.0.x schema, create the Ingestion
   API Connector, create and deploy the Data Streams, then map source Data
   Lake Objects to appropriate Data Model Objects.
4. Confirm least-privilege permission cdp_ingest_api and choose a supported
   external client app OAuth flow. Salesforce recommends JWT bearer for
   the Ingestion API. Do not alter the working api-only OAuth app.
5. Certify token exchange separately and safely: Salesforce Core token
   becomes Data Cloud token and tenant-specific instance_url.
6. After the connector exists and mapping is approved, a **separately
   authorized** synthetic validation can POST to /actions/test (validation,
   no ingestion). Then permit controlled streaming only with its own
   explicit write authorization and data/credit/retention controls.
7. Data Cloud streaming POST 202 means accepted for async processing,
   not that the data is visible, segmented or emailed.
8. Verify separately whether ABH has Marketing Cloud Growth / Next or
   any send-capable marketing product and credits. Data Cloud alone
   cannot be treated as an email campaign engine.

Sources:
https://help.salesforce.com/s/articleView?id=data.c360_a_data_cloud.htm&type=5
https://developer.salesforce.com/docs/data/data-cloud-int/guide/c360-a-ingestion-api-schema-req.html
https://developer.salesforce.com/docs/data/data-cloud-int/guide/c360-a-connect-an-ingestion-source.html
https://developer.salesforce.com/docs/data/data-cloud-int/guide/c360-a-create-ingestion-data-stream.html
https://developer.salesforce.com/docs/data/data-cloud-int/guide/c360-a-create-ingestion-api-connected-app.html
https://developer.salesforce.com/docs/marketing/marketing-cloud-growth/guide/mc-manage-objects-create-streaming-data-cloud.html
