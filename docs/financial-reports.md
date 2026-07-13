# Financial reports and period close

`GET /api/v1/reports/revenue` requires `folios.read` and a property context supplied by `property_id` or `X-Property-ID`. `start_date` and `end_date` are inclusive `YYYY-MM-DD` values, default to today, and may span at most 366 days. `currency` defaults to `USD` and prevents unlike currencies from being combined.

Revenue is recognized from immutable folio line items by `posted_at`. The report separates tax lines from net revenue, returns their gross sum, fills a daily row for every requested date, and groups the same values by reservation room type. Ancillary voids contain a combined charge-and-tax reversal in the ledger; reporting allocates that reversal back to the original charge family's net and tax components.

Payment reconciliation uses completed capture and refund operation timestamps. Legacy completed payments without operation records fall back to the payment creation timestamp and captured amount (or nominal amount when captured amount was not recorded). The response reports captured, refunded, and net settled amounts overall and by payment method. `reconciliation.revenue_less_net_settlement` is gross posted revenue minus net settlement for the period.

## Immutable period close

`POST /api/v1/reports/revenue/period-close` requires `folios.adjust` and accepts the same property, period, and currency fields. It stores the complete report JSON, the closing actor and timestamp, and a SHA-256 checksum. The property, dates, and currency form a unique close scope. Repeating the same close returns the original snapshot without recomputing it, even if later ledger entries were posted.

`GET /api/v1/reports/revenue/period-close/{id}` retrieves a snapshot under the active property context. Period-close rows cannot be updated or deleted through the model.

## Immutable audit feed

`GET /api/v1/reports/audit-events` requires `folios.read` and a property context. It returns a single reverse-chronological, paginated contract over immutable folio postings, payment operations, and revenue period-close snapshots. Each event includes its source identifier, timestamp, actor when known, resource, action, status, signed amount when applicable, currency, and a non-secret reference.

Use `source` to select `folio_line_item`, `payment_operation`, or `revenue_period_close`. Inclusive `start_date` and `end_date` filters use `YYYY-MM-DD`, and `per_page` is limited to 100. The feed joins every source back to the active property, so events from another property are never returned.
