# Property context

Tenant-scoped API routes resolve exactly one property before authorization and controller execution. Clients may provide the context with `X-Property-ID`, a route `{propertyId}`, or a `property_id` request field. Routes for an existing booking, folio, or payment derive the property from that resource, so clients do not need to repeat it.

All supplied and derived property identifiers must agree. A missing context returns `PROPERTY_CONTEXT_REQUIRED`, while conflicting identifiers return `PROPERTY_CONTEXT_MISMATCH`. Unknown properties or resources use the standard not-found contract, and users without any global or matching property role receive the standard forbidden response.

Controllers can use `App\Support\PropertyContext::scope()` to constrain Eloquent queries to the resolved property. Property-scoped collection endpoints such as bookings and payments require a context and apply that scope before pagination.

Global administration routes and the property collection/create routes intentionally remain outside the middleware. Their permission checks require a global role assignment.
