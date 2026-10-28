# Health and queue observability

The connector exposes a local, read-only health report intended for production monitoring without introducing a dependency on Salesforce availability.

## Endpoint

```text
/module/pssalesforcebridge/health?token=<cron-token>
```

The endpoint uses the same protected token already generated for the cron controller. It is intentionally not public and returns `403` when the token is absent or invalid.

The response is JSON, `Cache-Control: no-store`, and `X-Robots-Tag: noindex, nofollow`.

## Important rule

Generating a health report never calls Salesforce.

Health reflects the state of the local connector: configuration readiness, queue pressure, terminal failures and stale worker locks. A Salesforce outage may later surface as retries/failures through the normal asynchronous worker, but opening the health endpoint itself never waits for Salesforce.

## Status levels

### `healthy`

No local condition currently requires intervention.

In `test` mode an old pending queue is allowed because outbound delivery is intentionally disabled. The response includes:

```json
{
  "notes": ["outbound_delivery_disabled_in_test_mode"]
}
```

### `warning`

The connector can continue operating but there is a condition worth investigating.

Current warning conditions:

- one or more `processing` rows have held a lock for more than 600 seconds;
- in production, the oldest outstanding event is at least 900 seconds old.

Warnings return HTTP `200` so monitoring systems can distinguish degraded operation from a hard failure while still inspecting the `status` field.

### `critical`

The connector requires intervention or cannot be considered production-ready.

Current critical conditions:

- one or more queue rows reached terminal `failed` state;
- required production configuration is incomplete;
- the cron token is missing;
- in production, the oldest outstanding event is at least 3600 seconds old.

Critical health returns HTTP `503`.

## Configuration checks

The health service only receives booleans indicating whether required configuration exists. It never receives or returns the Client Secret itself.

Production requires:

- Authentication Base URI;
- REST Base URI;
- Client ID;
- Client Secret presence;
- Customer Data Extension External Key;
- Order Data Extension External Key;
- Journey Event Definition Key;
- cron token.

Test mode only requires the cron token because local capture is intentionally usable before Salesforce delivery is configured.

## Queue metrics

The health report includes:

- counts for `pending`, `processing`, `retry`, `failed` and `processed`;
- total outstanding work (`pending + processing + retry`);
- oldest outstanding event timestamp and age in seconds;
- number of stale processing locks;
- grouped counts by event type and status.

It does not expose payloads, customer email addresses, recovery tokens, Client Secrets or Salesforce access tokens.

## Example

```json
{
  "status": "warning",
  "ok": true,
  "generated_at": "2026-10-28T11:00:00+00:00",
  "mode": "production",
  "configuration": {
    "complete_for_mode": true,
    "missing": []
  },
  "queue": {
    "counts": {
      "pending": 2,
      "processing": 0,
      "retry": 0,
      "failed": 0,
      "processed": 140
    },
    "outstanding_total": 2,
    "oldest_outstanding_at": "2026-10-28 10:40:00",
    "oldest_outstanding_age_seconds": 1200,
    "stale_processing": 0,
    "by_event_type": []
  },
  "issues": [
    {
      "code": "queue_backlog_warning",
      "severity": "warning",
      "oldest_age_seconds": 1200
    }
  ],
  "notes": [],
  "prestashop_version": "8.x",
  "module_version": "0.6.1"
}
```

The module version in this example is illustrative. `0.7.0-A` is an internal hardening microcut; the public module version is not bumped until the production-hardening release boundary is closed.

## Monitoring

A monitoring system should interpret:

- HTTP `200` + `status=healthy`: normal;
- HTTP `200` + `status=warning`: degraded / investigate;
- HTTP `503` + `status=critical`: alert;
- HTTP `403`: monitoring credential is invalid or missing;
- HTTP `500`: the connector could not build its local health report.
