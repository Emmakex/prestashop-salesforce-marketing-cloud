# Sync correctness invariants

This connector treats the local synchronization ledgers as current-state ledgers. An older queue event must never overwrite or close a newer observed state.

## Customer queue ownership

Customer delivery claims queue rows by `entity_type=customer`, not by a fixed list of event names. This guarantees that live customer events and historical `CUSTOMER_BACKFILL` events share the same delivery worker and normalized Salesforce contract.

## Hash-conditional completion

`CustomerSyncLedger::markSynced()` and `OrderSyncLedger::markSynced()` only mark a row `synced` when the ledger still contains the exact payload hash that was delivered.

If a newer customer or order state is observed while an older event is in flight:

1. the ledger payload hash changes and remains `pending`;
2. the older event may still complete its Salesforce write;
3. the older event cannot overwrite the newer hash or mark the newer state `synced`;
4. the newer queue event remains available for delivery.

This keeps eventual delivery monotonic toward the latest observed PrestaShop state.

## CI contract

`tests/sync-correctness-contract.php` checks the two invariants at both supported PHP matrix extremes:

- customer queue claim uses `entity_type=customer` and does not enumerate live event names;
- customer and order ledger completion include the expected payload hash in the update condition and do not overwrite the stored hash.
