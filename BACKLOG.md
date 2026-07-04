# HamoPMS Backlog: Epics, Features & Tasks

This document is the project backlog, structured as **Epic → Feature → Task**, ready to be filed as GitHub issues (this environment does not currently have an issue-creation tool available, so the breakdown is provided here for manual/scripted creation).

Suggested GitHub conventions:
- Labels: `epic`, `feature`, `task`, plus a module label per epic (e.g. `module:reservations`).
- Each Epic issue's body is a checklist of its Feature issues (linked once created); each Feature issue's body is a checklist of its Task issues.
- Epics can additionally be tracked as Milestones if you prefer milestone-based reporting alongside labels.

## Phase 0 — Foundation (implemented in this PR)

### Epic: Foundation & Architecture
- **Feature: Framework scaffolding & coding standards** — DONE (Laravel skeleton, Pint, PHPUnit, `.editorconfig`)
- **Feature: Multi-tenant data model** — DONE (`properties` table, `BelongsToProperty` trait, `PropertyContext`, `ResolvePropertyContext` middleware)
- **Feature: Auth & RBAC** — DONE (Sanctum tokens, spatie/laravel-permission team-scoped roles, `PermissionSeeder`)
- **Feature: API layer & docs** — DONE (`/api/v1` structure, health endpoint, `dedoc/scramble` dependency for OpenAPI)
  - Task: Publish and review generated OpenAPI schema once dependencies are installed
  - Task: Add API versioning/deprecation policy doc
- **Feature: Webhook/event engine core** — DONE (`webhook_subscriptions`, `webhook_deliveries`, `WebhookDispatcher`, `DeliverWebhook` job with HMAC signing)
  - Task: Add subscription management CRUD endpoints (`/api/v1/webhooks/subscriptions`)
  - Task: Add delivery retry/backoff configuration UI

## Phase 1 — Core PMS Operations (MVP)

### Epic: Room & Room Type Management
- Feature: Room type configuration (amenities, max occupancy, base rates)
  - Task: `room_types` migration + model (property-scoped)
  - Task: CRUD API + form requests + policies
  - Task: Amenity taxonomy (JSON or pivot table)
- Feature: Room status state machine (`vacant_clean → occupied → vacant_dirty → clean → inspected → guest_ready`)
  - Task: `rooms` migration + model, state machine enum/service
  - Task: State transition API + validation guards
  - Task: Audit log of status changes
- Feature: Connecting rooms & ADA/accessible tracking
  - Task: Self-referential `connecting_room_id` relation
  - Task: ADA flag + reporting filter
- Feature: Room status dashboard summary
  - Task: Aggregate endpoint (`/api/v1/rooms/summary`) grouped by status/floor

### Epic: Rate Plans & Pricing
- Feature: BAR / derived / negotiated rate plans
  - Task: `rate_plans` migration + model, `parent_rate_plan_id` for derivation
  - Task: Derivation calculator (amount/percentage adjustment)
- Feature: Restrictions (MinLOS, MaxLOS, CTA, CTD)
  - Task: `rate_restrictions` migration + model, date-range aware
  - Task: Restriction evaluation service used by availability engine
- Feature: Effective rate calculation
  - Task: `RateCalculator` service (date range, occupancy-aware)
- Feature: Occupancy-based rate adjustments
  - Task: Per-occupancy rate table + calculator integration

### Epic: Reservation Management
- Feature: Lifecycle state machine (`pending → confirmed → assigned → checked_in → stayover → due_out → checked_out`)
  - Task: `reservations` migration + model + state machine service
  - Task: Webhook events on every transition
- Feature: Availability engine
  - Task: Inventory/availability calculation service
  - Task: Search/quote API endpoint
- Feature: Room assignment
  - Task: Assignment service with auto room-status transition
- Feature: Group check-in & bulk actions
  - Task: Batch check-in/check-out/cancel endpoints with per-item success/error results
- Feature: Reservation notes & guest messaging
  - Task: `reservation_notes` table + active-count tracking
  - Task: Messaging endpoint (GDPR opt-out enforced)
- Feature: No-show & cancellation policy enforcement
  - Task: Policy engine + cancellation fee calculation
  - Task: Explicitly unsupported "un-cancel" (create-new-reservation guidance)
- Feature: Batch reservation import
  - Task: CSV import endpoint with per-row error handling (ties into Data Migration epic)

### Epic: Guest Profiles
- Feature: Guest CRUD, contact & preferences
- Feature: VIP level tracking (standard/silver/gold/platinum/diamond)
- Feature: Do Not Rent (DNR) flagging
- Feature: GDPR consent tracking & data retention controls
- Feature: Guest search with flexible filters

### Epic: Folio & Billing
- Feature: Guest/master folios & city ledger accounts
- Feature: Charge posting (department codes, revenue categories)
- Feature: Charge routing rules (e.g. room & tax → company, incidentals → guest)
- Feature: Charge reversal & transfer between folios
- Feature: Folio settlement & close workflow
- Feature: Charge locking for night audit

### Epic: Housekeeping
- Feature: Task CRUD & 6 task types (checkout, stayover, deep clean, inspection, turndown, maintenance)
- Feature: Digital checklist templates (ADA/VIP-aware augmentation)
- Feature: Auto-task creation on checkout (event-driven via `room.status_changed`)
- Feature: Staff assignment & round-robin auto-assignment
- Feature: Task lifecycle (`pending → assigned → in_progress → completed → inspected`) + inspection pass/fail
- Feature: Housekeeping dashboard & analytics (turn time, inspection pass rate)

### Epic: Night Audit & Reporting
- Feature: Automated night audit run (room revenue posting, no-show processing, rate validation, day close)
- Feature: Daily revenue reports (department breakdown)
- Feature: Occupancy reports (ADR, RevPAR)
- Feature: Financial summaries & occupancy trend analysis
- Feature: KPI dashboard endpoints

### Epic: Tax Calculation Engine
- Feature: Jurisdiction-based tax rules (state/city/county)
- Feature: Tax types (sales, occupancy/lodging, tourism, VAT)
- Feature: Inclusive/exclusive calculation, rate-based & fixed-amount taxes
- Feature: Tax-exempt guest handling
- Feature: Automatic tax application on charge posting + folio breakdown

### Epic: Media & Photos
- Feature: Polymorphic media model (properties, room types, rooms) with `property_id` scoping
- Feature: URL-based and S3/MinIO upload drivers (env-selected)
- Feature: Ordering, captions, alt text, categories, single enforced primary image
- Feature: Dashboard photo galleries

## Phase 2 — Advanced Operations & Revenue

### Epic: Payments
- Feature: Stripe integration (PaymentIntents, tokenization, PCI-safe)
- Feature: Authorization/capture/void/refund workflows
- Feature: Payment method tracking (card, cash, bank transfer), folio linkage

### Epic: Groups & Allotment
- Feature: Group profiles (corporate, travel-agent, wholesale, event) + optional group/master folio
- Feature: Allotment blocks (quantity, cutoff, shoulder dates, Min/Max LOS), inventory validation
- Feature: Cutoff & auto-release (per-block and sweep endpoint)
- Feature: Pickup tracking (allotted vs. picked up, pickup rate)
- Feature: Rooming list batch import

### Epic: Accounting & Cashiering
- Feature: Deposit ledger (`held → applied → refunded/forfeited` lifecycle)
- Feature: Accounts Receivable ledgers, transfer/reverse-transfer, aging buckets
- Feature: Cash drawer & shift sessions, cash movements, variance detection
- Feature: Daily trial balance reconciliation
- Feature: Custom accounting/GL codes

### Epic: Split Folios & House Accounts
- Feature: Split folio config-driven routing, per-charge-type transaction moves
- Feature: House accounts (non-guest ledger, product catalog, open/close lifecycle)
- Feature: Correction matrix (void/refund/adjustment policy with illegal-override rejection)

### Epic: Data Migration & Import / Accounting Export
- Feature: Generic CSV import framework (column mapping, dry-run, per-row errors)
- Feature: Entity importers (guests, room types, rate plans)
- Feature: CSV accounting export (daily revenue journal, trial balance)

### Epic: Direct Booking Engine
- Feature: Public booking API (search → quote → book → pay → confirm), server-side re-quoting
- Feature: Publishable-key auth (`x-booking-key`), property-scoped, low-trust
- Feature: Embeddable widget app
- Feature: Dashboard Settings → Booking Engine tab (key rotation, sellable rates, branding, deposit policy)

### Epic: Webhook Engine (full event catalog)
- Feature: Implement remaining event types across all modules (target: parity with HAIP's 64 event types)

## Phase 3 — Distribution & Intelligence

### Epic: Channel Manager
- Feature: ARI sync engine (availability, rates, inventory push)
- Feature: Content distribution (photos/descriptions/amenities) with auto-resync
- Feature: Booking.com adapter (OTA XML + Photo API)
- Feature: Expedia (EQC) adapter (XML ARI + Image API)
- Feature: SiteMinder aggregator adapter (SOAP/OTA XML, 450+ OTA reach)
- Feature: Rate parity monitoring & enforcement, stop-sell, sync logging

### Epic: AI Agent Framework
- Feature: Common agent interface & decision logging (`analyze → recommend → execute → recordOutcome → train`)
- Feature: Per-property calibration/model state storage
- Feature: Revenue Manager orchestrator
- Feature: Demand Forecasting agent
- Feature: Dynamic Pricing agent
- Feature: Channel-Mix Optimization agent
- Feature: Overbooking Management agent
- Feature: Night Audit Anomaly Detection agent
- Feature: Housekeeping Optimization agent
- Feature: Cancellation Prediction agent (+ deposit-forfeit risk scoring)
- Feature: A/R Collections Prioritization agent
- Feature: Group Pickup Forecasting agent
- Feature: Guest Communication agent (template-based lifecycle emails, GDPR opt-out)
- Feature: Review Response agent (keyword topic extraction, sentiment, template assembly)

### Epic: ChatGPT Gateway (optional)
- Feature: Connect API client (typed wrapper over HAIP-equivalent Connect API)
- Feature: OpenAPI 3.1 Action spec generation for ChatGPT Custom GPT
- Feature: PII-scrubbed tool-call logging
- Feature: Deployment target (serverless function / Docker)

### Epic: Admin Dashboard (Frontend)
- Feature per page: Dashboard, Reservations, Check-In/Out, Guests, Rooms, Housekeeping, Rate Plans, Folios, Night Audit, Reports, Channel Manager, Revenue Management, Communications, Reviews, Settings
- Feature: Shared UI (real-time updates, calendar view, kanban board, KPI cards, skeleton/loading states, toasts, error boundaries)

### Epic: DevOps & Infrastructure
- Feature: Docker/Docker Compose setup (app, PostgreSQL, Redis, queue worker)
- Feature: CI/CD pipeline hardening (staging/prod deploy)
- Feature: Environment configuration & secrets management docs
- Feature: Deployment documentation
