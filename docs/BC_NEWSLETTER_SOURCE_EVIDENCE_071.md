# BC · Newsletter evidence source inventory after 0.7.1 installation

Status: **review only** · observed 2026-11-13.

## Confirmed directly by the BC operator

The authorized BC phpMyAdmin preflight returned:

| Origin | Name | Value | Scope |
|---|---|---|---|
| module | pssalesforcebridge | version=0.7.1; active=1 | global |
| setting | PS_SFMC_MODE | test | global |
| module | ps_emailsubscription | version=2.8.3; active=1 | global |
| module | psgdpr | version=1.4.3; active=1 | global |
| module | ps_dataprivacy | version=2.1.1; active=1 | global |
| setting | PS_CUSTOMER_OPTIN | enabled | global |

This **does not** certify that opt-in boxes are unchecked by default,
nor does it prove a specific legal basis or Salesforce subscriber status.
The preflight did not return `NW_VERIFICATION_EMAIL` or
`NW_CONFIRMATION_EMAIL`. Their absence in that query is **unknown**,
not proof that double opt-in is enabled, disabled or never used.

## Source-code findings (exact public module tags)

Official upstream sources:
- [PrestaShop ps_emailsubscription v2.8.3](https://github.com/PrestaShop/ps_emailsubscription/blob/v2.8.3/ps_emailsubscription.php)
- [PrestaShop psgdpr v1.4.3 install schema](https://github.com/PrestaShop/psgdpr/blob/v1.4.3/sql/install.php)
- [PrestaShop psgdpr v1.4.3 GDPRLog](https://github.com/PrestaShop/psgdpr/blob/v1.4.3/classes/GDPRLog.php)
- [PrestaShop ps_dataprivacy v2.1.1](https://github.com/PrestaShop/ps_dataprivacy/blob/v2.1.1/ps_dataprivacy.php)

1. **Customer table:** `bc_customer.newsletter` is the first-party
   subscription status for registered/guest customer accounts. The
   `newsletter_date_add` and `ip_registration_newsletter` fields are
   available, but date/IP presence is not stand-alone proof of notice text,
   purpose or absence of later opt-outs.
2. **Standalone newsletter subscriptions:** `ps_emailsubscription` v2.8.3
   creates the separate `<prefix>emailsubscription` table with
   `active`, `newsletter_date_add`, `id_shop` and other fields.
   These standalone subscribers can include visitors without a customer
   account and must not be silently dropped when analysing history.
   **Do not just add counts** from customer and standalone sources:
   email identities can overlap or be duplicated.
3. **Newsletter confirmation:** v2.8.3 supports
   `NW_VERIFICATION_EMAIL` (verification) and `NW_CONFIRMATION_EMAIL`
   (confirmation), but the current BC settings have not been verified.
   The PrestaShop customer-registration newsletter checkbox and standalone
   newsletter widget may follow different flows; check both.
4. **GDPR module:** psgdpr v1.4.3 creates `psgdpr_consent` (per-module
   consent settings), `psgdpr_consent_lang` (text of module consent
   notices) and `psgdpr_log` (request logs). Source code maps
   `request_type`: **1 consent**, **2 PDF export**, **3 CSV export**,
   **4 deletion**. These logs are not the same as newsletter-specific
   authorization, and do not automatically prove who may receive
   cart-recovery marketing. Do not extract
   `client_name`, `id_customer`, `id_guest` or raw messages.
5. **Data privacy module:** ps_dataprivacy v2.1.1 provides a required
   customer privacy checkbox/message. It is distinct from optional
   marketing consent.
6. Custom themes/modules, historical imports and existing external
   Marketing Cloud unsubscriptions may change real behavior relative
   to upstream source. **Live BC behavior is still unverified.**

## Safe historical inventory procedure

First confirm that the expected `bc_emailsubscription` table exists
without reading customer rows. In phpMyAdmin run:

```sql
SHOW TABLES LIKE 'bc_emailsubscription';
```

If present, use
`scripts/sql/bc_historical_newsletter_source_counts_readonly.sql`,
which outputs one **aggregate result set** for all of shop 1,
including active/inactive status, timestamps/IP-field presence and
per-source distinct-email **counts**. It does not return an email or
an IP value. If the table is absent, do not create one or run the
combined query; the operator should confirm whether the newsletter
table was migrated or renamed.

Separately, to assess GDPR audit coverage, check the existence of
`bc_psgdpr_log` and `bc_psgdpr_consent`. A GDPR-log presence is
not a license for marketing sends. Current active checkbox text,
legacy terms, suppression, deletion and legal basis still need
business review.

## SFMC gating

BC stays **Test** until the current registration/checkout opt-in state,
newsletter confirmation mechanism, stored evidence, scope, unsubscribe
and complaint statuses in the correct Marketing Cloud Engagement
Business Unit are verified. The default cart policy in 0.7.1
requires first-party `newsletter`, yet still lacks an integrated
read-only Salesforce suppression adapter; **do not activate Production**.

Historical customers, standalone newsletter subscribers, orders and
carts remain candidate analytical sources for lawful purposes,
separate from permitted marketing audiences. Never retroactively
trigger abandoned-cart sends during historical ingestion.
