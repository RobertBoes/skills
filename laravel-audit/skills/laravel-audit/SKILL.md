---
name: laravel-audit
description: Audit an existing Laravel codebase for security, correctness, structural and performance problems — missing authorisation, unvalidated input, raw queries, hard-coded credentials, exposed package routes, fake facades, logic in Blade, N+1 queries, dead code. Use when asked to audit, assess, or review a Laravel project for technical debt, when inheriting an unfamiliar codebase, or when planning where to spend cleanup effort.
---

# Laravel audit

Systematic review of an existing Laravel codebase. Not general code review — for how
individual code reads, see `readable-code`; for restructuring it, `refactoring`.

This skill is about **finding** problems and ranking them. It pairs with any skill
that turns findings into plans: run this to produce the findings, then hand them over.

## Before you start

**Assume constraints you can't see.** Code that looks careless is usually code
written under a deadline, by someone with less context than you have now, possibly
against an older PHP or Laravel version. The question is never "what were they
thinking" — it's "what does this cost now, and what would it cost to change".

**Don't nitpick.** An audit that returns eighty findings gets ignored; one that
returns the six that matter gets acted on. "I would have written this differently" is
not a finding. Something is a finding when it can cause a breach, corrupt data, break
under load, or measurably slow down future work.

**Rank as you go.** Security and data-integrity issues first, performance second,
structure last. Structural findings are real but they are never the thing to fix on
day one.

**Record enough to act on.** Each finding needs: file and line, what's wrong, what it
could cause, and roughly what fixing it involves. A finding nobody can act on without
re-doing your investigation is half a finding.

## Security and correctness

Highest priority. Work these first.

### Missing or incomplete authorisation

The most common serious finding. Look for routes and controller actions with **no
`authorize()`, policy, gate or `can` middleware** — especially ones where the UI is
doing the gatekeeping.

The dangerous pattern: a route left unprotected *because the interface never links to
it for the wrong user*. Hiding a button is not authorisation. Anyone can issue the
request.

Also check that authorisation is the *right* check — that "can view invoices" doesn't
silently mean "can view **all** invoices", and that an ownership check exists where
it should. Over-broad authorisation looks correct in a code review and leaks data in
production.

### Unvalidated or partially validated input

Every field you consume should be validated. Look for:

- Controllers reading `$request->all()`, `$request->input(...)` or `$request->merge(...)`
  where no Form Request or validator governs the fields
- Form Requests with empty or partial `rules()` — a `rules()` that covers three of the
  five fields being written
- Mass assignment reachable from request data — `Model::create($request->all())` with
  a permissive `$fillable`, or `$guarded = []`
- Validation that checks presence but not bounds or type

Then use `validated()` rather than `all()`, so only governed fields reach the model.

### Raw queries

Find every raw fragment and check whether user input reaches it:

```
rg 'whereRaw|selectRaw|orderByRaw|havingRaw|DB::raw|DB::statement|DB::select'
```

Most will be legitimate. What you're looking for is string interpolation or
concatenation of request data into the fragment. Bindings are the fix; if a raw
fragment must include a dynamic column or direction, validate it against an
allowlist — parameter bindings do not protect identifiers.

### Hard-coded credentials

```
rg -i 'api[_-]?key|secret|passwd|password|token|bearer' app/ config/ database/ resources/
```

Config files should read from `env()`; application code should read from `config()`,
never `env()` directly — a cached config makes `env()` return null in production, and
this fails silently.

Anything real that turns up must be **rotated**, not just deleted. It is in git
history, and removing the line does not un-leak it.

### Routes you didn't write

```
php artisan route:list
```

Installed packages register routes. Horizon, Telescope, debugbars, docs generators
and admin panels all add endpoints, and the defaults are not always restricted. Read
the whole list and ask which of these a stranger can reach in production.

## Structure

Real findings, lower priority. These cost future work rather than causing incidents.

### Fake facades

Classes of static methods written to look like Laravel facades. Laravel's facades are
container-resolved instances behind a static proxy; a class of genuine `static`
methods is a different thing with a real hazard: **static state persists for the whole
request**.

```php
PricingService::withTax();                              // sets static state
$a = PricingService::calculatePrice($productOne);       // with tax — intended
// ... elsewhere, unrelated code ...
$b = PricingService::calculatePrice($productTwo);       // ALSO with tax — surprise
```

The flag is still set. Two calls in unrelated parts of a request affect each other,
which is a genuinely hard bug to track down. The fix is an ordinary object resolved
from the container — testable, mockable, with state scoped to the instance.

Static methods are fine as stateless utilities. The smell is **static methods plus
static properties**.

```
rg 'public static function' app/ -l
```

### Business logic in helper functions

Small pure helpers are good. The smell is a helper doing real work — transactions,
model writes, dispatching jobs — because global functions can't be resolved from the
container, so they can't be mocked or substituted in tests. Every test touching that
code path runs all of it.

Move them into classes. Keep helpers for genuinely trivial, pure transformations.

### Controllers calling other controllers

```
rg 'new \w*Controller|Controller\(\)->|Controller::'
```

A controller's job is to handle an HTTP request. Calling one manually gives you an
object the framework never prepared — no route model binding, no middleware, no
injected dependencies, no request context. When two controllers need the same work,
the shared part belongs in a service or action that both call.

### Logic and queries in Blade

```
rg '@php|<\?php|<\?=' resources/views/
rg '::where|::all\(|->get\(\)|DB::' resources/views/
```

A query in a view runs per render, is invisible to anyone reading the controller, and
is usually an N+1 in a loop. Move data-fetching into the controller or a view model
and pass the result in. (`readable-code` covers the related rule: pass the value the
view needs, not the whole model.)

## Performance

### N+1 queries

The single most common performance finding. Any loop over a collection touching a
relationship, without a matching `with()`, is N+1:

```php
foreach ($comments as $comment) {
    echo $comment->author->name;   // one query per comment
}
```

Rather than hunting these by hand, make the framework find them — enable
`Model::preventLazyLoading()` outside production and let it raise on every violation.

Details, plus over-fetching columns and caching layers, in
`references/query-performance.md`.

## Dead code

`readable-code` says delete it. That assumes you can tell it's dead. When you can't:

1. **Read the version-control history.** Commit messages often say what it was for,
   and sometimes reveal it was meant to be removed in an earlier refactor. A hint,
   not proof.
2. **Scream test.** Remove it in a branch, run the suite, exercise the surrounding
   features manually. Passing tests are weak evidence — the feature may be untested —
   but failing ones settle it immediately.
3. **Instrument it.** For anything you still can't rule out, log or report on entry
   and deploy. After a full business cycle — including monthly and quarterly jobs,
   which is the trap — silence is real evidence.
4. **Remove it in its own commit**, so the revert is trivial if step 3 missed
   something.

Be especially careful with code reachable only from scheduled commands, queued jobs,
webhooks, or admin tooling. None of those show up in a scream test of the web UI.

## Reporting

Group by severity, not by file. For each finding: location, what's wrong, plausible
consequence, rough fix size. Separate **"this is a bug"** from **"this is a smell"** —
conflating them is what makes an audit easy to dismiss.

State what you did *not* look at. An audit that doesn't name its own blind spots reads
as more complete than it is.

## Related

- `references/query-performance.md` — N+1, eager loading, column selection, caching
- `references/grep-recipes.md` — starting commands for each smell, and their false positives

Overlaps worth knowing: a generic codebase-audit skill can structure the work and
write up plans, but doesn't know what `whereRaw` or a missing `authorize()` means in
Laravel — this supplies the findings, it doesn't replace that. A dedicated security
review goes deeper on vulnerability classes than the security section here.
