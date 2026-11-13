# G3 — 0.8.0 candidate: non-destructive native uninstall and provider egress isolation

**2026-11-13. ABH / Barcelona Confort uses Marketing Cloud Growth + Data 360 (not Engagement).**  
**State:** ephemeral CI-only module modification, **not** an installable 0.8.0, release or authorized Growth sender.

## Concrete risk addressed

The current distributed module 0.7.1 calls `uninstallDatabase()` and
`uninstallConfiguration()` when uninstalled. This drops legacy queues,
synchronization ledgers and configuration. Its customer, order and
abandoned-cart delivery workers check `PS_SFMC_MODE` but do **not** check
whether the installation targets Growth instead of legacy Engagement.

Those behaviors cannot be inherited silently in a reusable multiprovider
release for ABH.

## What this cut tests

Starting from the real PrestaShop native 0.7.1 -> **temporary 0.8.0**
upgrade already certified in PR #54:

1. Copy the 0.7.1 module into a temporary CI-only directory and patch
   **only that copy**, using exact source anchors.
2. Replace candidate `uninstall()` with `parent::uninstall()` under
   explicit disposable-run guards; it no longer calls either destructive
   private helper.
3. Inject a candidate provider policy **ahead of** the previous mode
   check in all three Engagement delivery workers: customer queue, order
   queue and abandoned-cart Journey. The policy loads the persistent
   provider binding and always blocks outgoing delivery while G3-G7 remain
   unapproved. ABH + Growth reports `g3_growth_delivery_not_approved`.
   An absent, tampered or other-merchant binding also fails closed.
4. Exercise the policy as a pure PHP contract on 7.1 and 8.5, including
   simulated `production`, ABH/Engagement mismatch, absent binding,
   invalid namespace, tampered state and an unapproved Engagement tenant.
   **No production configuration or network request is changed in tests.**
5. Run the real native PrestaShop module upgrade, verify the three workers
   explicitly refuse Engagement traffic, add an isolated blocked synthetic
   Growth observation and ledger row, then run the native
   `prestashop:module uninstall pssalesforcebridge` command.
6. Check the module registration has been removed, while legacy table
   row counts, retained nonsecret operational settings, all three prefixed
   Growth tables and blocked synthetic observations remain in the DB.

The runtime matrix remains pinned to **PrestaShop 1.7.8.11 / 8.2.8 /
9.1.5** using ephemeral CI containers with MySQL 5.7. The independent
MySQL 8/MariaDB 10.11 G3 sandbox tests remain unchanged.

## Boundaries

- Source code of the real installable `pssalesforcebridge/` is unchanged;
  its version, ZIP and module uninstall behavior **remain 0.7.1**.
- The 0.8.0 candidate patch is executable only with explicit CI guards
  and deliberately contains **no authorized delivery branch**.
- No BC production writes, module install, SQL access, secrets, Salesforce
  API calls, Data 360 schema/stream/ingestion, backfill or campaign sends.
- The candidate preserves existing configuration during uninstall as a
  **reversibility test only**. Retaining stored authentication secrets
  after uninstall is *not* approved as a production privacy policy.
  The eventual product requires a separately reviewed split between
  business data retention/erasure, credential revocation, and reversible
  operator settings.
- The real 0.8.0 module must ultimately use version-aware DB DDL,
  recover safely from partial DDL, guard *every* outbound surface,
  preserve authorization boundaries, and test new/reinstall/uninstall
  behavior without relying only on MySQL 5.7 CHECK constraints.

## Gates still open

G3 data-owner approval for PSCustomer/PSOrder, installation namespace,
source timezone/DST/revisions, consent/purpose, erasure and retention;
G4-G7 Salesforce schema, synthetic ingestion and marketing permissions;
and G8 future module release and production go-live.

**Barcelona Confort remains 0.7.1, Test. No user SQL or ZIP needed.**
