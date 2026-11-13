# G3 — PrestaShop 1.7/8/9 provider adapter: sandbox installation preflight

**2026-11-13 | ABH / Barcelona Confort | Marketing Cloud Growth + Data 360**  
**Status: test-only adapter candidate. NOT installed; not approved for PrestaShop rollout or Salesforce delivery.**

## Purpose of this cut

Extend the independent Growth outbox candidate from PR #51 with a **controlled
provider selection and reversible test installation**. This is the first
implementation step toward a reusable PrestaShop adapter, not the final
installable module. It is deliberately outside `pssalesforcebridge/`.

Files:

- `tests/integration/G3PrestaShopAdapterSandbox.php`: provider/PrestaShop
  preflight and guarded installation/rollback with a persistent test binding.
- `tests/g3-prestashop-adapter-contract.php`: PHP 7.1/8.5 offline negative gates.
- `tests/integration/g3_prestashop_adapter.php`: disposable MySQL 8.0 and
  MariaDB 10.11 installation, constraints, rollback and legacy-isolation tests.

## PrestaShop and provider policy

- Supported **version declaration**: `1.7.8.x`, `8.x`, `9.x`.
  Tested representative fixture strings: `1.7.8.11`, `8.2.8`, `9.1.5`.
  This is **not** genuine installation/runtime certification on those releases.
- Required explicit `merchant`, `provider`, `source_namespace`,
  `prestashop_version` and `mode=test`. No implicit provider default.
- ABH can select **only** `marketing_cloud_growth`; any Engagement selection
  is rejected. Another merchant may preview `marketing_cloud_engagement`,
  but there is **no legacy installer or outbound route** in this candidate.
- The provider binding table `g3_ps_adapter_binding_v1` is a new,
  **test-only** third table. The two Growth tables from PR #51 stay unchanged.
  The binding stores provider, merchant, namespace, exact PrestaShop version,
  `test` mode and `blocked` state; MySQL/MariaDB CHECK constraints enforce
  the provider/test/blocked invariants independently of PHP.
- A repeated install with exactly the same binding is a no-op. A change of
  namespace, merchant, version, provider or mode is rejected, not silently
  converted into a migration. All three tables must be simultaneously absent
  or present; partial DDL fails closed for manual sandbox review.
- Rollback is available only if **both** Growth tables contain no accepted
  business observations. A nonempty outbox is preserved; no automatic purging.
  The registry is removed only after the two owned tables roll back safely.
- Existing 0.7.1 `sfmc_queue`, `sfmc_customer_sync` and
  `sfmc_order_sync` are never modified or repurposed.

## Explicit limits

This candidate runs **only** with disposable `g3_ci` DB/user/DSN and
`G3_CI_ENABLE=yes_sandbox_only`; its constructor reuses the strict SQL
guard from PR #51. **It is not packaged in 0.7.1.** No production SQL, upgrade
script, shop hook integration, new ZIP, provider switching in Back Office,
Core OAuth, Data 360 schema/stream/ingestion, historical backfill, Growth
Flow or email is added. Every status remains `blocked` and every outbound
authorization remains `false`.

## Open gates before production adapter

1. G3 data-owner approval for PSCustomer/PSOrder, tenant namespace assignment,
   source UTC provenance/versioning, consent, erasure and retention.
2. A separate, fully prefixed **PrestaShop module upgrade** design that
   preserves merchant installation identity, supports real 1.7.8/8/9
   install/uninstall lifecycle, detects failed intermediate DDL and has a
   non-destructive rollback/data-retention strategy.
3. Exact-head real PrestaShop 1.7.8.11/8.2.8/9.1.5 runtime certification for
   that future packaged candidate; signed-off provider configuration and
   independent authorization for schema publication, synthetic ingestion
   and campaign delivery.

BC stays **0.7.1, Test**. No user SQL access or module installation is needed
for this cut.
