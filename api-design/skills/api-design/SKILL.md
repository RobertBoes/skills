---
name: api-design
description: Design and review HTTP/REST APIs — modelling resources and endpoints, choosing status codes, error response bodies (RFC 9457 problem details), loading related data without n+1, pagination, and versioning. Use when adding or changing an API endpoint, designing a response shape, deciding what to return on failure, or reviewing an API for consistency.
---

# API design

For HTTP APIs consumed by other people's code — a mobile app, a SPA, a partner
integration. The consumer can't be refactored alongside you, which is what makes
these decisions expensive to get wrong.

## First: match the project's existing conventions

**An API's most valuable property is being predictable.** A single endpoint that is
"better designed" than its neighbours makes the API worse, because every consumer now
has a special case. Before designing anything, read what the API already does:

1. **Response envelope** — bare object, `{"data": …}`, JSON:API, something bespoke?
2. **Error shape** — what does a validation failure actually return today? Fetch or
   grep a real 4xx response rather than assuming.
3. **Naming** — `snake_case` or `camelCase`; plural or singular collections; how
   nesting is expressed.
4. **Pagination** — offset/limit, page number, cursor? Where do the metadata and
   links live?
5. **Versioning** — URL segment, header, or none at all?
6. **Auth** — bearer token, session, signed request; how failures are reported.

Look in routes files, API resource/serializer classes, existing tests (often the
clearest statement of the response contract), and any OpenAPI document or published
docs.

**Then match it.** If the existing convention is bad, say so and propose changing it
*everywhere* as its own piece of work — don't improve one endpoint in isolation. The
rules below are for greenfield APIs and for judging whether an existing convention is
sound.

If the project follows a published spec — **[JSON:API](https://jsonapi.org)**,
**[RFC 9457](https://www.rfc-editor.org/rfc/rfc9457.html)**, OpenAPI — that spec is
the authority, not this skill. Link to it rather than paraphrasing, and check the
current version.

## Resources and endpoints

**Model nouns, not procedures.** An endpoint names a thing; the HTTP verb says what
you're doing to it. `POST /getOrdersByCustomer` is RPC wearing a REST costume.

**Plan by listing actions per resource**, before writing any routes:

```
Orders      create, read, update, cancel, list (by customer, by status, by date range)
Customers   create, read, update, list
Refunds     create, read, list
```

Then work out which need endpoints. **Not every action is an endpoint.** Most
"actions" are a state change on an existing resource, expressible as an update.
Filters and searches are query parameters on the list endpoint, not new endpoints —
`GET /orders?customer=42&status=open`, never `GET /orders/byCustomerAndStatus`.

**When an action genuinely isn't CRUD**, prefer a sub-resource that names the thing
being created over a verb on the parent: `POST /orders/42/refunds` rather than
`POST /orders/42/refund`. The refund is a real resource with its own identity, and
you'll want to read and list them later.

**Sub-resources express containment**, and stop at one level. `/orders/42/lines` is
fine; `/customers/7/orders/42/lines/3` is a URL nobody can construct reliably. Once a
resource has its own id, give it a top-level route.

## Status codes

Use a small working set, deliberately. Exhaustiveness wins nothing.

| Code | Use for |
|---|---|
| `200` | Success with a body |
| `201` | Created — include a `Location` header pointing at the new resource |
| `202` | Accepted for async processing; says nothing about the outcome |
| `204` | Success, no body (a delete, often an update) |
| `400` | Malformed request — unparseable body, missing required parameter |
| `401` | Not authenticated (no or invalid credentials) |
| `403` | Authenticated but not permitted |
| `404` | No such route, or no such resource |
| `405` | Wrong verb for this route (usually the framework's job) |
| `409` | Conflict with current state — duplicate, or an illegal transition |
| `410` | Deliberately removed and not coming back |
| `422` | Well-formed but semantically invalid — the usual validation failure |
| `429` | Rate limited — include `Retry-After` |
| `500` | Unhandled fault on your side |
| `503` | Temporarily unavailable — include `Retry-After` if you can |

**400 vs 422** is the one people get wrong most: 400 means you couldn't parse it; 422
means you parsed it fine and the *values* are wrong. Most validation is 422. (Some
projects use 400 for both; if that's the existing convention, match it.)

**404 vs 403 vs 410** carry different meanings and shouldn't be collapsed into 404 by
default. But note the security tradeoff the older literature ignores: distinguishing
403 from 404 confirms that a resource exists to someone not allowed to see it. For
anything sensitive, return 404 for "exists but forbidden" deliberately, and say in a
comment that it's deliberate.

## Errors

**Status code and error identifier are two different layers.** The status code
categorises; a stable, documented identifier says which of several conditions inside
that category occurred. One endpoint can return 403 for half a dozen distinct
reasons, and the status alone can't tell a client which — so it can't offer the user
a useful next step.

**Never make the human-readable message the contract.** If clients can only
distinguish errors by string-matching `"Session has expired"`, any copy edit breaks
them.

**Use RFC 9457 problem details** unless the project already has a different format.
Media type `application/problem+json`, with five standard members:

| Member | Meaning |
|---|---|
| `type` | URI identifying the problem *category*. This is the stable identifier clients branch on. Defaults to `about:blank`. Use absolute URIs. |
| `title` | Short human-readable summary of the type. Constant across occurrences. |
| `status` | The HTTP status, duplicated for convenience. Must match the real one. |
| `detail` | Explanation of *this* occurrence, aimed at helping resolve it — not a stack trace. |
| `instance` | URI identifying this specific occurrence; useful as a support/correlation id. |

```json
{
  "type": "https://api.example.com/problems/insufficient-funds",
  "title": "Insufficient funds",
  "status": 422,
  "detail": "Order 42 requires 120.00 EUR; the account balance is 45.00 EUR.",
  "instance": "/orders/42/refunds/attempt-881f2",
  "balance": "45.00"
}
```

Extension members (`balance` above) are allowed, and consumers must ignore ones they
don't recognise — which is what lets problem types evolve without breaking clients.

RFC 9457 recommends reporting **the single most relevant problem** rather than
batching unrelated ones. Validation is the standard exception: use an extension
member holding per-field errors, and keep the shape identical across every endpoint.

More detail, including field-level validation shapes, in `references/errors.md`.

**Antipatterns**

- **`200 OK` with an error in the body.** Every client must now inspect the body to
  know whether the call worked, and generic HTTP tooling can't help them.
- **Undocumented error identifiers.** A `type` URI clients can't look up is a magic
  string.
- **Leaking internals.** Stack traces, SQL, and file paths in `detail` are an
  information disclosure bug, not a debugging convenience. Log them; return an
  `instance` id that lets support correlate.
- **A different error shape per endpoint.** Consumers write one error handler. Give
  them one shape.

## Loading related data

The core tension: too many round trips, or too much payload. Four strategies, each
with a real cost — pick per relationship, not once for the whole API.

**1. Sub-resources** — `GET /orders/42/lines`. Simple and cacheable, but n+1. For a
list of 50 orders each needing 4 related collections, that's 1 + (50 × 4) = **201
requests**. Fine for occasional drill-down, ruinous for list views.

**2. Foreign key arrays** — return `"line_ids": [1, 2, 3]`. Still n+1 in principle,
but the client can batch: `GET /lines?ids=1,2,3`. Drops the example above to 1 + 4 =
**5 requests**. Cost: the client reassembles the graph.

**3. Side-loading (compound documents)** — related resources in a flat top-level
collection alongside the primary data. Deduplicates: 50 orders from 3 customers embed
3 customer objects, not 50. Cost: the client still stitches by id. This is JSON:API's
`included`.

**4. Embedding (nesting)** — the related object inline inside each resource. Easiest
to consume, no stitching. Cost: duplication across the collection.

**Whichever you choose, make it opt-in**: `GET /orders?include=customer,lines`.
Serving everything by default forces every consumer to pay for the heaviest use case;
serving nothing forces round trips on all of them. An explicit allowlist of includable
relationships also keeps the query cost bounded — and remember to eager-load what was
requested, or you've moved the n+1 from HTTP into the database.

Worked comparison in `references/relationships.md`.

## Pagination

**Always paginate collections.** An endpoint that returns everything works until the
table grows, then fails in production.

- **Offset/limit or page number** — simple, allows jumping to a page. Degrades on
  large offsets and can skip or repeat rows when data changes mid-traversal.
- **Cursor/keyset** — stable under concurrent writes and fast at depth. No page
  jumping. The right default for feeds, exports, and anything large.

Return the metadata clients need — at minimum a next-page indicator; total counts are
nice but can be expensive, so make them optional rather than always paying for a
`COUNT(*)`. Enforce a maximum page size; treat `?limit=100000` as a request to be
capped, not obeyed. Keep the pagination shape identical across every collection.

## Versioning

**Prefer not to version.** Most changes can be additive: new optional fields, new
endpoints. Consumers must tolerate unknown fields — say so in your docs.

Breaking changes are removing or renaming a field, changing a type, tightening
validation, or changing the meaning of an existing value. When you must:

- **URL path** (`/v2/orders`) — ugly to purists, unambiguous in logs, caches, and bug
  reports. The pragmatic default.
- **Header/media-type negotiation** — cleaner URLs, but harder to test by hand and
  easy for proxies to mishandle.

Whichever you pick: never break within a version, announce deprecation with dates,
and use `410 Gone` for versions actually withdrawn. Two live versions is manageable;
five is a maintenance burden that will outlive your interest in it.

## Reviewing an API

1. Does this endpoint match the conventions of its neighbours?
2. Is it a noun with a verb, or a procedure in disguise?
3. Are the status codes right — especially 400 vs 422, and 403 vs 404?
4. Is the error body the same shape as everywhere else, with a stable machine-readable
   identifier?
5. Does anything leak internals into a response body?
6. Is related data opt-in, and eager-loaded when opted into?
7. Is the collection paginated with a capped page size?
8. Is every change additive, or does it break an existing consumer?
