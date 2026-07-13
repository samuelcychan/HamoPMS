# Payment gateway abstraction

Application code depends on `Modules\Payment\Contracts\PaymentGateway`. The Stripe adapter creates manual-capture payment intents and implements capture, refund, and void operations through Stripe's HTTP API. The configured driver is selected by `PAYMENT_GATEWAY`.

Gateway failures are normalized as `GatewayException`, including a stable `GatewayErrorCode`, the gateway name, retryability, and non-sensitive provider details. Callers may retry only errors marked retryable and must reuse the same idempotency key. A received Stripe 5xx response is treated as indeterminate rather than retryable because Stripe retains the first response for an idempotency key; callers reconcile it through provider state and webhooks.

## Idempotency

`PaymentIdempotencyKey::for(operation, resourceId, version)` produces `hamopms:{operation}:{resource}:v{version}`. Every logical gateway mutation gets one key, stored with the local operation and reused unchanged for transport retries. A deliberate new operation or materially changed request increments the version; timeout retries do not. Internal payment IDs are globally unique, so they are the resource component for payment intents.

The public mutation APIs require an `Idempotency-Key` header. Payment creation stores that key on the payment; capture, refund, and void store it on an immutable operation record. Reusing the same key and request returns the prior result without another provider call. Reusing a key for different attributes, payment, or operation is rejected.

## Settlement operations

- `POST /api/v1/payments` creates a manual-capture Stripe intent for a card tender.
- `POST /api/v1/payments/{paymentId}/capture` captures the full tender.
- `POST /api/v1/payments/{paymentId}/refund` refunds a requested amount or the remaining captured balance.
- `POST /api/v1/payments/{paymentId}/void` cancels an uncaptured intent.

Split settlement is represented by multiple payments for one booking, such as a deposit and remaining balance. The folio response lists those payments and reports `settled_amount` plus signed `outstanding_balance`. Only captured value, less successful refunds, settles the folio.

## Stripe webhooks

`POST /api/v1/webhooks/stripe` reads the unmodified request body, requires the `Stripe-Signature` header, and verifies its timestamped HMAC with `STRIPE_WEBHOOK_SECRET`. `STRIPE_WEBHOOK_TOLERANCE` defaults to 300 seconds. Verification happens before JSON parsing or settlement changes. Accepted event IDs are persisted uniquely for replay protection and reconcile capture, cancellation, and refund status into the local payment. Invalid or stale signatures return `400` without recording an event.
