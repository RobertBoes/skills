# Query performance

## N+1 queries

Fetch 100 comments, then read `$comment->author->name` in a loop, and you have issued
101 queries: one for the comments, one per comment for its author. Each is fast, which
is why it survives review — the damage is the round trips.

```php
// 101 queries
$comments = Comment::all();

// 2 queries
$comments = Comment::with('author')->get();
```

Nested and conditional relations work the same way — `with('author.company')`, or
`with(['author' => fn ($q) => $q->select('id', 'name')])`.

### Let the framework find them

Hunting N+1s by reading code does not scale. Make them loud instead:

```php
// AppServiceProvider::boot()
Model::preventLazyLoading(! app()->isProduction());
```

Any lazy load now raises `LazyLoadingViolationException` in local, CI and staging,
while production keeps working. Enable it, run the test suite, and the violations
come to you.

Two caveats worth knowing before you trust it:

- **It only fires on code that executes.** An N+1 inside a loop that never runs —
  because the fixture collection is empty — is not detected. Empty-collection tests
  give false confidence here.
- **Turning it on in production is a judgment call.** It converts a slow page into a
  500. Defensible on a small team with good coverage; risky otherwise. The
  `! app()->isProduction()` form is the safe default.

Where it can't reach, a query-log assertion in a test is the fallback: assert that
rendering a page issues no more than N queries. Blunt, but it catches regressions the
violation exception can't see.

### Eager loading isn't always the fix

`with()` on a relation you only sometimes use trades N+1 for a wasted join. Two cases
to watch:

- **Conditional access** — if only some rows touch the relation, `load()` it later on
  the subset instead.
- **Counts** — `withCount('comments')` is much cheaper than loading every comment to
  call `count()` on it. Same for `exists()` over `count() > 0`.

## Fetch only what you need

```php
// every column, including large text bodies
$users = User::all();

// just what the page renders
$users = User::select('id', 'name', 'email')->get();
```

This matters most on wide tables and on rows with large text or JSON columns, and it
compounds with N+1 — an over-fetching query repeated 100 times is the worst case.

Two things to check when narrowing a select: include any column the model's accessors
or appended attributes depend on, and include the foreign keys the relationships need,
or you will reintroduce the N+1 you were trying to fix.

`chunk()`, `chunkById()`, `lazy()` or `cursor()` for anything that processes a large
result set — loading a whole table into memory is a different flavour of the same
problem, and it fails at the worst time, on the largest customer.

## Caching

In rough order of return on effort:

- **Framework caches in deployment** — `config:cache`, `route:cache`, `event:cache`,
  `view:cache`. Cheap and mechanical. Note that once config is cached, `env()` returns
  null outside config files, which is why application code must use `config()`. A
  codebase calling `env()` in a service is a finding in its own right.
- **Query and value caching** — `Cache::remember()` around genuinely expensive, rarely
  changing work. The hard part is never the caching, it's the invalidation: anything
  cached needs a clear answer to "what makes this stale, and what clears it".
- **Don't cache to hide a missing index.** If a query is slow because it scans, cache
  it and you have hidden the problem behind a TTL. Check `EXPLAIN` before reaching for
  a cache.

## Reporting performance findings

Measure before you report. "This looks slow" is not a finding; "this page issues 340
queries, 300 of them identical" is. Laravel's query log, Telescope, Debugbar or an
APM will give you the number, and the number is what gets it prioritised.
