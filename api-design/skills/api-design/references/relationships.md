# Loading related data

Every strategy trades round trips against payload size. There is no winner — pick per
relationship, based on how the data is actually used.

## The scenario

An orders API. A list view shows 50 orders, each needing its customer, line items,
shipping address, and payment method. Four related resources per order.

## 1. Sub-resources

```
GET /orders
GET /orders/42/lines
GET /orders/42/customer
```

Each relationship gets its own endpoint.

**For:** simple, independently cacheable, permissions are easy to reason about, and
the payload of any one call stays small.

**Against:** n+1. The list view costs `1 + (50 × 4) = 201` requests. Over mobile
networks that is the slowest thing the client can possibly do, and it's slow even
when the requests are parallel.

**Use when:** the related data is genuinely occasional — a drill-down the user
requests explicitly, not something every list row needs.

## 2. Foreign key arrays

```json
{
  "id": 42,
  "customer_id": 7,
  "line_ids": [11, 12, 13]
}
```

**For:** the client can batch — collect ids across all 50 orders and issue
`GET /lines?ids=11,12,13,…`. That's `1 + 4 = 5` requests, a 40× reduction. Response
stays small.

**Against:** the client reassembles the object graph. For a large or deeply related
dataset that's real work, and every client reimplements it.

**Use when:** consumers are sophisticated, or you're already shipping a client SDK
that can hide the stitching.

## 3. Side-loading (compound documents)

Related resources in a flat top-level collection beside the primary data:

```json
{
  "data": [
    { "id": 42, "customer_id": 7, "total": "120.00" },
    { "id": 43, "customer_id": 7, "total": "35.00" }
  ],
  "included": {
    "customers": [ { "id": 7, "name": "Acme Ltd" } ]
  }
}
```

**For:** deduplication. 50 orders placed by 3 customers carry 3 customer objects, not
50. One request. This is JSON:API's `included` member.

**Against:** the client still stitches by id, and deeply nested structures lose
context — it's not obvious from `included` alone which resource wanted what.

**Use when:** related resources repeat heavily across the collection. The saving
grows with the repetition.

## 4. Embedding (nesting)

```json
{
  "data": [
    {
      "id": 42,
      "total": "120.00",
      "customer": { "id": 7, "name": "Acme Ltd" },
      "lines": [ { "id": 11, "sku": "ABC", "quantity": 2 } ]
    }
  ]
}
```

**For:** easiest possible consumption. No stitching, no extra requests, structure
mirrors how the client thinks about the data.

**Against:** duplication. Those 3 customers now appear 50 times. Payload grows
quickly, and the same object appearing at multiple paths invites cache inconsistency
in the client.

**Use when:** the relationship is genuinely one-to-few and the related object is
small — line items on an order, an address on a shipment.

## Make it opt-in

Whichever you choose, let the consumer ask:

```
GET /orders?include=customer,lines
```

Serving all relationships by default makes every consumer pay for the heaviest use
case. Serving none forces round trips on all of them. Opt-in gives each client the
tradeoff it wants and is the single highest-value decision in this whole area.

Three rules that come with it:

1. **Allowlist what's includable.** An open-ended `include` is an unbounded query
   cost and a permissions hole — a consumer must not be able to reach data through an
   include that they couldn't request directly.
2. **Eager-load what was requested.** Otherwise you've moved the n+1 out of HTTP and
   into the database, which is harder to see and just as slow. Map the `include`
   parameter to the ORM's eager-load call.
3. **Cap include depth.** `include=customer.account.owner.organization` is a query
   nobody costed. One or two levels is almost always enough.

## Paginating nested collections

An order with 10,000 line items breaks embedding. Options, in order of preference:

1. **Don't embed unbounded collections.** Expose them as a sub-resource with its own
   pagination. A relationship that can grow without limit isn't an attribute.
2. **Embed a capped preview** plus a link and a total — `"lines": { "data": [first 10],
   "total": 10000, "link": "/orders/42/lines" }`. Good for "show the first few" UIs.
3. **Paginate the nested collection inline.** Possible, but the query parameters get
   confusing fast (`?include=lines&lines[page]=2`) and most consumers get it wrong.

The general rule: **if a relationship can be unbounded, it's a sub-resource, not an
embedded field.** Decide this from the data model, before the first consumer builds
against the wrong shape.
