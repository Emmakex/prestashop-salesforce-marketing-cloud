# Marketing Cloud Growth: sObject field and permission metadata gate

## What is certified already

ABH client-credentials OAuth succeeded without Salesforce writes. A subsequent
read-only metadata probe succeeded using REST API v68.0. Standard object names
(Contact, Lead, Campaign, Individual, ContactPointEmail, ContactPointTypeConsent,
Order) were visible, but visibility **does not certify object CRUD or FLS**.

## New manual gate

Actions → **Salesforce Growth Schema Discovery** → Run workflow on `main`.

The workflow reuses the existing `salesforce-growth-certification` Environment
without adding secrets. It performs only HTTPS **GET** metadata requests:

- `/services/data/` (API version)
- `/services/data/vXX.X/limits` (API metadata)
- `/services/data/vXX.X/sobjects` (list metadata)
- `/services/data/vXX.X/sobjects/{allowlisted standard object}/describe`

The last endpoint is called at most seven times, for the seven standard object
types listed above, and only when the object was visible in the global
description. The endpoint is selected exclusively from code-defined names.
No arbitrary object/path/user-input endpoint, no record rows and no mutations.

## Stored evidence

Persist ONLY fixed candidate standard-field names and booleans for:

- visibility, object `createable`, `updateable`, `queryable`;
- whether the object description exposes *any* External ID metadata field;
- `present`, `createable`, `updateable`, `external_id` for a fixed
  candidate field allowlist, to identify potential mapping blockers.

Never persist raw API response content, arbitrary custom field names,
field descriptions, picklist values, URLs, tokens, email addresses, customer
records or usage quota numbers. The result explicitly marks Data 360
entitlements, Data 360 ingestion and Growth Flow entry as `not_evaluated`.

Fail closed on unexpected response shape, HTTP errors or unsafe Salesforce
domains; logging uses only allowlisted diagnostic codes. OAuth and Salesforce
metadata traffic use TLS and reject redirect hops.

### Do NOT conclude from a passing run

- Metadata `createable/updateable` flags do not prove a safe business
  mapping, permission at execution time, successful upsert, relationship
  requirements, required fields, licensing, or marketing activation.
- A boolean `any_external_id_metadata` **does not identify** the
  appropriate key field for `ps_{shop_id}_{entity_id}`; that selection
  needs admin review and object-identity policy.
- Neither Data 360 permissions nor automated Growth Flow entry are tested.

Next design gate: tenant administrator confirms the marketing record model,
external ID mapping, consent/opt-out source and suppression rule, Data 360
availability, and an ABH test contact. Only then develop optional
per-shop Growth REST/ingestion adapters, still defaulting to test mode,
with separate staging-only live-write certification.

This discovery changes no PrestaShop Engagement 0.7.0 delivery path.

## Official Salesforce reference

https://developer.salesforce.com/docs/platform/api-rest/guide/resources-sobject-describe.html
