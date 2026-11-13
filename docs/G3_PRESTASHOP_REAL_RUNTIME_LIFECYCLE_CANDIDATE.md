# G3 — PrestaShop real: lifecycle candidate in CI only

**13 November 2026. Merchant ABH / Barcelona Confort. Provider Marketing Cloud Growth (Next) + Data 360, not Engagement.**

**State:** executable test fixture in disposable PrestaShop, not a shipped migration, release or owner approval.

## Objective and evidence

PRs #51 and #52 verify the independent Growth outbox and provider binding in MySQL 8 and MariaDB 10.11, without authenticating or sending anything to Salesforce. They do not certify a real PrestaShop installation.

This increment runs a new local-only candidate through the existing runtime harness on these exact releases:

| PrestaShop | Installed module | Runtime database |
| --- | --- | --- |
| 1.7.8.11 | 0.7.1 Test | MySQL 5.7 |
| 8.2.8 | 0.7.1 Test | MySQL 5.7 |
| 9.1.5 | 0.7.1 Test | MySQL 5.7 |

The new tests execute **after** the existing real 0.6.1 to 0.7.1 upgrade, clean installation and smoke test. They use PrestaShop Db, Configuration, Module, _DB_PREFIX_ and _PS_VERSION_ in CI containers only.

## Lifecycle behavior under test

1. Require explicit ABH merchant, marketing_cloud_growth provider, immutable installation namespace, real platform version and Test mode. Reject Engagement for ABH and refuse silent provider changes.
2. Require dedicated disposable GitHub Actions flags, fixed database name, fixed prefix and pinned exact version. The tests are not packaged into the PrestaShop module.
3. Create three prefixed CI-owned candidate tables: ps_g3_provider_binding_v1, ps_g3_growth_outbox_v1, and ps_g3_growth_entity_ledger_v1. The outbox + ledger DDL is derived from the isolated G3 v1 schema, with only PrestaShop table-prefix rewriting.
4. Enforce identical re-install as a no-op; reject differing provider, merchant or namespace. Detect partial DDL and stop without automatic destructive cleanup.
5. Preserve nonempty blocked events and ledgers on candidate uninstall. Remove only the three owned tables when they are empty. Verify a retained installation can be resumed safely.
6. Test a synthetic blocked event, interrupted DDL, repeat install, empty rollback, and no change to legacy 0.7.1 tables, module version or configuration.

## Critical MySQL 5.7 limitation

The pinned real PrestaShop runtime uses MySQL 5.7. This engine **does not enforce CHECK constraints**, although it parses them. Therefore success here does not certify DB-enforced blocked states on MySQL 5.7. This candidate has **no outbound sender or activation function**. The separate G3 MySQL 8/MariaDB 10.11 sandbox verifies its own DB-enforced CHECK restrictions. A future deployable migration must implement an independently fail-closed, database-version-aware state policy.

## Explicit exclusions

No files under the installable pssalesforcebridge/ folder are modified. No future upgrade script, ZIP, release, Back Office provider selector, live hook, production SQL, OAuth, ingestion, backfill, Flow, emails or Salesforce API operation. No real customer data. No automatic PS version migration. Success of this test is not certification of the eventual future Growth module.

**Barcelona Confort remains on 0.7.1, Test.**

## Still required before a real installation or sending

G3 owner sign-off on PSCustomer/PSOrder, namespace, timezone/source revision, consent, retention and privacy; a separately reviewed versioned migration from 0.7.1; safe database-version-aware DDL / retry / rollback; a real deployable future module validated in each pinned PrestaShop version; and independent approvals for Salesforce schema publication, ingestion and marketing activation.
