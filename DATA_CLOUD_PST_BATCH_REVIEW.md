# ABH PST — safely offline batch envelope for Ingestion API

**NOT ENABLED. DO NOT UPLOAD THE REVIEW YAML OR INGEST RECORDS YET.**

The already-certified OAuth exchange (#42655156432) authenticates Data Cloud,
but connector PST still requires an approved schema and deployed Data Streams.

New offline IngestionPayloadBuilder accepts only PSCustomer and PSOrder records
from the existing normalized mapper. It emits the Data Cloud Ingestion API
envelope with a data array, exact source-schema field names, no more than 50
records and no more than 200 KB per payload. It validates identity isolation
across installations and shops, full record fields/types, email/currency,
cross-shop relationships, duplicate records, and malformed JSON/encoding.

The Python schema CI gate compares the reviewed YAML fields and types with
both the synthetic PHP mapper output and the batch builder's PHP allowlist.
All tests use synthetic data. The code has **no network or secret access**.

**ABH data-owner approvals still needed before creating streams:**
- Field-level data minimization, GDPR retention/deletion and consent policy
- source_key uniqueness and default Data Space
- PSCustomer Profile / PSOrder Other category and DLO-to-DMO mapping
- Record Modified UTC timezone/ordering and immutable stream Refresh Mode
- Hash-pinned final YAML, tenant preview and explicit save approval

Salesforce requires source schema field names, not Data Lake/Model API names.
Payload validation uses POST /api/v1/ingest/sources/{source}/{object}/actions/test
and is a separate later step that must not start until PST has an approved
schema and stream. A 200 response to validation does not ingest data.
Streaming POST without /actions/test ingests records asynchronously and
remains disabled. The user-approved marketing send flow is also out of scope.

Official documentation:
https://developer.salesforce.com/docs/data/data-cloud-int/guide/c360-a-ingestion-api-schema-req.html
https://developer.salesforce.com/docs/data/data-cloud-int/guide/c360-a-create-ingestion-data-stream.html
https://developer.salesforce.com/docs/marketing/marketing-cloud-growth/guide/mc-manage-objects-create-streaming-data-cloud.html
