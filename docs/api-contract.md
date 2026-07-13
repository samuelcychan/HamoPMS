# API contract

The public API baseline is version `v1`. Versioned resources use the `/api/v1` prefix. Breaking contract changes require a new URL version; additive fields may be introduced within `v1`.

The machine-readable Sprint 1 contract is published at [`docs/openapi/v1.yaml`](openapi/v1.yaml). CI validates it with the pinned Redocly CLI configuration in [`redocly.yaml`](../redocly.yaml).

Validate the contract locally with Node.js 22.12 or newer:

```bash
npx --yes @redocly/cli@2.38.0 lint hamo@v1 --config redocly.yaml
```

## Success responses

Successful responses use a `data` envelope:

```json
{
  "data": {
    "id": 1,
    "name": "Example"
  }
}
```

Creation returns `201`. Deletion returns `204` with no response body.

## Error responses

Errors use a stable machine-readable code and a human-readable message:

```json
{
  "error": {
    "code": "RESOURCE_NOT_FOUND",
    "message": "The requested resource was not found."
  }
}
```

Standard codes include `UNAUTHENTICATED`, `FORBIDDEN`, `RESOURCE_NOT_FOUND`, `METHOD_NOT_ALLOWED`, `CONFLICT`, `RATE_LIMIT_EXCEEDED`, `VALIDATION_FAILED`, and `HTTP_ERROR`.

Reservation creation returns `INVENTORY_UNAVAILABLE` with status `409` when an active reservation already occupies the property for any of the requested nights.

Validation errors include field messages under `error.details.fields`:

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The request contains invalid data.",
    "details": {
      "fields": {
        "email": ["The email field is required."]
      }
    }
  }
}
```

## Pagination and filtering

Collection endpoints accept `page` and `per_page` query parameters. `per_page` defaults to `15` and must be between `1` and `100`.

```json
{
  "data": [],
  "meta": {
    "pagination": {
      "current_page": 1,
      "per_page": 15,
      "total": 0,
      "last_page": 1
    }
  }
}
```

Filters are query parameters named after the resource field (for example, `status=confirmed`). Multiple filters are combined with AND semantics. Unsupported filters are ignored until an endpoint documents support for them.

Reservation modification semantics and folio adjustments are documented in [`docs/reservation-modifications.md`](reservation-modifications.md).

Checked-in room moves, extensions, and early departures are documented in [`docs/in-stay-modifications.md`](in-stay-modifications.md).

Cancellation windows, penalties, and non-refundable behavior are documented in [`docs/cancellations.md`](cancellations.md).
