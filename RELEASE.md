# Release policy

## 0.7.0 production-hardening release

The public module version for this release is `0.7.0`.

The installable artifact is built by CI as:

- `pssalesforcebridge-0.7.0.zip`
- `pssalesforcebridge-0.7.0.zip.sha256`

The ZIP must contain a single top-level `pssalesforcebridge/` module directory, must include `upgrade/upgrade-0.7.0.php`, and must not include a Composer `vendor/` directory or local development files.

## Compatibility declaration

The declared target remains:

- PrestaShop 1.7.8.x
- PrestaShop 8.x
- PrestaShop 9.x
- PHP 7.1 and newer at source-contract level

This release gate proves syntax, internal contracts, upgrade/default parity and package integrity. It does **not** by itself certify runtime behavior on a real PrestaShop installation. Runtime certification remains a separate 0.7.0-E gate.

## Upgrade policy

Supported upgrade path for this release:

`0.6.1 -> 0.7.0`

The 0.7.0 upgrade performs no database DDL because the 0.6.1 schema already contains every table required by the hardening work. The upgrade only materializes missing privacy-retention configuration defaults.

Existing merchant values are never overwritten by the 0.7.0 upgrade.

Fresh installation and upgrade converge on these defaults:

- processed queue retention: 30 days
- local log retention: 30 days
- Journey audit retention: 90 days
- cart snapshot retention: 30 days
- recovery token retention: 30 days
- last retention run: empty

All older sequential upgrade scripts remain packaged so PrestaShop can advance installations from earlier module versions in order.

## Release gate

A release candidate is acceptable only when the exact candidate head passes:

1. PHP syntax matrix: 7.1, 7.4, 8.0, 8.1, 8.4, 8.5.
2. Health contracts: PHP 7.1 and 8.5.
3. Sync correctness contracts: PHP 7.1 and 8.5.
4. Retention contracts: PHP 7.1 and 8.5.
5. Release/upgrade contracts: PHP 7.1 and 8.5.
6. Versioned ZIP build and package validation.
7. SHA-256 checksum generation for the exact ZIP.

The same complete gate must pass again on the exact `main` SHA produced by the merge.

## Release safety

The release process must never:

- commit Salesforce credentials, access tokens, cron tokens or customer payloads;
- infer runtime certification from lint or static contract tests;
- overwrite existing merchant retention values during upgrade;
- introduce hidden schema changes outside an explicit upgrade script;
- publish an artifact whose embedded module version differs from its filename;
- publish an artifact from a different SHA than the certified release head.
