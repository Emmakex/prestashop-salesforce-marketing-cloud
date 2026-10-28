# Salesforce Data Extension — Customers

The customer worker expects a Marketing Cloud Data Extension whose **External Key** is configurable in the module and defaults to `PS_CUSTOMERS`.

The integration uses the synchronous REST upsert endpoint by External Key. `ContactKey` must therefore be the Data Extension primary key.

## Required schema

| Field | Suggested Marketing Cloud type | Required | Primary key |
| --- | --- | --- | --- |
| `ContactKey` | Text(100) | Yes | Yes |
| `CustomerId` | Number | Yes | No |
| `ShopId` | Number | Yes | No |
| `Email` | EmailAddress | No | No |
| `Firstname` | Text(255) | No | No |
| `Lastname` | Text(255) | No | No |
| `Newsletter` | Boolean | Yes | No |
| `OptIn` | Boolean | Yes | No |
| `Active` | Boolean | Yes | No |
| `LanguageId` | Number | No | No |
| `DateAdd` | Date | No | No |
| `DateUpd` | Date | No | No |

The field names are part of the connector contract. A future provisioning command may create the Data Extension automatically, but version 0.2.1 deliberately keeps schema creation explicit and auditable.

## Required API scope

The Salesforce Installed Package needs Data Extensions write permission (`data_extensions_write`) for the REST upsert endpoint.

## Delivery behavior

- PrestaShop hooks only enqueue normalized local events.
- The cron worker claims a batch and authenticates server-side.
- Salesforce calls never execute in the storefront request.
- Successful writes mark both the queue event and the local customer ledger as synchronized.
- Transient/remote failures use exponential retry and stop after five attempts.
- A lock token prevents two simultaneous worker executions from claiming the same events.

## Safety

The module defaults to `test` mode. The worker refuses outbound customer writes until mode is explicitly switched to `production`.
