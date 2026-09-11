# Tappable scopes

Verified against Laravel 13.x on 2026-09-11. `tap()` is not covered by the Eloquent
documentation; everything here is read off the framework source or worked out in
practice. Confirm against the installed version before relying on a detail.

## The mechanism

`tap()` lives on `Illuminate\Database\Concerns\BuildsQueries`:

```php
public function tap($callback)
{
    $callback($this);

    return $this;
}
```

Three consequences worth knowing:

1. **The return value is discarded.** A scope that returns `$builder` is not wrong,
   but the fluency is coming from `tap()`, not from the scope. Return `void` so the
   contract is honest.
2. **Any callable works** — an invokable object, a closure, a first-class callable.
   Nothing has to be registered anywhere.
3. **`pipe()` is the sibling** for when you *do* want the callback's value:
   `return $callback($this) ?? $this`. A "scope" that terminates the query and returns
   a `Collection` or a count belongs in `pipe()`, not `tap()` — but that is a query
   object wearing a scope costume; prefer naming it as one.

## Why the contract type-hint

```php
use Illuminate\Contracts\Database\Query\Builder;
```

All three of these implement that contract, directly or through
`Illuminate\Contracts\Database\Eloquent\Builder`, which extends it:

- `Illuminate\Database\Query\Builder`
- `Illuminate\Database\Eloquent\Builder`
- `Illuminate\Database\Eloquent\Relations\Relation` (so `HasMany`, `MorphMany`, …)

Type-hinting the concrete `Illuminate\Database\Eloquent\Builder` narrows the scope to
Eloquent queries for no benefit and breaks the useful cases below. Type-hint the
contract.

One caveat: the contract is narrower than the concrete class. Anything the concrete
builder adds and the interface does not declare will fail static analysis under the
contract hint. Where a scope genuinely needs an Eloquent-only method, hint the
concrete class deliberately and accept the narrower reuse.

## Parameters

Constructor, not method arguments:

```php
final readonly class OfType
{
    /** @var list<NotificationType> */
    private array $types;

    public function __construct(NotificationType ...$types)
    {
        $this->types = $types;
    }

    public function __invoke(Builder $builder): void
    {
        $builder->whereIn('data->type', $this->types);
    }
}
```

```php
Notification::query()->tap(new OfType(Type::Mention, Type::Reply))->get();
```

Keep the constructor honest about types — an enum or a value object rather than a
bare string is the same argument `refactoring` makes about primitive obsession, and
it is cheaper here because the class already exists.

## Where they compose

**Inside a relationship constraint.** The callback receives a relation, which
satisfies the contract — but `whereHas` type-hints `?Closure`, so the object has to be
turned into one. First-class callable syntax does it without a wrapper:

```php
Company::query()
    ->whereHas('members', (new Orphan())(...))
    ->get();
```

**Inside an eager load**, where a plain object *is* accepted — `with()` funnels every
constraint through `combineConstraints()`, which wraps it in a closure:

```php
Company::with(['members' => new Orphan()])->get();
```

That wrapper reads `$builder = $constraint($builder) ?? $builder;` — an eager-load
constraint that returns a non-null value **replaces the builder** for every constraint
after it. One more reason the pinned shape returns `void`.

**Inside a query object's variant methods**, so the concept has exactly one
definition:

```php
public function unread(): self
{
    return $this->tap(new Unread());
}
```

**Conditionally**, without breaking the chain:

```php
$query->when($request->boolean('orphaned'), new Orphan());
```

`when()` takes a `?callable`, so the object needs no conversion. It passes
`($query, $value)`; an `__invoke(Builder $builder)` signature simply ignores the extra
argument, which is legal for a userland function in PHP. Note that `when()` — unlike
`tap()` — *does* consume the return value (`$callback($this, $value) ?? $this`), so a
scope returning something other than the builder would hijack the chain.

## Naming

Name the concept, not the mechanism:

| Good | Avoid |
|---|---|
| `Orphan` | `OrphanScope`, `ApplyOrphanFilter` |
| `Unverified` | `WhereVerifiedAtIsNull` |
| `IsRoot` | `RootNodeQueryModifier` |

If the best name you can find restates the SQL, the constraint is not a concept yet —
leave it inline. The test is whether someone in the business would recognise the word.

Where to put them is a project decision; `app/Models/Scopes`, `app/Queries/Scopes` and
a per-domain-module folder are all defensible. Follow whatever is already there.

## Testing

The point of the class is that it is testable without the model:

```php
it('excludes companies that have an owner', function () {
    $sql = Company::query()->tap(new Orphan())->toRawSql();

    expect($sql)->toContain('"user_id" is null');
});
```

Asserting on SQL is brittle; asserting on results is better where a factory is cheap:

```php
Company::factory()->create(['user_id' => null]);
Company::factory()->create(['user_id' => User::factory()]);

expect(Company::query()->tap(new Orphan())->count())->toBe(1);
```

Test the scope once, in one place, instead of re-asserting the same condition in every
feature test that happens to use it.

## Limits

- **A scope is only reusable across models whose table has the column.** "Orphaned"
  crossing `Company` and `Member` is a statement about the schema and the domain. Ask
  before assuming; a missing column is a runtime error, not a type error.
- **No IDE completion at the call site.** `->tap(new ` gives you class-name completion,
  not a list of available scopes for that model. That is the trade for reuse.
- **They don't compose *with each other*.** Two scopes applied to one builder are two
  independent mutations; there is no `Orphan()->andUnverified()`. If you need
  or-groups or negation, write the `where` closure or model it explicitly — don't
  build a scope-combinator library.
- **Global scopes are still global.** A tappable scope is opt-in per query; if a
  constraint must always apply, it is a global scope, not this.
