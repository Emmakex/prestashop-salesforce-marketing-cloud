# BC / PrestaShop — abandoned-cart segmentation design (review only)

Decision on 2026-11-12: retain valuable historical carts as well as ongoing
customer/order history, where processing is lawful and data quality allows.
A registered customer who adds items but does not buy must be separately
counted as a **cart abandonment**. Guests may also form useful analytics
cohorts, without automatically becoming marketing recipients.

## Existing 0.7.0 capabilities and limitations

- The module already captures cart snapshots through `actionCartSave` into
  `sfmc_cart` and item details in `sfmc_cart_item`.
- The configured inactivity threshold defaults to 60 minutes (minimum 15).
- `CartAbandonmentDetector` checks native PrestaShop orders and the local
  order ledger to suppress converted carts; Journey dispatch re-checks
  conversion and cart freshness before a send.
- Existing `CartEligibilityPolicy` requires product + known customer +
  valid email + active customer, and defaults to the raw newsletter/optin
  flags. Those source flags are **not** durable legal proof of permission.
- **Current gap:** the detector selects only `status=active AND eligible=1`.
  An `ineligible` cart may still be abandoned for *analytics*, even though
  it is not an eligible recovery-email candidate. Never assume local
  `sfmc_cart.status=abandoned` is the complete universe of abandoned carts.
- The module does **not** have a Data Cloud cart object or cart Data Stream.
  The review-only `PSCustomer` / `PSOrder` v0.3 YAML contains only two
  objects. A `PSCart` schema and relationship/retention policy, if chosen,
  require independent approval and a versioned schema change **before**
  Salesforce publication.
- PrestaShop core historic carts predate installation of the module and
  are not reconstructed just by installing 0.7.0. Backfill needs a
  separate read-only first scan, event freshness checks and a lawful
  purpose; historical carts must not retroactively trigger Journey events.

## Proposed independent cohorts

| Cart cohort | Analytical use | Outreach |
| --- | --- | --- |
| active | Added products, not inactive enough | No abandoned-cart send |
| abandoned + registered | Inactivity threshold met, no order | Only with separately verified permission/policy |
| abandoned + guest identified | Guest checkout with customer identity, no order | Only with independently verified permission |
| abandoned + anonymous | No reliable contact but items remain | Aggregates only; no contact |
| activity_time_unverified | Missing/ambiguous UTC provenance | Do not mark as confirmed abandonment |
| recovered link started | Recovery token used, no order confirmed | Not a completed sale |
| converted | Corresponding native order exists | Always suppress abandoned-cart recovery |
| empty | All items removed | Not an abandonment |

Additional analytical dimensions: first-time versus repeat buyers,
recency, value band, product categories, item count, last activity,
recovery outcome, historical versus newly captured. Segments must
carry a permanent merchant/installation + shop scope; email alone
never joins different merchants.

## Implementation boundary

`CartSegmentClassifier::classify` is a **pure offline analytics
candidate**. It takes shop/cart identity, product count, native order
existence and independently verified inactivity minutes and returns a
cohort and identity class. It intentionally includes current
`ineligible` snapshots in the analytical abandoned cohort.

Its `outreach_authorized`, `data_cloud_ingestion_authorized` and
`marketing_send_authorized` outputs are **always false**. This is not
wired into module hooks, detector, SQL queries or Salesforce; no
new configuration, credentials or cron actions.

## Before any real stream or recovery campaign

1. Produce aggregate **read-only**, merchant-scoped counts from the
   native cart/order tables and `sfmc_cart` to measure covered and
   unobserved historical carts. No customer IDs/emails leave BC.
2. Verify timestamp semantics for the actual cart write path, including
   the historical DST policy and 60-minute inactivity threshold; do
   not use unproven `date_upd` as an absolute UTC time.
3. Approve `PSCart` data minimization, retention, erasure, consent
   provenance, suppression, source_key and customer/order relations;
   decide if old cart item details are necessary at all.
4. Implement an analytics-only ingestion mapper separately from
   Journey recovery actions. Re-check each cart's native order state,
   eligibility and suppression immediately before a planned send.
   Include throttling, campaign frequency caps and opt-out handling.
5. Approve synthetic test, then production ingestion, then actual
   contact campaigns as separate gates; never enable all three just
   because the connector module is healthy.

Do not change production PrestaShop mode, start backfill, enable cron
delivery, publish PST v0.3 or send any customer data during this step.
