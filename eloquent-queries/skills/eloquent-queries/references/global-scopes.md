# Global scopes, and where to register them

Verified against Laravel 13.x on 2026-09-11.

## The reputation, and what causes it

"Global scopes are bad, local scopes are good" is folklore with a real cause behind it.
The cause is not the mechanism — it is that the documented example registers the scope
in the model's `booted()` method, which means it applies to *everything*: web requests,
the admin panel, queue workers, seeders, migrations that touch models, scheduled
commands, and Tinker. A developer debugging a missing row in the REPL has no reason to
suspect the model itself is lying to them.

Nothing in the API requires that. `addGlobalScope` can be called anywhere, at any time,
and the scope applies from that point forward for the life of the process.

## Registering at a boundary

Make the *placement* carry the meaning. A middleware applies a scope to exactly the
routes it is attached to:

```php
final readonly class RestrictByCountry
{
    public const NAME = 'country.restrict';

    public function __construct(private GeoRepository $geo) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $scope = new CountryRestriction($this->geo->get());

        Movie::addGlobalScope($scope);
        Rating::addGlobalScope($scope);
        Review::addGlobalScope($scope);

        return $next($request);
    }
}
```

```php
Route::middleware(['web', RestrictByCountry::NAME])->group(function () {
    Route::resource('movies', Site\MovieController::class);
    // …public site
});

Route::middleware(['web', 'auth'])->prefix('admin')->group(function () {
    Route::resource('movies', Admin\MovieController::class);
    // …admin panel, unrestricted
});
```

What this buys:

- The restriction cannot be forgotten on a new public route added later — it is
  attached to the group, not to each query.
- The admin panel sees everything, without `withoutGlobalScope` calls scattered
  through it.
- Tinker, queue workers and seeders are unaffected, because nothing registered the
  scope in those processes.
- The blast radius is readable from the route file.

A service provider is the right place when the constraint really is process-wide (see
*Read models* below). A command, a job, or a single controller action are all legal
too — choose the narrowest scope that still cannot be bypassed by accident.

## One class, both ways

A `Scope` implementation can also be tappable, so a concept has a single definition
whether it is applied globally or per query:

```php
final readonly class FileScope implements Scope
{
    public function __invoke(Builder $builder): void
    {
        $this->apply($builder, File::make());
    }

    /** @param File $model */
    public function apply(Builder $builder, Model $model): void
    {
        $builder
            ->where($model->qualifyColumn('model_type'), 'directory')
            ->where($model->qualifyColumn('collection_name'), 'file');
    }
}
```

`apply()` satisfies the `Scope` contract for `addGlobalScope`; `__invoke()` makes it
usable with `tap()`. Note `qualifyColumn` — a global scope is joined into queries you
did not write, so qualify columns or risk an ambiguous column error.

## Read models over the same table

The one case where a genuinely process-wide global scope earns its place: a second
model pointed at an existing table, representing a narrower concept.

```php
final class File extends Model
{
    protected $table = 'media';
}
```

Registered in a service provider, `FileScope` above makes `File` incapable of seeing
anything that is not a directory's file — queries return nothing, `findOrFail` throws
`ModelNotFoundException`, and the narrowing is a property of the model rather than of
each caller. That is also what lets the parent declare a plain `hasMany(File::class)`
where the underlying table is polymorphic: the `model_type` condition is already
guaranteed.

This is a real technique with a real cost. Two models writing to one table is a
consistency hazard, and a scope that silently empties results is exactly the confusion
described at the top of this file. Reach for it when a UI or a package forces your
hand, keep the second model read-mostly, and name the scope after the concept so the
next person finds it.

## Rules

- **Register at the narrowest boundary that cannot be bypassed by accident.**
  Middleware for a route group, a provider only for genuinely process-wide truths.
- **Never register a request-dependent scope in `booted()`.** A scope that reads the
  current user or the current tenant in a model's boot method will eventually run in a
  queue worker where there is no request.
- **Qualify columns** in any scope that might meet a join.
- **Prefer `withoutGlobalScope(X::class)` over `withoutGlobalScopes()`** at the few
  places that must opt out — the broad version silently drops soft deletes too.
- **Write down which scopes exist and where they are registered.** A global scope is
  the one piece of query logic that is invisible at the call site; if it is not in the
  project's docs or a convention file, it is a trap.
