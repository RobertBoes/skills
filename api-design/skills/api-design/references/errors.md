# Error responses

The authority is [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457.html) (July 2023),
which obsoletes RFC 7807. The wire format and media type are unchanged from 7807, so
existing `application/problem+json` implementations remain valid.

## The two layers

| Layer | Answers | Audience |
|---|---|---|
| HTTP status | What category of thing went wrong | Generic HTTP tooling — proxies, caches, retry logic, monitoring |
| `type` URI | Which specific condition | Your client's branching logic |
| `title` / `detail` | What to tell a person | Humans reading logs or a UI |

A client that wants to do something intelligent — offer to top up a balance, prompt
for re-auth, show a specific field error — needs the middle layer. Status codes alone
can't provide it, because one status legitimately covers many conditions.

## Choosing `type` URIs

- **Stable forever.** This is the contract. Changing it is a breaking change.
- **Absolute URIs.** The spec discourages relative ones because resolution is
  ambiguous. `https://api.example.com/problems/insufficient-funds`.
- **They need not resolve**, but resolving them to documentation is a kindness and
  costs a static page.
- **Name the condition, not the location.** `insufficient-funds`, not
  `order-controller-error-3`.
- **Document every one you emit.** An identifier a client can't look up is a magic
  string with extra steps.

## Validation errors

RFC 9457 recommends reporting the single most relevant problem rather than batching
unrelated ones. Validation is the standard exception — clients genuinely need every
field at once, or users fix one error per round trip.

Use an extension member. Consumers must ignore extensions they don't recognise, so
this stays spec-compliant:

```json
{
  "type": "https://api.example.com/problems/validation-failed",
  "title": "Validation failed",
  "status": 422,
  "detail": "The request contains 2 invalid fields.",
  "errors": [
    { "field": "email",    "code": "format",   "detail": "Must be a valid email address." },
    { "field": "quantity", "code": "min",      "detail": "Must be at least 1." }
  ]
}
```

Give each entry a machine-readable `code` as well as a human `detail`, for the same
reason the top level has `type` — clients that want to render their own copy, or
localize, shouldn't parse English.

For nested request bodies, identify the field unambiguously. A JSON Pointer
(`/lines/0/quantity`) is precise and standard; dotted paths (`lines.0.quantity`) are
more familiar in some ecosystems. Either is fine — pick one and use it everywhere.

## Framework defaults

Most frameworks ship an error shape that isn't RFC 9457. Laravel, for example,
returns `422` with `{"message": …, "errors": {"field": ["..."]}}`.

**Match the project's existing shape by default.** A codebase where most endpoints
return the framework default and a few return problem details is worse than either
one used consistently. Adopting RFC 9457 is a project-wide decision — worth making,
but as its own piece of work, with the exception handler changed once and every
endpoint following.

If you do adopt it, do it in the framework's central exception handler so no endpoint
has to remember, and keep a mapping table from internal exceptions to `type` URIs.

## What not to put in a response

- Stack traces, SQL, file paths, framework internals. This is information disclosure,
  and attackers read error messages first.
- Whether an account exists, on login or password-reset errors.
- Whether a forbidden resource exists, when that itself is sensitive — return `404`
  deliberately and comment that it's deliberate.

Log the detail server-side, return `instance` as a correlation id, and let support
look it up. That gives you debuggability without leaking anything:

```json
{
  "type": "https://api.example.com/problems/internal-error",
  "title": "Internal error",
  "status": 500,
  "detail": "The request could not be completed. Quote this reference to support.",
  "instance": "urn:uuid:8f14e45f-ea8f-4b45-9c1e-6d3a2b1c0f77"
}
```

## Retries

Errors clients can act on should say so:

- `429` and `503` — include `Retry-After`.
- Distinguish retryable from permanent. A client that retries a `422` forever is
  usually the API's fault for not making the distinction visible.
- For non-idempotent writes, support an idempotency key so a retry after a timeout
  can't double-charge. Document how long keys are honoured.
