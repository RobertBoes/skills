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

Hunting N+1s by reading code does not scale. Make them loud instead.

The usual advice is `Model::preventLazyLoading(! app()->isProduction())`. Don't do
that — it gives up the signal exactly where the cost is real. Keep the check on
everywhere and decide what a violation *does*:

```php
// AppServiceProvider::boot()
use Illuminate\Database\LazyLoadingViolationException;

Model::preventLazyLoading();

Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
    $violation = new LazyLoadingViolationException($model, $relation);

    if (app()->isProduction()) {
        report($violation);   // Sentry, or whatever the app reports to

        return;
    }

    throw $violation;
});
```

Failing tests in development, reported exceptions in production, and the page still
renders — a handler that returns instead of throwing lets the lazy load go ahead, which
is the point. The suite never covers every page, and the paths it misses are the ones running
against real data volumes: a relation lazy-loaded over ten fixture rows is invisible, the
same code over ten thousand is the problem you were looking for. Those are precisely the
ones the usual advice silences.

Note the namespace: `Illuminate\Database\LazyLoadingViolationException`, not
`Illuminate\Database\Eloquent\`. Since Laravel 13.32 the handler receives three
arguments — the model, the relation name and a pre-built `LazyLoadingViolationException`.
Earlier versions pass only the first two, and a closure that declares the third will fail
with an `ArgumentCountError` there. The snippet above declares two and builds the
exception itself, which works on both; on 13.32+ you can take the third argument instead.
Check the installed framework version before relying on it.

Registering a handler **replaces** the default behaviour entirely, including its guard
— by default a violation on a model that doesn't exist yet, or was just created, is
ignored. A handler that throws unconditionally will fire on those too. Re-add the guard
if that noise is not useful:

```php
if (! $model->exists || $model->wasRecentlyCreated) {
    return;
}
```

Three caveats worth knowing before you trust it:

- **It only fires on code that executes.** An N+1 inside a loop that never runs —
  because the fixture collection is empty — is not detected. Empty-collection tests
  give false confidence here.
- **It does not catch every lazy load.** `Builder::hydrate` arms the check only on
  models hydrated from a query that returned **more than one row** (`count($items) > 1`)
  — a lazy load on a single model is not an N+1, so it is deliberately allowed. A test
  written with a one-row fixture therefore passes regardless of what the handler does,
  or whether one is registered at all. **Use two rows.**
- **Some of the inventory may be blocked.** The violations are the inventory you could
  not have produced by reading code — but not all of it is actionable. In one real
  case, 10 of 26 violations could not be eager-loaded at all until a column type was
  migrated, which took three sequenced deploys. Finding a blocked violation is itself a
  result: record it with what unblocks it, and don't let a partial fix read as a failed
  one.

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

## Related

This file covers whether a query is *fast*. Where its constraints should *live* —
inline, an attribute scope, a tappable scope, a query object, or a global scope
registered at a boundary — is the `eloquent-queries` skill.
