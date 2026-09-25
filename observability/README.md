# Observability

Metrics, traces and logs follow Pimsen's setup (Pimsen ADR-026, `../pimsen/docs/06-decisions.md`), with `kanso_` in place of `pim_`. This directory holds what an operator loads into their own monitoring. Nothing in an installation depends on it.

| Path | What it is |
|---|---|
| `prometheus/kanso-rules.yml` | Recording rules (`installation:kanso_*`) that both dashboards and the alerts read |
| `prometheus/kanso-alerts.yml` | Alerts: scrape, 5xx ratio, p95 over the 500 ms budget, stalled workers, a growing backlog, failed messages, mixed releases |
| `prometheus/tests/` | `promtool` unit tests for the alerts (`make observability-check`) |
| `grafana/dashboards/` | `kanso-installation` (one installation) and `kanso-fleet` (every installation, linking to the first) |
| `collector/otel-collector.yaml` | A reference OpenTelemetry Collector pipeline: adds `installation`, and is where tail sampling goes |
| `local/` | Wiring for the opt-in local stack in `compose.yaml` |

`ObservabilityFilesTest` (backend) fails if a file here reads a `kanso_*` series that `PrometheusMetrics` does not declare.

## Metrics

The API serves `GET /metrics` in the Prometheus text format. It is **not** routed by the reference proxy, so scrape the API container directly. If a deployment exposes it through its public entry point, it has to restrict access there.

Counters and histograms live in the installation's Redis, because PHP-FPM processes keep nothing between requests. Every API replica therefore serves the same, installation-wide numbers, and the rules take `max` over scrape targets before summing.

| Series | Labels |
|---|---|
| `kanso_http_request_duration_seconds` (histogram; `_count` is the request counter) | `route` (the route name, never the path), `method`, `status` |
| `kanso_messenger_messages_total` | `transport`, `result` = `handled` / `retried` / `failed` |
| `kanso_messenger_handler_duration_seconds` (histogram) | `transport`, `message` (class short name) |
| `kanso_queue_depth` (read from MySQL at scrape time) | `transport` |
| `kanso_build_info` (per process) | `version`, `env` |

`/health/*` and `/metrics` itself are not recorded.

**Two conventions the scrape must provide:** `job="kanso"`, and an `installation="<slug>"` label on every series. The process doesn't know its own slug, so the scrape adds it.

## Traces

Tracing has one setting: `OTEL_EXPORTER_OTLP_ENDPOINT`. **Empty (the default) means off**, and costs nothing. Once set, spans go to `<endpoint>/v1/traces` as OTLP/HTTP protobuf, with a 2 s timeout and no retries. The SDK's other `OTEL_*` variables are never read. The service name is `kanso-api`, `kanso-worker` or `kanso-console`, and sampling is `parentbased(always_on)`. Decide what to keep in the collector (tail sampling).

Spans are placed by hand:

- one SERVER span per API request, named `<METHOD> <route name>`, continuing an incoming `traceparent`;
- a PRODUCER span where a message is dispatched and a CONSUMER span where it is handled, joined by a `TraceContextStamp` carrying the W3C `traceparent` in the envelope. Retries keep the stamp, so every attempt belongs to the same trace.

Span attributes name the message class, never its contents.

**Turning tracing on is its own deployment step**, taken after a rollout has finished. A release that predates `TraceContextStamp` cannot unserialize an envelope that carries one, and the stamp is only added while tracing is on.

## Logs

Monolog writes JSON to stderr. A line written while a span is current carries `extra.trace_id` and `extra.span_id`, so you can follow a line to its trace and a trace to its lines.

## Running it locally

```
docker compose --profile observability up -d
# then, in .env: OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318
make up        # recreates api and worker with the endpoint
```

Grafana is on http://localhost:13010 (anonymous admin), Prometheus on http://localhost:19091, and Jaeger on http://localhost:16696.
