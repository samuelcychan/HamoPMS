# Guest email notifications

Guest lifecycle email is event-driven. A confirmed reservation, pre-stay reservation modification, cancellation, or successfully settled payment creates a deduplicated `notification_deliveries` record and queues delivery. Payment receipts are triggered by both an explicit capture and a successful gateway webhook, while the per-payment deduplication key prevents duplicate receipts if both paths observe the same settlement.

Templates are translation resources under `lang/{locale}/notifications.php`. Each template has a subject and body with named replacement variables, so adding a locale does not require changing delivery code. The initial English templates are:

- `reservation_confirmation`
- `reservation_modification`
- `reservation_cancellation`
- `payment_receipt`

Each delivery log stores its template key, locale, recipient, rendered subject and payload, related property/reservation/payment IDs, attempt count, and lifecycle timestamps. Status progresses through `pending` → `sending` → `sent`; terminal failures are recorded as `failed` with the final error message and timestamp.

Delivery jobs retry three times with 60-second and 300-second backoffs. The default production queue connection is the database queue with `after_commit` enabled. Run a queue worker for delivery processing, for example `php artisan queue:work --tries=3`. The local default mailer writes mail to the application log; configure the existing `MAIL_*` environment variables for SMTP delivery.
