# G3 0.8.0 — Candidate credential removal and safe reinstall (CI only)

**2026-11-13.** ABH/Barcelona Confort uses Marketing Cloud Growth + Data 360, **not Engagement**. This is a disposable PrestaShop CI candidate. **The real distributable module remains 0.7.1 in Test.**

## Goal

Extend #55's non-destructive uninstall to remove **local provider credentials and obsolete Engagement routes**, while preserving operational settings and blocked observations. Then prove a **real module reinstall** through PrestaShop, without overwriting merchant settings or reusing cron bearer tokens.

## Temporary runtime candidate

- `tests/runtime/G3CandidateCredentialLifecycle.php` has an explicit list of connection settings to remove: legacy auth and REST endpoints, client ID/secret, account ID, DE targets, Journey target and cron bearer. It has a separate allowlist of benign operator defaults to preserve (cart abandonment interval, marketing opt-in choice, retention windows, etc.).
- Candidate `uninstall()` scrubs these credentials **before** calling `parent::uninstall()`, but keeps all the module's SQL tables and nonsecret settings. It fails closed if cleanup fails.
- The candidate `installConfiguration()` preserves existing nonsecret settings and sets mode `test` explicitly. It refuses to inherit legacy provider destinations and generates a new random 48-character hex cron token. This is not permission to send emails.
- The Growth provider binding is not deleted; no Engagement adapter fallback is permitted.
- All candidate mutations remain behind the existing strict flags, expected PrestaShop version, fixed disposable database and `ps_` prefix. **No changes in the distributable module directory.**

## Native tests (new cut)

Using only ephemeral GitHub Actions containers and synthetic CI-only values:

1. Install the actual legacy 0.7.1 module and run the existing smoke tests.
2. Seed synthetic local credentials and non-default cart/retention settings. Record only the SHA256 of the original cron token, not its value.
3. Upgrade natively to the isolated 0.8.0 candidate and verify old settings and data are intact.
4. Uninstall natively; verify the module registration is removed, all legacy and Growth tables/rows remain, nonsecret settings persist, but the credential/destination/cron keys no longer exist.
5. Reinstall the candidate natively; verify the Growth namespace and ledger survive, nonsecret settings remain unchanged, **no legacy credentials or destinations are restored**, mode is still Test, and the new cron token differs from the old one.
6. Verify customer, order, and Journey legacy senders all report `g3_growth_delivery_not_approved`. No remote data or marketing actions occur.

Run on PrestaShop **1.7.8.11**, **8.2.8** and **9.1.5**; run offline credential-policy contracts on PHP 7.1 and 8.5.

## Unresolved production decisions

- Local deletion is **not** remote token revocation or full erasure from logs/backups.
- Legacy queues/recovery tokens may contain PII and must have an approved retention, erasure and recovery policy before general release. A draft ADR is available for owner review, not approved.
- Future actual 0.8.0 will require all SQL DDL and provider routing hardened for the target DB, safe partial migration and precise revocation/retention semantics, followed by a separately authorized release.
- G3 PSCustomer/PSOrder source time, namespaces, consent, minimization, privacy and Data 360 schema owner sign-off is still required. No schema publish, ingestion, Flow or campaigns authorized.

**BC stays 0.7.1 Test. No ZIP or SQL access required.**
