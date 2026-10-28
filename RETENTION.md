# Privacy and retention

The connector keeps local operational data only for as long as it remains useful for delivery, recovery, auditing or attribution. Retention runs locally in PrestaShop and never calls Salesforce.

## Execution

Retention runs from the existing protected cron at most once every 24 hours. A merchant can also run it manually from the module configuration screen.

A retention failure is isolated from customer, order and Journey delivery. The cron reports a sanitized `retention_failed` result and never exposes database exceptions, credentials, queue payloads or customer data.

## Configurable windows

Each window accepts 1–3650 days.

Defaults:

- processed queue: 30 days;
- local logs: 30 days;
- Journey audit: 90 days;
- cart snapshots: 30 days;
- recovery tokens: 30 days.

These are technical defaults, not a substitute for the merchant's legal, contractual or business retention policy.

## Queue

Automatic retention deletes only rows whose status is `processed` and whose `processed_at` is older than the configured cutoff.

It never automatically deletes:

- `pending`;
- `processing`;
- `retry`;
- `failed`.

This prevents privacy cleanup from silently discarding undelivered or operationally failed commerce events.

## Local logs

Rows in `sfmc_log` older than the configured log window are deleted.

The protected cron also returns sanitized failure codes instead of raw exception messages, reducing the risk of secrets or customer context being exposed to callers.

## Journey audit

Only terminal, non-error Journey audit rows are deleted automatically:

- `sent`;
- `suppressed`.

`processing`, `retry` and `failed` audit rows are retained for operational investigation.

## Cart snapshots

Cart snapshots can contain email addresses and normalized cart payloads. Old snapshots are automatically removable when they are explicitly terminal:

- `empty`;
- `ineligible`;
- `converted`;
- `recovered`.

Old `abandoned` snapshots also become removable, but only after two additional fail-closed checks prove they are no longer operationally required:

1. there is no unused, unexpired recovery token for that shop/cart;
2. there is no `CART_ABANDONED` queue row in `pending`, `processing`, `retry` or `failed` state.

Matching `sfmc_cart_item` rows are removed before their parent cart snapshot.

`active` snapshots are never auto-purged. An `abandoned` snapshot with a valid recovery link, outstanding delivery attempt or terminal delivery failure is also retained.

## Recovery tokens

Raw recovery tokens are never stored; only SHA-256 hashes are persisted.

After the configured retention window:

- expired unused tokens are deleted;
- used tokens that did not create a recovered cart are deleted;
- used tokens that did create a recovered cart are retained only as a minimal attribution bridge and are anonymized.

Anonymization removes customer identity and the original snapshot binding:

- `id_customer` becomes `0`;
- `contact_key` becomes empty;
- `snapshot_hash` becomes a zero hash;
- the stored token hash is replaced with an irreversible retained-record hash unrelated to the original recovery link.

The shop/cart/recovered-cart relation remains so `sfmc_recovery_order` can continue to provide recovered-order counts and authoritative revenue attribution.

## Recovery order attribution

`sfmc_recovery_order` is not automatically purged by this microcut. It is the authoritative local ledger for recovered orders and recovered revenue and does not contain customer email addresses or Salesforce credentials.

A later business-retention policy may define an explicit lifetime for financial attribution records without weakening the recovery measurement contract.

## Operational guarantees

Retention must never:

- call Salesforce;
- delete undelivered queue work;
- delete terminal failures that still require investigation;
- invalidate an active recovery link by deleting its abandoned snapshot prematurely;
- delete an abandoned snapshot while its Journey event is unresolved;
- delete recovered-order attribution merely to remove token identity;
- expose raw exception messages or secrets through cron responses.

The CI retention contract verifies these safety selectors on PHP 7.1 and PHP 8.5.
