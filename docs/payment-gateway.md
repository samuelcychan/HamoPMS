# Payment gateway abstraction

Application code depends on `Modules\Payment\Contracts\PaymentGateway`. The initial `StripeGateway` adapter creates payment intents through Stripe's HTTP API and is selected by the `PAYMENT_GATEWAY` configuration value. Capture, refund, void, and split-settlement orchestration remain outside this baseline and will build on the same contract.

Gateway failures are normalized as `GatewayException`, including a stable `GatewayErrorCode`, the gateway name, retryability, and non-sensitive provider details. Callers may retry only errors marked retryable and must reuse the same idempotency key. A received Stripe 5xx response is treated as indeterminate rather than retryable because Stripe retains the first response for an idempotency key; callers reconcile it through provider state and webhooks.

## Idempotency

`PaymentIdempotencyKey::for(operation, resourceId, version)` produces `hamopms:{operation}:{resource}:v{version}`. Every logical gateway mutation gets one key, stored with the local operation and reused unchanged for transport retries. A deliberate new operation or materially changed request increments the version; timeout retries do not. Internal payment IDs are globally unique, so they are the resource component for payment intents.

## Stripe webhooks

The webhook endpoint will read the unmodified request body, require the `Stripe-Signature` header, and verify its timestamped HMAC with `STRIPE_WEBHOOK_SECRET` using Stripe's supported signature scheme and a bounded clock tolerance. Verification must happen before JSON parsing or queue dispatch. Accepted event IDs will be persisted uniquely for replay protection, acknowledged quickly, and reconciled asynchronously against local payment state. Invalid signatures return a non-success response without exposing verification details.
