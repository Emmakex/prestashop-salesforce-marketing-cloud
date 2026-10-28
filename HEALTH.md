# Health and queue observability

The connector exposes one local, read-only health report that is consumed by both external monitoring and the Back Office dashboard. Generating the report never calls Salesforce.

## Protected JSON endpoint

```text
/module/pssalesforcebridge/health?token=<cron-token>
```

The endpoint uses the same protected token already generated for the cron controller. It returns `403` when the token is absent or invalid.

The response is JSON with `Cache-Control: no-store` and `X-Robots-Tag: noindex, nofollow`.

## Back Office dashboard

The module configuration screen renders a health dashboard from the exact same `healthReport()` used by the JSON endpoint. There is no second severity calculation or queue-health implementation in the UI.

The dashboard includes:

- overall `healthy`, `warning` or `critical` state;
- current connector mode and PrestaShop/module versions;
- queue counts for `pending`, `processing`, `retry`, `failed` and `processed`;
- total outstanding work and oldest outstanding age;
- stale processing-lock count;
- active queue breakdown by event type/status;
- current operational issues;
- configuration readiness and missing configuration keys;
- customer backfill progress by shop;
- cart snapshot status totals;
- Journey delivery status totals;
- recovery-link / recovered-cart / attributed-order metrics;
- paid recovered revenue separated by currency.

Dynamic values rendered into the dashboard are escaped. The dashboard receives configuration-presence booleans only; it never receives or renders the Client Secret, Salesforce access token, recovery token, queue payload or customer email address.

The renderer uses basic server-rendered HTML/Bootstrap classes only. It does not depend on a JavaScript framework or a PrestaShop-generation-specific admin component.

If dashboard construction itself fails, the module configuration page remains usable and shows a generic local error instead of exposing an exception or secret value.

## Important rule

Generating either the JSON health endpoint or the Back Office dashboard never calls Salesforce.

Health reflects the local connector state: configuration readiness, queue pressure, terminal failures and stale worker locks. A Salesforce outage may later surface as retries/failures through the normal asynchronous worker, but opening health surfaces never waits for Salesforce.

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
- grouped active counts by event type and status.

The event-type breakdown is intentionally restricted to active/problem work (`pending`, `processing`, `retry`, `failed`) so health rendering does not become slower as the historical processed queue grows.

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

The module version in this example is illustrative. `0.7.0-A` and `0.7.0-B` are internal hardening microcuts; the public module version is not bumped until the production-hardening release boundary is closed.

## Monitoring

A monitoring system should interpret:

- HTTP `200` + `status=healthy`: normal;
- HTTP `200` + `status=warning`: degraded / investigate;
- HTTP `503` + `status=critical`: alert;
- HTTP `403`: monitoring credential is invalid or missing;
- HTTP `500`: the connector could not build its local health report.
