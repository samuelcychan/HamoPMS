# HamoPMS GitHub Issue Backlog

This document turns the initial HamoPMS implementation plan into a ready-to-paste GitHub issue backlog with explicit parent-child relationships.

## Suggested labels

- `epic`
- `feature`
- `task`
- `mvp`
- `phase-2`
- `backend`
- `frontend`
- `database`
- `security`
- `reporting`
- `integration`

## Dependency order

1. Define product scope
2. Establish technical foundation
3. Design core domain model
4. Authentication and access control
5. Property setup and configuration
6. Reservation management
7. Front desk operations
8. Folio, billing, and payments
9. Housekeeping and maintenance
10. Reporting and dashboards
11. Notifications and integrations
12. Security, audit, and hardening

## Epics, features, and tasks

### [Epic] Define product scope
**Labels:** `epic`, `mvp`  
**Description:** Define the HamoPMS MVP boundaries, workflows, and role model for a greenfield PHP hotel PMS inspired by HAIP.  
**Acceptance criteria:**
- MVP scope is defined
- Phase-2 scope is defined
- Core business workflows are documented
- Role matrix is documented

#### [Feature] Define PMS MVP
**Parent:** [Epic] Define product scope  
**Description:** Define the MVP boundary for HamoPMS.
- [Task] List in-scope MVP modules
- [Task] List out-of-scope phase-2 modules
- [Task] Define single-property vs multi-property scope

#### [Feature] Define business workflows
**Parent:** [Epic] Define product scope  
**Description:** Document key hotel workflows.
- [Task] Document reservation lifecycle
- [Task] Document guest stay lifecycle
- [Task] Document folio and payment lifecycle
- [Task] Document housekeeping workflow

#### [Feature] Define user roles
**Parent:** [Epic] Define product scope  
**Description:** Define staff roles and permissions.
- [Task] Define admin permissions
- [Task] Define front desk permissions
- [Task] Define housekeeping permissions
- [Task] Define finance and manager permissions

### [Epic] Establish technical foundation
**Labels:** `epic`, `backend`  
**Description:** Select the PHP stack, define engineering standards, and bootstrap the platform.  
**Acceptance criteria:**
- Framework and infrastructure choices are documented
- Engineering standards are documented
- Initial application bootstrap approach is defined

#### [Feature] Select application stack
**Parent:** [Epic] Establish technical foundation  
**Description:** Choose the technology stack for the platform.
- [Task] Choose PHP framework
- [Task] Choose database, cache, queue, storage
- [Task] Define deployment environment

#### [Feature] Define engineering standards
**Parent:** [Epic] Establish technical foundation  
**Description:** Define engineering conventions and delivery standards.
- [Task] Define project structure
- [Task] Define coding standards
- [Task] Define migration and seeding strategy
- [Task] Define testing strategy

#### [Feature] Bootstrap platform
**Parent:** [Epic] Establish technical foundation  
**Description:** Set up the initial application and delivery baseline.
- [Task] Initialize PHP application
- [Task] Configure environment settings
- [Task] Set up CI baseline

### [Epic] Design core domain model
**Labels:** `epic`, `backend`, `database`  
**Description:** Define the core PMS entities, relationships, statuses, and lifecycle rules.  
**Acceptance criteria:**
- Inventory model is defined
- Reservation and guest model is defined
- Billing model is defined

#### [Feature] Property and room inventory
**Parent:** [Epic] Design core domain model  
**Description:** Define property and room inventory entities.
- [Task] Model properties
- [Task] Model room types
- [Task] Model rooms
- [Task] Model room status and maintenance states

#### [Feature] Guest and reservation model
**Parent:** [Epic] Design core domain model  
**Description:** Define guest and reservation entities.
- [Task] Model guest profiles
- [Task] Model reservations
- [Task] Model rate plans and packages
- [Task] Model booking sources and statuses

#### [Feature] Billing model
**Parent:** [Epic] Design core domain model  
**Description:** Define billing and financial entities.
- [Task] Model folios
- [Task] Model charges and taxes
- [Task] Model payments and refunds
- [Task] Model audit history

### [Epic] Authentication and access control
**Labels:** `epic`, `backend`, `security`  
**Description:** Implement authentication, session handling, and role-based authorization.  
**Acceptance criteria:**
- Authentication flows are defined
- RBAC model is defined
- Permission enforcement boundaries are defined

#### [Feature] User authentication
**Parent:** [Epic] Authentication and access control  
**Description:** Support login and session lifecycle.
- [Task] Implement login
- [Task] Implement password reset
- [Task] Implement session management

#### [Feature] Authorization
**Parent:** [Epic] Authentication and access control  
**Description:** Support role and permission enforcement.
- [Task] Implement role-based access control
- [Task] Enforce module-level permissions
- [Task] Enforce action-level permissions

### [Epic] Property setup and configuration
**Labels:** `epic`, `backend`  
**Description:** Build hotel setup, inventory configuration, and rate setup capabilities.  
**Acceptance criteria:**
- Hotel profile setup is supported
- Inventory setup is supported
- Rate setup is supported

#### [Feature] Hotel configuration
**Parent:** [Epic] Property setup and configuration  
**Description:** Configure property-level operational settings.
- [Task] Set hotel profile settings
- [Task] Set currency, timezone, tax settings
- [Task] Configure invoice and receipt settings

#### [Feature] Inventory setup
**Parent:** [Epic] Property setup and configuration  
**Description:** Configure room inventory and operational rules.
- [Task] Configure room types
- [Task] Configure rooms
- [Task] Configure amenities and status rules

#### [Feature] Rate setup
**Parent:** [Epic] Property setup and configuration  
**Description:** Configure pricing and restrictions.
- [Task] Configure rate plans
- [Task] Configure seasonal pricing
- [Task] Configure occupancy rules and restrictions

### [Epic] Reservation management
**Labels:** `epic`, `backend`, `frontend`  
**Description:** Build reservation lifecycle handling, booking types, and availability management.  
**Acceptance criteria:**
- Reservation CRUD flows are defined
- Multiple booking types are supported
- Availability and assignment rules are defined

#### [Feature] Booking operations
**Parent:** [Epic] Reservation management  
**Description:** Manage the reservation lifecycle.
- [Task] Create reservation flow
- [Task] Edit reservation flow
- [Task] Cancel reservation flow
- [Task] Handle no-show and waitlist

#### [Feature] Booking types
**Parent:** [Epic] Reservation management  
**Description:** Support multiple booking sources and reservation types.
- [Task] Support walk-in bookings
- [Task] Support direct bookings
- [Task] Support group bookings
- [Task] Track source channels

#### [Feature] Availability views
**Parent:** [Epic] Reservation management  
**Description:** Manage room availability and allocation.
- [Task] Build room availability calendar
- [Task] Build room assignment workflow
- [Task] Prevent overbooking conflicts

### [Epic] Front desk operations
**Labels:** `epic`, `backend`, `frontend`  
**Description:** Build check-in, in-stay, and checkout workflows.  
**Acceptance criteria:**
- Check-in is supported
- In-stay operations are supported
- Checkout and invoice generation are supported

#### [Feature] Check-in workflow
**Parent:** [Epic] Front desk operations  
**Description:** Support guest arrival and room allocation.
- [Task] Assign room at check-in
- [Task] Capture guest verification details
- [Task] Handle early check-in and upgrades

#### [Feature] In-stay operations
**Parent:** [Epic] Front desk operations  
**Description:** Support changes during a stay.
- [Task] Support room move
- [Task] Support stay extension
- [Task] Support add-on service posting

#### [Feature] Checkout workflow
**Parent:** [Epic] Front desk operations  
**Description:** Support departure and settlement.
- [Task] Review folio before checkout
- [Task] Collect payment at checkout
- [Task] Generate invoice and receipt

### [Epic] Folio, billing, and payments
**Labels:** `epic`, `backend`, `database`  
**Description:** Build folio operations, payment processing, and reconciliation controls.  
**Acceptance criteria:**
- Charges and folios can be managed
- Payments and refunds can be tracked
- Reconciliation controls are defined

#### [Feature] Folio operations
**Parent:** [Epic] Folio, billing, and payments  
**Description:** Manage folio posting and billing items.
- [Task] Post room charges
- [Task] Post extra service charges
- [Task] Apply taxes and discounts
- [Task] Split folios

#### [Feature] Payment handling
**Parent:** [Epic] Folio, billing, and payments  
**Description:** Record and track payments.
- [Task] Record cash payments
- [Task] Record card payments
- [Task] Record deposits and refunds
- [Task] Track payment status

#### [Feature] Financial control
**Parent:** [Epic] Folio, billing, and payments  
**Description:** Add financial controls and reconciliation.
- [Task] Implement void and reversal flow
- [Task] Implement approval for sensitive adjustments
- [Task] Build end-of-day reconciliation

### [Epic] Housekeeping and maintenance
**Labels:** `epic`, `backend`, `frontend`  
**Description:** Build room cleaning, maintenance, and operational room status coordination.  
**Acceptance criteria:**
- Housekeeping states are tracked
- Maintenance workflows are supported
- Front desk and housekeeping room status stay synchronized

#### [Feature] Housekeeping management
**Parent:** [Epic] Housekeeping and maintenance  
**Description:** Manage room cleaning and readiness.
- [Task] Track dirty, clean, inspected states
- [Task] Assign housekeeping tasks
- [Task] Record task completion

#### [Feature] Maintenance management
**Parent:** [Epic] Housekeeping and maintenance  
**Description:** Manage room maintenance workflows.
- [Task] Create maintenance requests
- [Task] Mark rooms out of order
- [Task] Restore rooms to service

#### [Feature] Operational coordination
**Parent:** [Epic] Housekeeping and maintenance  
**Description:** Keep room status aligned across teams.
- [Task] Sync front desk and housekeeping room status
- [Task] Surface ready-room visibility

### [Epic] Reporting and dashboards
**Labels:** `epic`, `reporting`  
**Description:** Build operational dashboards and revenue reporting.  
**Acceptance criteria:**
- Operational dashboards exist
- Revenue reports exist
- Export options exist

#### [Feature] Operational dashboards
**Parent:** [Epic] Reporting and dashboards  
**Description:** Show real-time operational hotel metrics.
- [Task] Show arrivals and departures
- [Task] Show in-house guests
- [Task] Show room status overview

#### [Feature] Revenue reporting
**Parent:** [Epic] Reporting and dashboards  
**Description:** Support commercial and finance reporting.
- [Task] Build occupancy report
- [Task] Build ADR and RevPAR report
- [Task] Build revenue by source report
- [Task] Build payment summary report

#### [Feature] Export and sharing
**Parent:** [Epic] Reporting and dashboards  
**Description:** Support report export options.
- [Task] Export CSV reports
- [Task] Export PDF reports

### [Epic] Notifications and integrations
**Labels:** `epic`, `integration`  
**Description:** Add guest notifications and external/internal integrations.  
**Acceptance criteria:**
- Guest communications are supported
- Payment and OTA integration scope is defined
- API and webhook contracts are defined

#### [Feature] Guest communication
**Parent:** [Epic] Notifications and integrations  
**Description:** Support outbound guest messaging.
- [Task] Send booking confirmation
- [Task] Send pre-arrival reminder
- [Task] Send checkout invoice

#### [Feature] Payments and external systems
**Parent:** [Epic] Notifications and integrations  
**Description:** Integrate with third-party operational systems.
- [Task] Integrate payment gateway
- [Task] Define accounting export
- [Task] Define OTA/channel manager integration

#### [Feature] Internal integration model
**Parent:** [Epic] Notifications and integrations  
**Description:** Define internal system contracts.
- [Task] Define API contracts
- [Task] Define webhook events

### [Epic] Security, audit, and hardening
**Labels:** `epic`, `security`  
**Description:** Harden the system for security, traceability, and operational reliability.  
**Acceptance criteria:**
- PII protections are defined
- Audit logging is defined
- Reliability standards are defined

#### [Feature] Security baseline
**Parent:** [Epic] Security, audit, and hardening  
**Description:** Protect sensitive data and enforce secure defaults.
- [Task] Protect guest PII
- [Task] Enforce least-privilege access
- [Task] Add secure input validation

#### [Feature] Auditability
**Parent:** [Epic] Security, audit, and hardening  
**Description:** Make sensitive actions traceable.
- [Task] Log reservation changes
- [Task] Log folio and payment actions
- [Task] Log admin configuration changes

#### [Feature] Reliability
**Parent:** [Epic] Security, audit, and hardening  
**Description:** Define operational resilience standards.
- [Task] Define backup and restore approach
- [Task] Define monitoring and alerting
- [Task] Define error handling standards
