# UAT and go-live readiness

This runbook is the release record for validating HamoPMS with hotel operators and promoting a tested build to production. Copy the checklists into the release ticket, replace every placeholder, and attach the requested evidence. A release is not ready while any required item is blank or any critical defect remains open.

## Release record

| Field | Value |
|---|---|
| Release version and commit SHA | `<version>` / `<sha>` |
| Candidate environment | `<staging URL>` |
| Planned production window and timezone | `<window>` |
| Release manager | `<name>` |
| Product owner | `<name>` |
| Engineering lead | `<name>` |
| Database/recovery owner | `<name>` |
| Front Desk UAT lead | `<name>` |
| Housekeeping UAT lead | `<name>` |
| Finance UAT lead | `<name>` |
| Incident commander for launch | `<name>` |
| Approved recovery point objective (RPO) | `<duration>` |
| Approved recovery time objective (RTO) | `<duration>` |
| Rollback build SHA | `<sha>` |
| Backup identifier and restore drill | `<backup>` / `<result link>` |

## Entry criteria

- [ ] The candidate SHA is immutable and is the same SHA that will be deployed.
- [ ] Protected CI checks passed: `PHP 8.3 quality` and `Locked dependency audit`.
- [ ] Staging uses production-equivalent PHP, database, queue, cache, mail, and scheduler configuration.
- [ ] Migrations were rehearsed against a recent, sanitized production-sized dataset and their duration was recorded.
- [ ] The queue worker, scheduler, notification transport, payment sandbox, and integration sandbox are available.
- [ ] Two test properties, users with property-scoped roles, representative room inventory, and payment test instruments exist.
- [ ] Test data contains no production secrets or guest personal data.
- [ ] The rollback build, database backup, restore owner, RPO, and RTO are recorded above.
- [ ] UAT leads understand how to capture request IDs, timestamps, screenshots, report exports, and financial references.

For a disposable local baseline, use `Database\\Seeders\\StayLifecycleCiSeeder` as described in [the testing strategy](testing-strategy.md). UAT itself must use the candidate environment and configured sandbox providers.

## UAT execution rules

Record each result as `Pass`, `Fail`, or `Blocked`. Evidence must include the candidate SHA, property, operator, timestamp, relevant business identifiers, and the observed result. Never paste access tokens, card data, integration signing secrets, or guest personal data into the release ticket.

### Front Desk scenarios

| ID | Scenario and steps | Expected result | Required evidence | Owner |
|---|---|---|---|---|
| FD-01 | Search availability for one property and date range; create a reservation; repeat the search. | Only eligible clean inventory is offered, the reservation has the quoted rate, and availability decreases without affecting the second property. | Search and booking request IDs, booking ID, before/after inventory | Front Desk lead |
| FD-02 | Modify stay dates and room type; then exercise a policy-eligible cancellation on a separate booking. | Repricing and inventory changes are correct, the modification is auditable, and cancellation terms and status are applied once. | Booking IDs, quoted totals, audit timestamps | Front Desk lead |
| FD-03 | Check in a confirmed reservation; perform an in-stay room move; post an ancillary charge. | Room status transitions are correct, the old room is released appropriately, the new room is occupied, and the folio receives one charge. | Booking, room, folio, and line-item IDs | Front Desk lead |
| FD-04 | Capture payment and check out; verify the housekeeping handoff; process an allowed refund. | Folio balance and settlement state are correct, checkout completes once, the room becomes dirty, and the refund is linked to the original payment. | Payment references, closed folio, room status history | Front Desk and Finance leads |
| FD-05 | Repeat a sensitive action as a user assigned only to another property. | The API denies cross-property access and does not reveal whether the target record exists. | User role, property IDs, sanitized error response | Front Desk lead |

### Housekeeping scenarios

| ID | Scenario and steps | Expected result | Required evidence | Owner |
|---|---|---|---|---|
| HK-01 | Open the board after checkout; assign the dirty room; start and complete cleaning; mark it inspected. | The board, assignment, timestamps, and status history reflect each transition and the inspected room becomes eligible inventory. | Board captures and room status history | Housekeeping lead |
| HK-02 | Attempt invalid transitions and updates from another property. | Invalid state changes are rejected, concurrent updates do not silently overwrite work, and property isolation is enforced. | Sanitized responses and final room state | Housekeeping lead |
| HK-03 | Open a maintenance ticket that blocks inventory; resolve it and return the room through the approved clean/inspection flow. | The room is unavailable while blocked, the ticket history is auditable, and availability returns only after the required operational states. | Ticket ID, availability checks, status history | Housekeeping and Engineering leads |
| HK-04 | Review the housekeeping shift report for assignments, completion, inspection, and carry-over work. | Totals reconcile to the board and exceptions are actionable for the next shift. | Export or API response plus manual reconciliation | Housekeeping lead |

### Finance scenarios

| ID | Scenario and steps | Expected result | Required evidence | Owner |
|---|---|---|---|---|
| FI-01 | Review room, tax, and ancillary postings; attempt a duplicate posting; apply an allowed adjustment. | Ledger entries reconcile, duplicate/idempotent requests do not double-post, and adjustments preserve an audit trail. | Folio and line-item IDs, totals, request IDs | Finance lead |
| FI-02 | Authorize/capture a sandbox payment, retry the same idempotency key, and issue a partial or full refund. | One settlement is recorded, replay returns the existing result, and the refund and remaining balance are correct. | Provider references and ledger reconciliation | Finance lead |
| FI-03 | Run operational, occupancy, revenue, and financial audit reports for a closed period and property. | Report totals reconcile to source bookings, folios, payments, refunds, and audit events with no cross-property data. | Report exports and signed reconciliation worksheet | Finance lead |
| FI-04 | Attempt to mutate an immutable financial/audit record through supported APIs and direct operational workflows. | Mutation is unavailable or rejected; corrections are represented by new linked entries. | Sanitized response and linked correction records | Finance lead |

### Cross-cutting scenarios

| ID | Scenario and steps | Expected result | Required evidence | Owner |
|---|---|---|---|---|
| XC-01 | Trigger guest booking/check-in/check-out notifications, including a provider failure and retry. | Templates render the expected property/stay data, delivery state is auditable, and retry does not duplicate a successful delivery. | Delivery IDs and provider sandbox references | Product owner |
| XC-02 | Submit a valid signed integration event twice and then reuse its ID with different content. | The first event is accepted, the exact replay is idempotent, and conflicting content is rejected. Failed processing is visible and retryable. | Event ID, request IDs, operational status | Engineering lead |
| XC-03 | Exercise authentication expiry/revocation and property role changes during an active session. | Revoked or expired credentials stop working and permission changes take effect without data leakage. | Audit event IDs and sanitized responses | Engineering lead |

## Defect handling and UAT exit

| Severity | Definition | Release rule |
|---|---|---|
| Critical | Data loss/corruption, security or tenant-isolation breach, inability to check guests in/out, incorrect financial settlement | Stop testing where unsafe; blocks release |
| High | Core workflow has no acceptable workaround or produces materially wrong operational/financial state | Blocks release unless fixed and rerun |
| Medium | Workflow degradation with a documented safe workaround | Product owner must accept explicitly |
| Low | Cosmetic, documentation, or minor usability defect | May be scheduled with owner and due date |

UAT exits only when:

- [ ] Every scenario above has a result and evidence link.
- [ ] All Critical and High defects are closed and their affected scenarios rerun.
- [ ] Every accepted Medium/Low defect has an owner, due date, and documented workaround.
- [ ] Front Desk, Housekeeping, and Finance leads sign their scenario groups.
- [ ] Engineering signs migration, performance, security, observability, and recovery evidence.
- [ ] Product owner signs the complete release scope and known-risk register.

## Go-live checklist

### Five to two business days before launch

- [ ] **Release manager:** freeze scope, record the candidate and rollback SHAs, and publish the change summary.
- [ ] **Engineering:** verify production configuration keys exist without exposing values; confirm rate limits, queues, scheduler, mail, payment, and integration providers.
- [ ] **Database owner:** take or identify a restorable backup and complete a timed restore drill against isolated infrastructure.
- [ ] **Engineering:** record migration plan, expected duration, lock risk, backward compatibility, and rollback classification for every migration.
- [ ] **Operations leads:** finish UAT and staff training; publish manual fallback procedures for arrivals, departures, room status, and payment reconciliation.
- [ ] **Incident commander:** confirm on-call roster, escalation contacts, status channel, bridge details, and decision authority.

### One business day before launch

- [ ] **Release manager:** verify all required approvals and protected checks against the candidate SHA.
- [ ] **Operations leads:** confirm occupancy/arrival constraints and any blackout period for operational changes.
- [ ] **Finance lead:** record gateway balances, unsettled transactions, and reconciliation baseline.
- [ ] **Engineering:** confirm dashboards and alerts for API errors/latency, database health, queue depth/age, failed jobs, notifications, integrations, and payment failures.
- [ ] **Database owner:** confirm backup completion, retention, encryption, restore permissions, and available capacity.
- [ ] **Incident commander:** conduct the final go/no-go review; record the decision and every signatory.

### Deployment window

- [ ] **Release manager:** announce start and prevent unrelated deployments.
- [ ] **Engineering:** enable maintenance or compatibility controls only when required by the rehearsed plan.
- [ ] **Engineering:** deploy the recorded candidate SHA and run migrations with captured start/end timestamps.
- [ ] **Engineering:** restart workers safely, clear only documented caches, and verify scheduler/queue consumption.
- [ ] **Operations leads:** run smoke checks for login, property context, availability, booking, check-in, room status, folio/payment sandbox health, checkout, reports, notifications, and integrations.
- [ ] **Database owner:** compare row counts and integrity checks selected during rehearsal; verify replicas and backups remain healthy.
- [ ] **Incident commander:** record go/no-go after smoke checks. If a rollback trigger is met, execute the rollback section immediately.

### First business day after launch

- [ ] **Operations leads:** reconcile arrivals, departures, room status, open folios, payments/refunds, and shift reports.
- [ ] **Engineering:** review errors, slow requests, queue retries/dead letters, provider failures, and database performance since deployment.
- [ ] **Finance lead:** reconcile settlements and refunds to the gateway and audit report.
- [ ] **Release manager:** publish status, defects, owners, and the next hypercare checkpoint.

## Rollback decision

The incident commander owns the rollback call after consulting Operations, Engineering, Finance, and the database owner. Roll back when any of these occur and a safe forward fix cannot be completed inside the approved decision window:

- confirmed tenant isolation, authentication, or sensitive-data exposure;
- corrupted, missing, duplicated, or materially incorrect booking/folio/payment data;
- Front Desk cannot complete critical arrival or departure workflows;
- sustained API, database, or queue failure breaches the launch threshold recorded in the release ticket;
- migrations exceed the rehearsed window or leave application versions incompatible;
- payment/provider behavior risks duplicate or unreconciled financial activity.

Record the trigger, decision time, decision makers, affected period, and last known good transaction before acting.

## Application rollback runbook

1. Announce rollback, halt unrelated changes, and preserve logs, request IDs, failed-job data, provider references, and the incident timeline.
2. Stop or drain queue workers and scheduled writes using the rehearsed procedure. Disable affected inbound integrations or use provider-side holds if continued processing could worsen impact.
3. Put the API into the planned maintenance/read-only mode when needed to establish a consistent recovery boundary.
4. Deploy the recorded rollback SHA and restore its matching dependencies and configuration. Never select an unrecorded build during the incident.
5. Handle database changes according to their reviewed classification:
   - For a tested, reversible migration with no incompatible writes, run the exact reviewed rollback command and step count.
   - For additive/backward-compatible changes, leave the schema in place and run the previous compatible application.
   - For destructive or data-transforming changes, do not run `migrate:rollback` blindly; follow the data-recovery procedure below.
6. Restart workers on the rollback build, clear only documented caches, and re-enable scheduler/provider traffic gradually.
7. Repeat the deployment smoke checks and reconcile all transactions created during the incident window.
8. Announce the outcome, keep the incident open through reconciliation, and create follow-up actions before another release attempt.

## Data recovery runbook

1. Freeze writes or isolate the affected property/workflow and record the recovery boundary in UTC.
2. Preserve the affected database, logs, object storage, queue/dead-letter payloads, and provider transaction references as incident evidence.
3. Select the backup and, where supported, point-in-time target that satisfies the approved RPO. Obtain approval from the database owner and incident commander.
4. Restore into isolated infrastructure first. Do not overwrite the only production copy.
5. Validate migrations/schema version, tenant/property counts, bookings by status, rooms by status, open/closed folios, ledger totals, payment/refund references, audit-event continuity, failed jobs, integration events, and notification delivery state.
6. Reconcile post-recovery transactions with Front Desk and Finance. Reapply only reviewed, idempotent operations; never replay payment or integration traffic without checking provider state.
7. Cut over using the platform's rehearsed database procedure, rotate credentials if exposure is suspected, restart workers, and run smoke checks.
8. Record actual data loss window, recovery duration, validation evidence, guest/financial remediation, and required notifications.

## Hypercare and incident response

Hypercare lasts for the first 72 hours by default; the release manager may extend it. Staffing and checkpoints must cover the properties' arrival, departure, housekeeping shift, and finance reconciliation peaks.

Monitor at least:

- API availability, 4xx/5xx changes, p95 latency, and rate-limit responses;
- database connections, locks, slow queries, storage, replicas, and backup completion;
- queue depth, oldest-job age, retries, failed jobs, and worker restarts;
- booking conflicts, failed check-in/out actions, and unexpected room-status transitions;
- folio imbalance, payment failure/duplication, refunds, and settlement reconciliation;
- notification failure/retry rates and integration failed/dead-lettered events;
- authentication failures, permission denials, and suspicious cross-property attempts.

| Severity | Examples | Acknowledge/escalate | Update cadence |
|---|---|---|---|
| SEV-1 | Security/data integrity incident, critical hotel workflow unavailable, payment corruption | Page incident commander and domain owners immediately | Every 15 minutes |
| SEV-2 | Major degradation or repeated provider failures with a limited workaround | Engage on-call Engineering and Operations immediately | Every 30 minutes |
| SEV-3 | Limited defect with a safe workaround | Assign during the current hypercare shift | At each scheduled checkpoint |

For every incident:

1. Open one incident record with UTC start time, commander, severity, affected properties/workflows, and current impact.
2. Stabilize safety first: stop harmful writes/provider traffic and preserve evidence.
3. Correlate application request IDs with logs, jobs, database records, notification/integration event IDs, and provider references.
4. Decide forward fix versus rollback using the recorded thresholds and decision authority.
5. Communicate known facts, mitigations, next update time, and operator workarounds; do not speculate.
6. Verify recovery with the affected operational lead and Finance when money or folios are involved.
7. Close only after monitoring remains healthy through an agreed observation period and reconciliation is complete.
8. Schedule a blameless review for every SEV-1/SEV-2, with owners and due dates for corrective actions.

## Sign-off

| Gate | Approver | Decision/time | Evidence or accepted risk |
|---|---|---|---|
| Automated quality and security | Engineering lead | `<decision>` | `<link>` |
| Front Desk UAT | Front Desk lead | `<decision>` | `<link>` |
| Housekeeping UAT | Housekeeping lead | `<decision>` | `<link>` |
| Finance UAT and reconciliation | Finance lead | `<decision>` | `<link>` |
| Backup, restore drill, RPO/RTO | Database owner | `<decision>` | `<link>` |
| Operational readiness | Incident commander | `<decision>` | `<link>` |
| Final go/no-go | Product owner and release manager | `<decision>` | `<link>` |
