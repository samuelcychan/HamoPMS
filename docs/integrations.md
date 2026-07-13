# External integration contracts

External OTA and channel connectors send canonical events to `POST /api/v1/integrations/{provider}/events`. Every body contains `property_id`, a stable event `type`, the provider's `occurred_at` timestamp, and an open-ended `data` object. The `X-Integration-Event-ID` header is the provider's idempotency key. Reusing it with the same raw payload returns the existing event; reuse with a different payload returns `409`.

The `X-Integration-Signature` header is the lowercase hexadecimal HMAC-SHA256 of the exact request body using the provider secret. Providers without a configured secret are disabled. A provider-and-IP rate limit protects the public endpoint.

Adapter classes implement `Modules\Integration\Contracts\IntegrationAdapter` and are selected from `config/integrations.php`. The bundled sandbox adapter writes structured processing logs and is a safe template for OTA/channel implementations. Inbound payloads are persisted before a queued `ProcessIntegrationEvent` job invokes the adapter.

Jobs retry three times by default with 60- and 300-second backoffs. Processing state, attempt count, timestamps, and bounded error text are stored in `integration_events`. Exhausted jobs become `dead_lettered`. Authorized staff can list property-scoped state at `GET /api/v1/integration-events` and requeue failed or dead-lettered records with `POST /api/v1/integration-events/{eventId}/retry`. Raw inbound payloads and their hashes are intentionally omitted from operational responses.
