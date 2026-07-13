# Ancillary folio charges

Ancillary charge types are configured per property and used to post food and beverage, room-service, minibar, spa, and other add-on charges during an active stay.

## Charge type configuration

- `GET /api/v1/properties/{propertyId}/ancillary-charge-types`
- `POST /api/v1/properties/{propertyId}/ancillary-charge-types`
- `PUT /api/v1/properties/{propertyId}/ancillary-charge-types/{chargeTypeId}`

Each type has a property-unique `code`, display `name`, percentage `tax_rate`, and `is_active` flag. Reading requires `folios.read`; creating or changing property configuration requires `properties.write`. Inactive types remain available for historical ledger references but cannot receive new postings.

## Posting charges

`POST /api/v1/bookings/{bookingId}/folio/ancillary-charges` accepts an active `charge_type_id`, a positive `amount`, and an optional `description`. The reservation must be checked in and its folio must be open.

The service posts an immutable `ancillary_charge` entry immediately with the actor and timestamp. It snapshots the charge type and tax rate, then posts a related `ancillary_tax` entry when the calculated tax is non-zero. Tax is rounded to the nearest cent using half-up rounding. Both entries commit in one transaction, and the response includes the resulting folio balance.

## Adjustments and voids

`POST /api/v1/bookings/{bookingId}/folio/ancillary-charges/{lineItemId}/adjustments` requires the dedicated `folios.adjust` permission. Seeded Admin, Manager, and Accountant roles have this permission; Receptionist does not.

An `adjustment` action accepts a signed non-zero amount and reason. It appends an `ancillary_adjustment` plus the corresponding signed tax adjustment. A `void` action appends one reversal for the complete charge family, including its tax and earlier adjustments. Original entries are never updated or deleted, the actor and reason are retained, and a charge can be voided only once.
