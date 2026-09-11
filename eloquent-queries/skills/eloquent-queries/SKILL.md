---
name: eloquent-queries
description: Decide where Eloquent query logic lives — inline constraints, attribute scopes, tappable (invokable) scopes, query objects, global scopes applied outside the model, and shared eager-load constraints. Use when a where clause is about to be written in a controller, job, action or command, when the same constraint appears in more than one query, when asked to extract or reuse a scope, or when reviewing a query for placement rather than performance.
---

# Eloquent queries

This skill answers one question: **where does this query constraint belong?** Not
whether the query is fast — for N+1s, eager loading and index problems see
`laravel-audit`'s `references/query-performance.md`. Not how the code reads locally —
see `readable-code`.

The failure this prevents is small and constant: a `where('user_id', null)` written
inline in a job, repeated three months later in a controller with `whereNull` instead,
and a third time in a command with the column renamed. Nothing breaks loudly. The
domain language just never makes it into the code.

## When to apply

- **Writing a query** — the triggers below fire as you type. The cheapest moment to
  place a constraint correctly is before it has a second caller.
- **Finishing a change** — re-read the queries you just wrote. A constraint that now
  appears twice in the diff is the trigger for extraction.
- **Reviewing** — a `where` in a controller, job, action or command is the prompt to
  ask whether it names something the domain already has a word for.

**It is a standard, not a task.** Using it once in a session does not discharge it.

### When you catch yourself thinking…

| Thought | Reality |
|---|---|
| "It's one `where`, inline is fine" | It is, until it isn't. One caller: leave it. See the table below. |
| "I'll extract it when it's duplicated" | Correct. This skill fires *at* that moment — don't skip it then. |
| "This project uses `#[Scope]`, but tappable is better" | The project wins. Conventions first, always. |
| "It's a quick query in a command" | Commands and jobs are where unnamed constraints accumulate worst. |
| "I already used this skill this session" | It's a standard, not a task. |

## First: read the project's conventions

Before introducing any shape, find out what already exists:

1. **How are reusable constraints expressed today?** Grep for `#[Scope]`, `scope` on
   models, `Builder` subclasses (`newEloquentBuilder`), invokable scope classes, or
   macros. Whatever is already there is the default.
2. **Where do scope classes live?** `app/Models/Scopes`, `app/Queries`, a domain
   module — follow it.
3. **Is there a project-level skill or convention doc?** (`.claude/skills/`,
   `.ai/skills/`, `CLAUDE.md`.) Where one exists it outranks everything here.
4. **Are there global scopes already?** Know them before adding a query, or you will
   debug a `ModelNotFoundException` that is doing exactly what it was told.

**On conflict, the project wins.** A codebase consistently using `#[Scope]` methods
should not have tappable scopes bolted onto it because this skill prefers them; a
consistent mediocre convention beats an inconsistent good one. Introduce a new shape
only when adding the *first* reusable constraint, or when you are converting
deliberately and completely.

## Where a constraint belongs

| The constraint… | Put it… |
|---|---|
| is used once, and the columns are obvious | **inline** in the query |
| is reused, and belongs to exactly one model | **`#[Scope]` method** on that model |
| is reused across models, or names a domain concept | **tappable scope** (invokable class) |
| ships with a package, for consumers' models | **tappable scope** — no name collisions |
| is a whole named query with several variants | **query object** |
| must apply to every query in a bounded context | **global scope, registered at the boundary** |
| refines an eager load, in more than one place | **invokable eager-load constraint** |

Two rules bound the table:

- **Don't extract a constraint with one caller.** Same reasoning as `refactoring`'s
  *defer until necessary* — you cannot see the right shape yet. A named class per
  `where` is its own mess.
- **Don't extract what isn't a concept.** `Orphan` and `Unverified` earn classes
  because the domain says those words. `WhereIdGreaterThanFive` does not.

## Tappable scopes

`tap()` on the builder takes any callable, hands it the builder, and returns the
builder. So an invokable class is a scope, with no trait, no prefix, and no
registration:

```php
use Illuminate\Contracts\Database\Query\Builder;

final readonly class Orphan
{
    public function __invoke(Builder $builder): void
    {
        $builder->whereNull('user_id');
    }
}
```

```php
return Company::query()
    ->oldest()
    ->tap(new Orphan())
    ->tap(new Unverified())
    ->get();
```

The same `Orphan` then applies to `Member::query()` — the thing an `#[Scope]` method
on `Company` cannot do.

### The shape, pinned

Pick one shape and keep it. This one:

- **`final readonly class`**, named for the concept (`Orphan`, `Unverified`,
  `IsRoot`), not for the mechanism (`OrphanScope`, `ApplyOrphanFilter`).
- **`__invoke`**, returning `void`. `tap()` ignores the return value; returning
  `$builder` implies a fluent contract that isn't there.
- **Type-hint `Illuminate\Contracts\Database\Query\Builder`**, not the Eloquent
  builder — the contract also covers query and relation builders, which is what makes
  the same class usable inside a `whereHas` or an eager-load closure.
- **Parameters go in the constructor**: `new OfType($type)`.
- **No interface and no base class.** `tap()` takes any callable; an interface adds a
  file and buys nothing at runtime. (A marker interface is defensible purely for
  static analysis or discovery — decide once, project-wide, and then be consistent.)
- **No `filter()`/`scopes()` macro.** Passing variadic scopes through a registered
  macro reintroduces exactly the opaque runtime magic the pattern exists to avoid, and
  macros are the one shape here that can genuinely collide. Chained `tap()` calls are
  slightly longer and entirely explicit.

### Why this over a scope method

Be precise about the argument, because the usual version of it is out of date:

- **Reuse across models** — the real win. One concept, one class, any table that
  backs it.
- **No name collisions** — decisive for package authors, where a published
  `scopeIsRoot` occupies a name on every consuming model.
- **Click-through** — the class is a class; the IDE resolves it without a helper.

What is **not** a good argument any more: "scopes need an ugly `scopeXxx` prefix".
Current Laravel uses an attribute (see *Version notes* below). The dispatch is still
dynamic and the scope is still bound to one model, but the naming complaint is dead —
don't repeat it.

The honest cost: `Post::published()` reads better than `Post::query()->tap(new
Published())`. Tappable scopes trade a little surface elegance for reuse and
explicitness. **This is a preference, not a standard** — and it is not documented in
the Laravel docs. Say so when you introduce it, rather than presenting it as the
framework's recommendation.

Composition, parameters, testing and the interaction with relations:
`references/tappable-scopes.md`.

## Global scopes, applied at a boundary

A global scope does not have to be registered in the model's `booted()`. It can be
added anywhere — a service provider, middleware, a command — and applied to exactly
the surface that needs it:

```php
public function handle(Request $request, Closure $next): mixed
{
    $scope = new CountryRestriction($this->geo->get());

    Movie::addGlobalScope($scope);
    Review::addGlobalScope($scope);

    return $next($request);
}
```

Route the middleware onto the public site and not onto the admin panel, and the
restriction is unbypassable where it matters, absent where it would be wrong, and
invisible in Tinker. That is what makes global scopes tolerable: **the placement, not
the mechanism**. A scope registered in `booted()` applies to migrations, seeders,
queue workers and the REPL too — which is why they have the reputation they have.

Full treatment, including making one class serve as both a global and a tappable
scope: `references/global-scopes.md`.

## Query objects

When a single named query grows variants — "my notifications", read / unread / of
type — a class that owns the base query and exposes the variants beats repeating the
base in every controller:

```php
$notifications = GetMyNotifications::query($request->user())
    ->read()
    ->with('notifiable')
    ->get();
```

Composed with `ForwardsCalls` and a `/** @mixin \Illuminate\Database\Eloquent\Builder */`
annotation, the whole builder API stays available and the IDE keeps completing. The
variant methods can themselves be `tap()` calls onto the tappable scopes above.

Where to stop: a query object owns **one** query's variants. When it starts
accumulating unrelated queries for the model it has become a repository with extra
steps — that is a God object, and the reason custom `Builder` subclasses tend to rot.

## Shared eager-load constraints

A refined eager load is a constraint too, and duplicating the closure is the same
problem one level down:

```php
final readonly class LoadThumbnail implements Arrayable
{
    public function __invoke(MorphMany $query): void
    {
        $query->where('collection_name', 'thumbnail');
    }

    public function toArray(): array
    {
        return ['media' => $this];
    }
}
```

```php
Product::with([
    'categories',
    'media' => new LoadThumbnail(),
    'variant.media' => new LoadThumbnail(),
])->tap(new Available())->get();
```

Same rule as everywhere else here: extract on the second use, not the first.

## Where to stop

- **One caller, no class.** Inline is the correct answer far more often than not.
- **No class per `where`.** If the name would restate the SQL, it is not a concept.
- **Don't convert a project's existing scopes wholesale** because this skill prefers
  another shape. That is a separate, deliberate migration, and it is the project
  owner's call.
- **A tappable scope is not portable by magic.** It only applies to models whose table
  actually has the column. Reuse across models is a claim about the domain, not a
  free lunch.
- **Don't reach for a query object for a two-line query.** Variants justify it;
  a single `->get()` does not.
- **This is not a performance skill.** Placement doesn't fix an N+1.

## Version notes

Verified against **Laravel 13.x** on 2026-09-11. Confirm against the version actually
installed (`vendor/laravel/framework`) rather than trusting this summary:

- Local scopes are declared with the **`Illuminate\Database\Eloquent\Attributes\Scope`
  attribute** on a normally-named method — the `scopeXxx` prefix is legacy:

  ```php
  #[Scope]
  protected function popular(Builder $query): void
  {
      $query->where('votes', '>', 100);
  }
  ```

- `ScopedBy` attaches global scopes declaratively; the docs also cover Pending
  Attributes and Dynamic Scopes.
- **`tap()` is not mentioned in the Eloquent documentation** at 13.x. It is a builder
  method that works, not a documented pattern — which is precisely why this skill
  pins a shape.
