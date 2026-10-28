# Salesforce Marketing Cloud live certification

This document defines the controlled live-tenant certification gate for `pssalesforcebridge` 0.7.0.

It is separate from the PrestaShop runtime matrix. The runtime matrix proves the connector on real PrestaShop installations without Salesforce. This gate proves that the same production Salesforce client classes can authenticate and, only when explicitly enabled, write the expected Data Extension rows and fire the Journey Builder API Entry Event against a real Marketing Cloud Engagement tenant.

## Safety model

The workflow is `.github/workflows/sfmc-live-certification.yml` and is manual-only (`workflow_dispatch`). It never runs on push or pull request events.

It uses the GitHub environment `sfmc-live-certification`. Keep all tenant credentials and test identifiers in that environment and never commit credential values to the repository or pass them as workflow inputs.

The default stage is `auth`, which performs no Data Extension or Journey write. The `data_extensions` and `journey` stages require the operator to enable the `confirm_live_write` checkbox explicitly.

The emitted `sfmc-live-certification.json` artifact is sanitized. It records pass/fail state, commit/run identifiers, scope-presence booleans, hashes of the certification ContactKey and the Journey `eventInstanceId` when present. It does not record OAuth tokens, credentials, email addresses, Data Extension keys or account identifiers.

## Certification stages

| Stage | Live tenant effects | What it proves |
| --- | --- | --- |
| `auth` | OAuth token request only | Client credentials, optional Business Unit context and REST tenant routing are valid. The configured REST Base URI must match `rest_instance_url` returned by Salesforce. |
| `data_extensions` | Upserts one synthetic customer row and one synthetic order row | The active production `RestClient`, `CustomerDataExtensionWriter` and `OrderDataExtensionWriter` can write the configured Data Extensions with the connector's real field contract. |
| `journey` | Performs the two Data Extension upserts and fires one Journey API Entry Event | The active production `JourneyClient` can submit the connector event contract and Salesforce returns `eventInstanceId`. This can cause a real Journey/email for the dedicated certification contact. |

Stages are cumulative: `journey` includes the OAuth and Data Extension checks before it fires the event.

## Environment configuration

Create a GitHub environment named `sfmc-live-certification` and populate the environment secrets referenced by the workflow. They cover the Authorization Base URI, REST Base URI, server-to-server integration credentials, optional Business Unit MID, customer/order Data Extension external keys, Journey Event Definition Key, a dedicated certification mailbox/contact and an optional controlled recovery URL.

Use a dedicated test contact and mailbox. Do not use a real customer contact for this gate.

For Journey certification, the dedicated ContactKey must already be active in the target tenant. If no controlled recovery URL is configured, the harness uses an `example.invalid` URL so API contract testing cannot expose a real cart recovery token.

## Installed Package permissions

Use a Server-to-Server API Integration with only the permissions required by the selected stage.

- OAuth uses the standard client-credentials `/v2/token` flow.
- Data Extension upsert uses `POST /hub/v1/dataevents/key:{key}/rowset`, which requires Data Extension write access.
- Journey entry uses `POST /interaction/v1/events`, so the integration must have the Journey/contact permissions required by that endpoint in the target tenant.

If a Business Unit MID is configured, the OAuth request uses it as `account_id`.

## Running the gate

In GitHub Actions, open **SFMC Live Certification**, select **Run workflow**, select the exact ref to certify and choose a stage.

Recommended sequence:

1. Run `auth` first. It must pass without tenant writes.
2. Inspect the sanitized evidence artifact.
3. Run `data_extensions` with `confirm_live_write` enabled and verify the two synthetic rows in Marketing Cloud.
4. Prepare and activate the dedicated test Journey and active test contact.
5. Run `journey` with `confirm_live_write` enabled and confirm an `eventInstanceId` is returned.
6. Verify the expected Journey/email behavior using the dedicated certification contact.

The workflow explicitly checks out `github.sha`, so evidence is tied to the exact dispatched commit.

## What this gate does not certify

A green `journey` stage does not by itself prove the entire abandoned-cart recovery lifecycle. It does not create a real PrestaShop cart, generate and consume a real one-time recovery token, complete checkout or verify recovered-order attribution.

After the API/Journey gate is green, the final deployment certification must use a controlled PrestaShop staging shop through the actual module flow: create and abandon a test cart, let cron deliver the event, receive the Journey email, open the real one-time `RecoveryUrl`, verify sanitized cart restoration without implicit login, place the order, confirm `sfmc_recovery_order` attribution/revenue and verify that the recovery link cannot be reused.
