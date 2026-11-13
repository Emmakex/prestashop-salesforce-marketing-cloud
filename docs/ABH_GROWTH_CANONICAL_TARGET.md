# ABH canonical target — Marketing Cloud Growth, NOT Engagement

**Canonical product decision (operator and project history, 2026-11-05 to
2026-11-13): ABH uses Marketing Cloud Growth / Marketing Cloud Next on
Salesforce Core (Lightning) plus Data Cloud / Data 360. It does NOT have a
verified Marketing Cloud Engagement tenant. Do not redirect the operator to
mc.exacttarget.com, Engagement Installed Packages, All Subscribers, SOAP
Subscriber Retrieve, Publication Lists or Journey Builder API Entry Event
as a prerequisite for this ABH integration. Those belong to a separate
Engagement target and are not certified for ABH.**

## Already completed; do not repeat

- Salesforce Core org: `https://alfabh.my.salesforce.com`
- External Client App: `ABH PrestaShop Marketing Cloud Growth`
- Approved OAuth Client Credentials Run As: `marketing@alfabh.com`
- Core token endpoint: `/services/oauth2/token`
- Existing protected GitHub environment: `salesforce-growth-certification`
  (never display secret values).
- Core OAuth certification succeeded, and read-only REST v68.0 discovery
  enumerated Contact, Lead, Campaign, Individual, ContactPointEmail,
  ContactPointTypeConsent and Order *names*; mere visibility does not prove
  access to records, write permissions or campaign send rights.
- Data Cloud (Data 360) was confirmed in the org. Connector:
  `ABH PrestaShop Data Cloud`, alias `PST`, source API name
  `ABH_PrestaShop_Data_Cloud`. Connector was observed **Schema Required**.
- The existing Growth OAuth External Client App was reused for the
  Data Cloud token exchange with `api`, `cdp_ingest_api`,
  `refresh_token/offline_access` scopes. The manual no-write token
  exchange succeeded in GitHub Action #42655156432.
- PrestaShop BC pilot: `pssalesforcebridge` 0.7.1 installed and active,
  global `PS_SFMC_MODE=test`,
  `PS_SFMC_CART_CONSENT_MODE=marketing_optin`,
  `PS_SFMC_CART_ABANDON_MINUTES=60`.
- The historical newsletter audits produce 99 distinct positive-flag
  email keys, eight contradictory email keys, **not** a certified campaign
  audience. The recent 500-cart cohort contains 7 distinct newsletter-
  positive customer IDs; neither count is proof of send permission.

## Correct architecture and unresolved gates

```text
PrestaShop 1.7/8/9 local hooks -> local queue and shop-aware identities
  -> approved Growth Core REST and/or Data 360 Ingestion adapter
  -> published PST schema + Data Streams + mapping / identity / consent
  -> Marketing Cloud Growth segments / Salesforce Flows
  -> approved test-only journey / campaigns
```

1. Review/approve `schemas/ABH_PST_SCHEMA_REVIEW.yaml`,
   tenant object names, PII minimization, merchant-scoped stable keys,
   local-vs-UTC update semantics, Data Space, retention and privacy.
   Do NOT upload or publish the unapproved schema automatically.
2. Deploy the approved Ingestion API schema and Data Streams only after
   administrator/data-owner approval, and test a **synthetic** payload
   with the Data 360 validation endpoint before permitting ingestion.
3. Discover Growth-specific consent, preference and suppression sources
   for the ABH org. A legacy Engagement `Subscriber.Status=Active` /
   All Subscribers check is NOT an ABH consent gate. Do not assume the
   presence of ContactPointTypeConsent alone proves correct purpose,
   current opt-out or CRM field write permissions.
4. Discover and validate the actual Growth campaign or Flow trigger
   supported by ABH's edition, including an approved synthetic
   recipient, throttling and recovery-window semantics.
5. Add a Growth-specific adapter behind provider selection, preserving
   the existing Engagement module adapter as an **isolated** option
   for future merchants that genuinely have Engagement. Never silently
   route ABH into `/interaction/v1/events`, the Engagement
   SOAP Subscriber API or Engagement Data Extensions.
6. Preserve `test` mode until all end-to-end gates have passed.
   No real customer backfill, automatic Data Cloud ingestion or
   campaign sending is authorized by this document.

## Documentation supersession

- `GROWTH_AUTH_CERTIFICATION.md`, `GROWTH_DISCOVERY.md`,
  `GROWTH_SCHEMA_DISCOVERY.md`, `DATA_CLOUD_AUTH_REUSE.md` and
  `DATA_CLOUD_ABH_DECISIONS.md` contain the ABH-specific certified history.
- `JOURNEY_BUILDER.md`, `DATA_EXTENSION_*.md`,
  `SFMC_LIVE_CERTIFICATION.md`, and
  `docs/BC_SFMC_SUPPRESSION_READINESS.md` concern the *legacy
  Engagement-capable adapter*, not BC's verified Growth product.
- PR #40's `tests/review/SfmcSubscriberStatusParser.php` is an
  **offline parser for Engagement SOAP**. It is NOT an ABH integration
  and cannot be used as an ABH pre-send authorization gate.
- Never re-request a different login or package because of
  historical Engagement-only docs.

## Vendor evidence

- https://developer.salesforce.com/docs/marketing/marketing-cloud-growth/guide/mc-getting-started.html
- https://developer.salesforce.com/docs/marketing/marketing-cloud-growth/guide/mc-connect-apis-rest.html
- https://developer.salesforce.com/docs/marketing/marketing-cloud-growth/guide/mc-manage-objects-create-streaming-data-cloud.html
