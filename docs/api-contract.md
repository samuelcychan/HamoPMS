# API contract

The public API baseline is version `v1`. Versioned resources use the `/api/v1` prefix. Breaking contract changes require a new URL version; additive fields may be introduced within `v1`.

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
