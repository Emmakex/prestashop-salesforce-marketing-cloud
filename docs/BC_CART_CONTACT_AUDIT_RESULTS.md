# BC — abandoned-cart contact audit (aggregates only)

Observed by the BC operator on 2026-11-12. **Sample: most recent 500
cart IDs**, classified with a provisional 60-minute inactivity window.
Source: authorized, read-only SQL on the merchant's PrestaShop database.
No customer identifiers or personal data were provided.

## Funnel from the sampled carts

| Segment | Carts |
| --- | ---: |
| Anonymous abandonment candidates | 298 |
| Registered abandonment candidates | 46 |
| Guest abandonment candidates | 12 |
| Converted to order | 86 |
| Empty cart | 58 |
| **Total** | **500** |
| **Abandonment candidates** | **356** |
| **Candidates with a linked customer** | **58** |

**Important:** 58 is a **cart count**, not a distinct customer count;
a customer may have multiple carts. Neither 356 nor 58 are proven to be
abandoned by an absolute UTC event until the cart timestamp policy is
certified. The query does not assess the full historical population.

## Registered/guest contact audit of these 58 carts

| Signal (raw PrestaShop flags) | Guest carts | Registered carts | Total carts |
| --- | ---: | ---: | ---: |
| Candidates with a linked customer | 12 | 46 | 58 |
| Empty email | 0 | 0 | 0 |
| Suspicious email format by sampled SQL check | 0 | 0 | 0 |
| Inactive customer | 0 | 0 | 0 |
| Deleted customer | 0 | 0 | 0 |
| Newsletter flag | 2 | 8 | 10 |
| Partner opt-in flag | 3 | 3 | 6 |
| Both flags | 2 | 3 | 5 |
| At least one of the flags (deduplicated) | 3 | 8 | **11** |
| Neither flag | 9 | 38 | **47** |

The flags overlap: 10 + 6 - 5 = 11. There are **11 candidate carts
with at least one raw marketing flag**, NOT 11 proven contacts eligible
for recovery sends. An email passing a simple structural SQL pattern
is not a verified deliverable address. No row-level marketing
consent, source, timestamp, suppression, unsubscribe, privacy notice,
email deliverability or cart-specific legal eligibility was proved.

## Segmentation policy

1. Retain valuable historical customers, orders and cart information
   for approved purposes (including analytics). An old cart or missing
   opt-in is **not** an automatic data-deletion criterion.
2. Distinguish analytics cohort, known/guest/anonymous identity,
   provisional abandonment, recovered/conversion, send-eligibility and
   historical timestamp confidence. Each is an independent dimension.
3. Anonymous carts are analytics-only unless a later, lawfully
   established identity exists. Do not attempt to extract an email
   from an anonymous cart that PrestaShop never collected.
4. Converted carts must be suppressed against native orders before
   any outreach; a recovery-link click is not proof of a purchase.
5. No sending until lawful basis for the **specific abandoned-cart
   message and channel** has been documented, suppression applied,
   audience rules reviewed and a current, trusted contact established.
   Neither newsletter nor partner opt-in alone proves this.
6. Review volume by distinct customer as well as distinct cart, and
   enforce frequency caps and a sensible campaign recency window.
   Historical carts must never automatically trigger retroactive
   recovery messages after migration.
7. A proposed Data Cloud `PSCart` object is separate from the
   reviewed `PSCustomer`/`PSOrder` schema, and requires its own
   approved minimum fields, retention, privacy and relationships.

## Next read-only discovery

`scripts/sql/bc_consent_source_metadata_readonly.sql` lists
schema **metadata only** for likely consent, subscription and
privacy evidence. It does not retrieve customer rows or secrets.
A positive column or table name does not demonstrate that records
contain valid legal proof. Subsequent record-level evidence
verification stays with an authorized BC data owner, without
sending personal data into code repositories or chat.

The release remains in Test; no backfill, Data Cloud schema/stream
publication or Journey Builder campaign has been authorized.

## Follow-up: distinct customers and newsletter traceability (2026-11-12)

The BC operator supplied a one-page phpMyAdmin aggregate PDF from the
read-only customer/cart query. The 58 cart candidates represent **44 distinct
customer IDs** (12 guest; 32 registered). Email distinct counts within each
cohort are 11 guest and 32 registered; do not assume they total 43 distinct
addresses across both cohorts or deduplicate across merchants by email.

| Measure | Guest carts | Registered carts | Total |
| --- | ---: | ---: | ---: |
| Candidate carts | 12 | 46 | 58 |
| Distinct customer IDs | 12 | 32 | 44 |
| Newsletter=1 carts | 2 | 8 | 10 |
| Distinct customers with newsletter=1 | 2 | 5 | **7** |
| Newsletter=1 carts with newsletter_date_add | 2 | 8 | **10** |
| Newsletter=1 carts without newsletter_date_add | 0 | 0 | 0 |
| Newsletter=1 carts with ip_registration_newsletter | 0 | 0 | 0 |
| Newsletter date present with newsletter=0 | 0 | 0 | 0 |
| Partner optin=1 carts | 3 | 3 | 6 |
| Both flags carts | 2 | 3 | 5 |
| Neither flag carts | 9 | 38 | 47 |

The 10 newsletter-positive **carts** do not represent 10 people. Seven
distinct **customers** is the candidate *newsletter-subscribed* audience
before verifying purpose, notice version, collection mechanism, opt-out and
suppression. Non-NULL signup timestamps improve traceability but are not
proof by themselves that a particular commercial message is authorized.
No subscription IP is populated in this sample; an IP is not automatically
mandatory for valid consent, and no evidence should be fabricated.

### Safety finding for the existing runtime

Current 0.7.0 `CartEligibilityPolicy::MODE_MARKETING_OPTIN` treats
`newsletter=1 OR optin=1` as eligible for abandonment sends. In PrestaShop,
`optin` is generally the partner/commercial communications flag, and should
NOT independently enable first-party cart-recovery sends without a
documented, purpose-specific legal determination. BC has **one candidate
cart with only optin=1** (11 with either flag, 10 with newsletter).
The runtime's technical eligibility flag is **not** proof of lawful
contact authorization. Keep Test mode and do not switch to Production
until policy/consent/suppression and Journey entry rules are reviewed and
separately certified.

### Next review gates

1. Verify the actual registration/checkout/newsletter checkbox wording and
   default unchecked state, and any legacy import sources.
2. Identify all current unsubscribe, opt-out, objection and deletion
   sources, including external email tools. A shop flag alone may not
   reflect a subsequent unsubscribe in Salesforce.
3. Decide whether 0.7.0 should require verified first-party newsletter
   subscription (and separately documented alternatives) before a Journey
   send; partner optin alone is **not** a safe default for BC.
4. Recheck current no-order state, inactivity timestamp, recency window and
   unique customer contact before any recovery; never mass-send to old carts.
5. Keep the full lawful historical data available for analytics and
   transactional relationships without mislabeling all of it marketing
   eligible.

These figures describe the operator's **sample of 500 latest carts**,
not the whole historic database or a certified send audience.
