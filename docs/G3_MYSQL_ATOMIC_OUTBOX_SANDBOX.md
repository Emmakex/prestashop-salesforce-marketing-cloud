# G3 — Real InnoDB concurrency certification in an isolated CI sandbox

**ABH / Barcelona Confort: Salesforce Marketing Cloud Growth + Data 360.**
**Status:** isolated test implementation, not a deployed PrestaShop adapter.
**First implemented:** 2026-11-13. This test does not close G3 owner approval.

## What was implemented

The `tests/integration/G3SandboxPdoJournal.php` adapter implements
`StagedAtomicUtcAcceptance::accept(..., insertOrLoad)` against **real
MySQL/MariaDB** using PDO and prepared statements. It is intentionally
located **outside** the installable `pssalesforcebridge/` directory.
No BC database credentials, schema migrations or customer data are needed.

The SQL fixture `tests/integration/g3_sandbox_schema.sql` creates three
tables **only** in CI's ephemeral database `g3_ci`:

- `g3_journal`: append-only event observations, primary key
  `accepted_sequence`, unique `(source_namespace, dedupe_fingerprint)`.
  `captured_at_utc` records when the hook was observed by PHP; it is
  **not** verified source modification time or a Data 360 watermark.
- `g3_outbox`: unique reference to journal event, **status blocked**.
  It is **not** the current `sfmc_queue` and cannot send messages.
- `g3_ledger`: one row per namespace/shop/entity, storing the latest
  **local accepted sequence**, not an authoritative source revision.
  It is **not** the current `sfmc_customer_sync` or `sfmc_order_sync`.

The adapter uses a deterministic per-event MySQL `GET_LOCK`, performs
`SELECT ... FOR UPDATE` and commits journal, blocked outbox and ledger
within **one InnoDB transaction**. An existing event is returned without
invoking the timestamp factory; a new event invokes it exactly once.
A unique index independently prevents duplicated event rows. The
connection is released from the advisory lock even when the transaction
fails. A failed transaction rolls back all three writes.

## Tested on disposable databases in GitHub Actions

New CI job `G3 atomic DB sandbox` has separate matrix runs for
**MySQL 8.0** and **MariaDB 10.11**. The test connects only when the
three exact values are present:

- `G3_CI_ENABLE=yes_sandbox_only`
- DSN exactly `mysql:host=127.0.0.1;port=3306;dbname=g3_ci;charset=utf8mb4`
- user exactly `g3_ci_user` (test-only, ephemeral password)

The CI job tests **real transactions**, not merely an in-memory fake:

1. Inject failure after **journal**, **outbox** or **ledger**, then
   assert all three tables contain zero committed rows.
2. Insert once, retry the same commercial hash with another hook name,
   verify identical UTC stamp/sequence and no duplicate outbox.
3. Change commercial hash, check the new accepted sequence and updated
   local ledger; no claim of source causal order.
4. Verify the same numeric ID and hash in a different **installation**,
   **shop** or **entity type** do not collide.
5. Launch **eight parallel PHP worker processes** and separate PDO
   sessions against the same dedupe fingerprint. Exactly one may create
   the new journal/outbox row; all must observe its first UTC instant.
6. Verify every outbox row remains `blocked`; there is **no** outbound
   transport or Salesforce API in these tests.

## Segundo laboratorio: tablas 0.7.1 reales, pero aisladas

El [espejo transaccional de las tablas del módulo 0.7.1](G3_PRESTASHOP_071_ATOMIC_MIRROR.md)
prueba atomicidad de diario UTC + `sfmc_queue` + ledgers de
clientes y pedidos en MySQL/MariaDB, **sin crear tablas en BC**.
La cola está siempre `blocked`, las colisiones con filas
preexistentes se rechazan y el test valida las columnas/índices
del instalador existente. Esto no convierte al adaptador de pruebas
en un runtime Growth operativo.

## Production gates remain blocked

The real 0.7.1 queue first updates
`CustomerSyncLedger/OrderSyncLedger` and then separately calls
`QueueRepository::enqueue`. The existing legacy outbound processor
can still write to its Engagement Data Extension before the local
ledger's `markSynced`; that is not acceptable as an ABH Growth
delivery path.

This sandbox does **not** modify those classes and does not prove
atomicity of the existing PrestaShop storage path. To implement a
future production-grade Growth outbox, we must separately:

- Approve a namespaced schema and data retention/deletion policy
  (G3-01/07), and design a safe `0.7.x` migration/rollback with the
  actual PrestaShop DB layer across PS 1.7/8/9.
- Couple journal + **actual queue + ledger** into one transaction
  or a rigorously audited outbox. Verify transactional engines and
  rollback without damaging BC's current rows.
- Add source freshness/version validation and a current state read
  before any remote write; locally increasing acceptance IDs do
  **not** identify the newest source mutation.
- Certify PHP/server clock synchronization and source timezone
  provenance (G3-03/04), then decide Record Modified and stream refresh
  policy. Never derive reliable UTC historical timestamps from the
  current `Europe/Madrid` configuration alone.
- Approve and publish `PSCustomer`/`PSOrder` schema **separately**.
  Data 360 ingest, Growth contact policies/consent, segments, Flows
  and campaign sends remain unapproved.

The tests use only synthetic IDs and no personal data or secret
Salesforce tokens. **Do not install a new ZIP on BC** for this test.
No production queries, backfills, schema uploads, data streams,
Marketing Cloud Engagement routes or marketing messages were executed.
