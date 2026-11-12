# BC / reusable PrestaShop — SFMC suppression and consent-source readiness

Status: **review only**. No Marketing Cloud subscriber API access was granted,
and BC has not been certified for live abandoned-cart sends. PrestaShop 0.7.1
is installed by the operator but its current Back Office mode/version
has not been independently observed through an authenticated session.

## What source evidence we have

From BC's read-only samples (most recent 500 carts), 356 looked abandoned
according to a *provisional local inactivity rule*, 58 carts had a linked
customer, 44 distinct customer IDs were represented, and **seven** distinct
customer IDs had `newsletter=1` (ten carts) with a stored
`newsletter_date_add` on all those ten cart rows. No newsletter IP was
present in that sample. There is no claim of seven legally sendable contacts.

PrestaShop 1.7 exposes two separate opt-ins:
- `newsletter`: first-party shop newsletter status;
- `optin`: offers from partners (not evidence for a shop campaign).

The 0.7.1 patch correctly stops `optin` alone from qualifying
a default cart-recovery candidate and re-reads the current PrestaShop
customer just before Journey dispatch.

## Crucial external source: Marketing Cloud Engagement

PrestaShop `newsletter=1` does **not** override a subsequent unsubscribe
or complaint in Marketing Cloud Engagement. Before any Journey event,
read these independent dimensions from the **correct** Business Unit:

1. **SubscriberKey mapping**, identity namespace, current email and shop/
   merchant scope. The current `ps_{shopId}_{customerId}` ContactKey is
   not necessarily unique *across two separate PrestaShop installations*.
   If two shops share the same Marketing Cloud account / BU, redesign
   or map existing keys deliberately before any merges, uploads or sends.
2. **All Subscribers** current `Subscriber.Status`: require `Active`;
   `Unsubscribed`, `Held`, `Bounced`, `Deleted` and unknown must
   fail closed. A different system's newsletter flag cannot restore
   subscriber status without a documented resubscription process.
3. **Publication list status**, where the intended Journey uses a
   publication list. List-level unsubscribe and account-level unsubscribe
   are different. Unknown publication scope must fail closed.
4. **Suppression / exclusion**, including suppression lists, complaints,
   unsubscribe evidence, legal objection, erasure and campaign exclusion.
   A missing/failed lookup is not equivalent to an empty suppression list.
5. **Purpose and timeliness**: registration-checkbox wording/default,
   subscription evidence, current suppression and customer record, order
   existence, verified cart age, sensible recovery window, anti-frequency
   rules. Do not send historic recovery messages on import.

Official Salesforce guidance:
- https://help.salesforce.com/s/articleView?id=mktg.mc_es_how_a_subscriber_opts_out.htm&type=5
- https://developer.salesforce.com/docs/marketing/marketing-cloud/guide/subscriber.html
- https://developer.salesforce.com/docs/marketing/marketing-cloud/guide/soap_tech_articles_subscribers.html
- https://help.salesforce.com/s/articleView?id=sf.mc_es_subscribers_on_suppression_list.htm&type=5

## Pure readiness contract

`RecoverySuppressionReadiness::assess()` accepts **only Boolean and
enumerated status signals**; it rejects PII, IDs, contact keys, unknown
statuses or missing fields. It provides a human-review candidate and
machine-readable rejection reasons, and always returns
`marketing_send_authorized=false`. A positive synthetic result is **not**
a production authorization. No DB, HTTP, token reads, worker wiring or
module setting changes were made.

The live Journey worker remains 0.7.1's implementation. Therefore,
**DO NOT switch BC to Production** until a real Marketing Cloud read-only
status/suppression adapter with exact tenant/BU and subscriber key mapping
has been implemented and certified; this policy class alone cannot
prevent existing 0.7.1 from sending in Production.

## Read-only next step on BC

Use `scripts/sql/bc_071_preflight_readonly.sql` in BC's authorized SQL
interface. It returns module and safe configuration switch metadata only:
- Confirm module version `0.7.1`, enabled, and PS_SFMC_MODE `test`;
- Find active newsletter/privacy-related modules;
- Check `NW_VERIFICATION_EMAIL` and `NW_CONFIRMATION_EMAIL` switch
  settings when stored.

Do **not** infer that verification-email configuration proves an
unchecked checkbox or that `newsletter_date_add` proves acceptance text.
Inspect the actual registration and checkout form **without submitting a
real customer or taking payment**, and record the checkbox initial state
and wording. Check for custom themes/modules and legacy imports.

## Pending real Marketing Cloud work

- Confirm actual Installed Package API scopes and tenant BU MID, without
  copying secrets into the repository/chat.
- Read existing subscriber status, publication/suppression policies using
  correct official API (SOAP for Subscriber and some list operations).
- Decide explicit historical subscriber-key reconciliation when older
  subscriber identities may exist in Salesforce.
- Integrate a non-mutating, fail-closed status/suppression check **before**
  every Journey send. Prefer a stable cached status with expiry and
  explicit API refresh/failure semantics; no bypass on network errors.
- Test with synthetic subscribers: Active, Unsubscribed, Held, complaint,
  publication-list unsubscribe, suppression-list match, missing status,
  wrong BU, changed email, stale data, no order, converted order.
- Separate technical readiness, lawful purpose review and final
  operator approval for controlled sends.

The historical customer/order/cart analytical datasets stay in scope
for their permitted purposes; they are **not** a mass recovery mailing list.
