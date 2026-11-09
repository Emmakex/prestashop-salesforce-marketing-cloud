# Marketing Cloud Growth — read-only REST capability discovery

## Reason

The ABH external client app has passed OAuth Client Credentials certification.
Marketing Cloud Growth (Marketing Cloud Next) **does not** use Marketing Cloud Engagement
Installed Packages, Data Extension Rowset APIs, or Journey Builder `/interaction/v1/events`.
An OAuth success alone cannot certify the Marketing Cloud Growth data path.

This microcut adds a **manual-only, metadata-only** discovery probe; it does not
change the 0.7.0 PrestaShop module configuration or any Engagement delivery worker.

## Manual invocation

Go to GitHub Actions → **Salesforce Growth Read Only Discovery** → Run workflow → main.

It reuses the existing protected environment `salesforce-growth-certification`:
`SF_GROWTH_ORG_URL` environment variable and `SF_GROWTH_CLIENT_ID` /
`SF_GROWTH_CLIENT_SECRET` environment secrets. No new secrets are needed.

Only three authenticated **GET** requests run against Salesforce Core:
1. `/services/data/` — discover supported REST versions
2. `/services/data/vXX.X/limits` — prove API usage metadata access
3. `/services/data/vXX.X/sobjects` — inspect object-type **names**, never records

The latest REST API version advertised by the org is selected using a strict
version-path allowlist. HTTPS, validated Salesforce instance domain, TLS
verification, timeouts and disabled redirects are mandatory.

## Evidence

The JSON workflow artifact records only: provider/stage, exact commit/run IDs,
success or a sanitized failure code, API version, booleans for REST metadata
access and *visibility* of selected standard sObject types (Contact, Lead,
Campaign, Individual, ContactPointEmail, ContactPointTypeConsent, Order),
plus explicit `not_evaluated` markers for Data 360 and Growth Flow triggers.

It does **not** retain raw API responses, API limit counts, custom-object names,
customer rows, email addresses, secrets, OAuth tokens or Data 360 data.
An object appearing in `/sobjects` does **not** establish update permission,
suitable customer matching, Salesforce licensing or Growth campaign eligibility.

A failed API permission or metadata request leaves the workflow red with an
allowlisted diagnostic. Do **not** change the OAuth app to all-users access.

## Additional ABH discovery needed (admin / marketing owner)

Before selecting an ingestion implementation, confirm without sharing personal
data or credentials:

- Which Marketing Cloud Growth edition, Data 360 entitlements and credits are
  active, and whether an Ingestion API data stream and connector exist.
- Which Contact/Lead/consent fields and external identifier should be used.
- Whether a dedicated integration user and its object-level CRUD/FLS are
  sufficient; `/sobjects` visibility is not a write permission grant.
- Which Growth Flow or segment can actually be triggered by an external
  PrestaShop cart lifecycle event (do not assume native Commerce triggers).
- Opt-in and suppression policy, deletion lifecycle, event volume and test
  recipient; decide REST sObject upsert vs Data 360 streaming only with evidence.

The existing PrestaShop queue, multishop keys, idempotency, test mode,
revalidation, one-use RecoveryUrl and order-attribution guarantees remain the
required foundation. Growth production writes and customer messages are **not**
enabled by this discovery.

## Salesforce references

- https://developer.salesforce.com/docs/marketing/marketing-cloud-growth/guide/mc-getting-started.html
- https://developer.salesforce.com/docs/marketing/marketing-cloud-growth/guide/mc-connect-apis-info.html
- https://developer.salesforce.com/docs/marketing/marketing-cloud-growth/guide/mc-manage-objects-create-rest.html
- https://developer.salesforce.com/docs/marketing/marketing-cloud-growth/guide/mc-manage-objects-create-streaming-data-cloud.html
