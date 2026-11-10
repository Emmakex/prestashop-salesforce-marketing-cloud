# ABH / PST — Data Cloud provisioning and schema approval matrix

**Status: schema publication blocked.** Do not upload either YAML until
approval of field retention, keys, categories and source data handling.
This document records facts observed in the ABH Salesforce UI and the
remaining decisions; it is not an admin sign-off.

## Observed and verified

| Setting | Observed value |
| --- | --- |
| Connector display name | ABH PrestaShop Data Cloud |
| Connector alias | PST |
| Source API name | ABH_PrestaShop_Data_Cloud |
| Current connector state | Se requiere esquema (Schema Required) |
| Available Data Space | default |
| Existing Data Streams (All view) | 52 |
| Data Streams returned for name search PrestaShop | 0 |
| Data Streams returned for name search PST | 0 |
| Main review schema | schemas/ABH_PST_SCHEMA_REVIEW.yaml |
| Objects and fields | PSCustomer (11), PSOrder (11) |
| Outbound writes | Disabled / not implemented |

An empty name search does not guarantee no previous streams using another
unrelated name. Do not modify or delete existing 52 ABH streams.

## Proposed deployment choices — unapproved until data owner signs off

| Setting | PSCustomer | PSOrder |
| --- | --- | --- |
| Data Space | default | default |
| Proposed category | Profile | Other |
| Proposed primary key | source_key | source_key |
| Key shape | {merchant_namespace}:ps_{shop_id}_{customer_id} | {merchant_namespace}:ps_{shop_id}_{order_id} |
| Refresh Mode | Full (NO Partial) | Full (NO Partial) |
| Record Modified Field | None for first setup; no validated UTC field | None for first setup; no validated UTC field |
| DMO mapping | Requires review | Requires review |
| Retention / erasure | Requires review | Requires review |

**Caution:** Without a Record Modified Field the stream cannot use the
source's monotonically ordered timestamp to reject stale out-of-order
updates. Resolve before enabling production ingestion; do not represent the
proposed Full refresh mode as sufficient for reliable asynchronous writes.
The Refresh Mode is immutable after Data Stream creation.

Source timestamps from PrestaShop are local/unzoned strings; treat
\`source_updated_local\` as text, **never** as a Record Modified Field.
No UTC conversion until shop timezone and DST policy are explicitly chosen.

Newsletter and partner opt-in source flags are not legal consent decisions.
Do not send marketing messages from these flags alone.
Recovery URLs must not be exported into Data Cloud.

## Data Cloud OAuth is separate from the previously certified Core OAuth

Salesforce Ingestion API requires a Core token with the \`cdp_ingest_api\`
scope, then token exchange through
\`POST {salesforce_instance_url}/services/a360/token\` with
\`grant_type=urn:salesforce:grant-type:external:cdp\`,
\`subject_token={core_access_token}\` and
\`subject_token_type=urn:ietf:params:oauth:token-type:access_token\`.

A standalone \`DataCloud/TokenExchangeClient.php\` and offline contract
test prove the form/response safety without making network requests.
No credential settings or permissions in the existing ABH Growth app are
changed by this PR.

**Admin prerequisite:** approve a separate least-privilege Data Cloud
External Client App / dedicated Run As identity, scope \`cdp_ingest_api\` and
\`api\`, plus Data Cloud permissions. Salesforce recommends JWT bearer.
Ensure **Issue JWT-based access tokens for named users** remains unchecked
for Data Cloud token exchange. Do not paste certificates, private keys,
client secrets, bearer tokens or tenant endpoint URLs into PR comments.

The new auth-only client is NOT wired into the PrestaShop worker and is NOT
configured to run against ABH. Future manual certification must have its
own protected GitHub Environment, distinct from
\`salesforce-growth-certification\`. After auth success, a separately
authorized synthetic payload test can run when the schema and Data Streams
have been approved.

## Operator acceptance checklist (admin + data owner)

- [ ] Approve customer/order field dictionary, personal-data minimization,
      retention and deletion/suppression (including GDPR).
- [ ] Confirm PK source_key/merchant namespace uniqueness and Data Space.
- [ ] Approve each stream's category, refresh mode, Record Modified policy,
      DMO mapping, and ordered update behavior.
- [ ] Establish dedicated Data Cloud external client app and least-privilege
      identity; document permissions without revealing secrets.
- [ ] Approve schema version/hash, preview *before* Save, and freeze the
      exact uploaded file to prevent untracked schema drift.
- [ ] Deploy at most one Data Stream per object, **only after** schema approval.
- [ ] Test authentication, then synthetic validation, then separately gated
      real writes; do not assume 202 Accepted means ingested successfully.
- [ ] Keep marketing sending blocked until ABH confirms a send-capable product.

Official docs:
https://developer.salesforce.com/docs/data/data-cloud-int/guide/c360-a-ingestion-api-schema-req.html
https://developer.salesforce.com/docs/data/data-cloud-int/guide/c360-a-create-ingestion-data-stream.html
https://developer.salesforce.com/docs/data/data-cloud-int/guide/c360-a-create-ingestion-api-connected-app.html
https://developer.salesforce.com/docs/data/data-cloud-int/references/data-cloud-ingestionapi-ref/c360-a-api-get-started.html

## Auth gate update (2026-11-09)

Data Cloud OAuth token exchange has now succeeded in manual GitHub run
#42655156432, using the **existing** approved Growth OAuth application and
protected secrets. A second client app is NOT required for this integration.
This replaces the earlier provisional statement that a dedicated second
app or GitHub Environment had to be created. Data Cloud payload validation,
final schema upload, Data Streams, DMO mapping and commercial sends remain
separate unapproved operations.
