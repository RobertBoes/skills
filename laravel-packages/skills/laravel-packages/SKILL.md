---
name: laravel-packages
description: Build and maintain a distributable Laravel package — service provider registration, config-driven bindings with defaults that work before publishing, optional peer dependencies detected testably, exception messages a stranger can act on, state that survives Octane and other long-lived runtimes, driver-style extension points, version matrices and upgrade guides, and shipping (and testing) AI guidance with the package. Use when writing or reviewing a Laravel package, adding an integration with another package, widening a version constraint, or preparing a release with breaking changes.
---

# Laravel packages

A package is not a small application. Three things change, and every rule here follows
from them:

1. **You do not control the host.** Any Laravel version in your constraint, any config
   state, any combination of other packages, any runtime — FPM, Octane, queue workers,
   Lambda.
2. **Everything public is an API.** Class names, config keys, contract methods, event
   names, even the shape of a shared prop. Renaming one is a breaking change; there is
   no "just update the callers".
3. **Your errors are read by strangers** who have never seen your source and are three
   levels deep in someone else's stack trace.

Laravel's own [package development docs](https://laravel.com/docs/13.x/packages) cover
the mechanics — providers, publishing, commands, views. This skill is the part that is
learned by shipping: what breaks in someone else's app.

For application-side rules, Laravel Boost's `laravel-best-practices` skill and the
`request-handling`, `eloquent-queries` and `web-security` skills apply to package code
too — a package's controllers are still controllers.

## When to apply

- Writing or reviewing any code in a package.
- Adding support for another package (a driver, an adapter, an integration).
- Widening or bumping a version constraint.
- Preparing a release, especially one with a breaking change.
- Any time a package holds state between calls.

**It is a standard, not a task.**

### When you catch yourself thinking…

| Thought | Reality |
|---|---|
| "I'll rename this config key, it's clearer" | It's a public API. That's a major version. |
| "class_exists is untestable, so I'll skip the test" | Wrap it. See *Optional dependencies*. |
| "Nobody runs this on Octane" | Someone does, and they'll report it as "breadcrumbs from the wrong page". |
| "The exception says what went wrong" | Does it say what to *do*? Name the missing package and the command. |
| "prefer-stable passes, ship it" | `prefer-lowest` is what proves your constraint isn't lying. |

## First: read the package's conventions

Packages are more conservative than apps, because every change is someone else's
upgrade. Before adding anything: read `composer.json` (constraints, `extra.laravel`),
the config file's defaults and comments, `UPGRADING.md` and `CHANGELOG.md` for what has
already counted as breaking, and the CI matrix for what is actually supported. Match all
of it.

## The service provider

**Split registration from boot deliberately.** Bindings and singletons register;
anything that touches other packages' state boots.

**Bind from config with the default in code**, so the package works before anyone runs
`vendor:publish`:

```php
$this->app->bind(
    CollectorContract::class,
    config('my-package.collector', DefaultCollector::class),
);
```

A package that requires publishing config before it functions has made installation a
two-step process for no reason.

**Auto-register middleware, but let people opt out:**

```php
public function packageBooted(): void
{
    if (! config('my-package.middleware.enabled', true)) {
        return;
    }

    $this->app->make(Router::class)->pushMiddlewareToGroup(
        group: config('my-package.middleware.group', 'web'),
        middleware: Middleware::class,
    );
}
```

Both halves matter. Auto-registration is why the package works after `composer require`;
the opt-out is for the app that needs it in a different group, a different order, or not
at all.

**Publish under a tag, and never publish over a user's file without `--force`.**
`spatie/laravel-package-tools` collapses most of this to a `configurePackage()` call and
is worth the dependency.

## Optional dependencies

The hardest part of an integration package: supporting peers you do not require.

**Wrap `class_exists` so it can be faked:**

```php
class PackageExistenceChecker
{
    public function __invoke(string $class): bool
    {
        return class_exists($class);
    }
}
```

That is the whole class, and it exists for one reason: `class_exists` cannot be mocked,
so "behaves correctly when the peer is missing" is untestable without a seam. Bind it as
a singleton and inject it.

**Let each driver declare what it needs, and check at construction:**

```php
abstract class AbstractCollector implements CollectorContract
{
    public function __construct(private readonly PackageExistenceChecker $checker)
    {
        if (! ($this->checker)(static::requiredClass())) {
            throw new PackageNotInstalledException(static::packageIdentifier(), static::class);
        }
    }

    abstract public static function requiredClass(): string;

    abstract public static function packageIdentifier(): string;
}
```

Two static declarations per driver — the class to probe for, and the Composer name to
tell the user to install. Failing in the constructor means the error arrives when the
driver is resolved, not deep inside a method with a `Class "X" not found`.

**Put optional peers in `require-dev`** so CI tests against them, and never in
`require`.

## Errors a stranger can act on

An exception thrown by a package is read by someone who has never opened your source.
Name the problem, the cause, the fix, and the escape hatch:

```php
parent::__construct(sprintf(
    '%s is not installed, which is required by the configured collector [%s]. '
    .'Install it with `composer require %s`, or set a different collector in '
    .'config/my-package.php (the built-in %s requires no extra package).',
    $packageIdentifier,
    $collectorClass,
    $packageIdentifier,
    ClosureCollector::class,
));
```

Four things in one message: what is missing, which of *their* choices required it, the
exact command, and the option that needs nothing installed. Compare with
`Class "Glhd\Gretel\Registry" not found`, which sends them to your source to work out
which config key caused it.

Use custom exception classes, not `\Exception`, so applications can catch yours
specifically.

## State and long-lived runtimes

Under FPM every request gets a fresh container. Under **Octane, Swoole, RoadRunner or a
long-lived worker it does not** — a singleton holding request state leaks into the next
request, which surfaces as one user seeing another page's data.

If the package holds per-request state, clear it explicitly:

```php
private function clearStateOnOctaneRequest(): void
{
    if (! class_exists(RequestReceived::class)) {
        return;
    }

    $this->app['events']->listen(RequestReceived::class, function (): void {
        $this->app->make(MyPackage::class)->clearPending();
    });
}
```

The `class_exists` guard keeps Octane an optional peer. **Test it** by dispatching the
event at a stub class and asserting the state cleared — the bug is otherwise invisible
in a normal test suite, because each test boots a fresh app.

Also worth auditing for the same reason: static properties, memoised
`private ?Foo $cached` on singletons, and anything captured in a closure bound to the
container.

## Extension points

Where the package integrates with things you do not own, define a contract and ship
drivers for the common ones:

- **A contract with the narrowest method set** that every driver can honestly implement.
- **A default that needs no peer package**, so the package is usable out of the box.
- **An abstract base for what drivers share** — the dependency check, common helpers —
  keeping each driver to its translation logic.
- **Selection through config**, so an app can point at its own driver without touching
  a service provider.

Resist widening the contract for one driver's special case. A method that only one
implementation can support is a sign the abstraction is wrong, and every future driver
inherits it.

## Compatibility and releases

Constraints, the CI matrix, `prefer-lowest`, semantic versioning for packages, and
writing `UPGRADING.md`: `references/compatibility.md`.

The short version: support a range, prove it in CI across **both** ends of every
constraint, and write the upgrade guide as diffs at the moment you make the break — not
at release, when you have forgotten the details.

## Shipping AI guidance with the package

Laravel Boost installs third-party guidelines and skills that packages ship, from:

```
resources/boost/guidelines/core.blade.php
resources/boost/skills/<name>/SKILL.md
resources/boost/skills/<name>/references/*.md
```

A short guideline that names the package and points at the skill, plus a skill that
teaches the actual API, means a consuming app's assistant stops guessing at your method
names.

**Test it, or it becomes a liability.** Guidance that describes a renamed method is
worse than no guidance: it is confidently wrong and it ships with your tag. Assert that
every symbol the skill teaches still exists:

```php
$this->assertTrue(method_exists(MyPackage::class, 'serializeUsing'));

foreach ($collectorsTheSkillLists as $class) {
    $this->assertTrue(class_exists($class), "{$class} is referenced by the skill but no longer exists");
}

foreach (['default', 'always', 'deferred'] as $case) {
    $this->assertNotNull(ShareStrategy::tryFrom($case));
}
```

Also assert the files exist at the paths Boost scans, that the frontmatter parses, and
that every config key the skill documents is in the published config. A rename then
fails the suite instead of reaching users.

## Where to stop

- **Don't require an optional peer.** If it is in `require`, it is not optional.
- **Don't widen a contract for one driver.**
- **Don't rename public things for tidiness.** Deprecate, keep the old name working,
  remove at the next major.
- **Don't auto-register anything without an opt-out.**
- **Don't test the framework.** Test your package's behaviour against it.
- **Don't publish files the user did not ask for** — no migrations run on install, no
  config overwritten.

## Version notes

Verified 2026-09-12 against Laravel 13.x:

- `extra.laravel.providers` in `composer.json` drives package discovery; `aliases` there
  registers facades.
- `spatie/laravel-package-tools` provides `PackageServiceProvider` with
  `configurePackage()`, plus `packageRegistered()` / `packageBooted()` hooks.
- `orchestra/testbench` tracks Laravel majors — Testbench 10 for Laravel 12, 11 for
  Laravel 13. The matrix has to map them explicitly.
- Boost scans `resources/boost/` in installed packages for guidelines and skills.
