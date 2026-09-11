# Compatibility, matrices and releases

Verified 2026-09-12. Version numbers age; the method does not.

## Constraints

Support a range, and mean it:

```json
"require": {
    "php": "^8.2",
    "illuminate/contracts": "^12.0|^13.0",
    "some/peer": "^2.0|^3.0"
}
```

- **Depend on `illuminate/contracts`, not `laravel/framework`.** A package needs the
  interfaces, not the whole framework, and requiring the framework in a package that a
  framework app already has is a constraint conflict waiting to happen.
- **Optional peers go in `require-dev`.** If it is in `require`, it is not optional.
- **Widen with intent.** Adding `|^13.0` is a claim you have tested against 13. The
  matrix below is how you make that claim true.

## The CI matrix

Test both ends of every axis:

```yaml
strategy:
  matrix:
    php: [8.2, 8.3, 8.4, 8.5]
    laravel: [12.*, 13.*]
    peer: [^2.0, ^3.0]
    stability: [prefer-lowest, prefer-stable]
    include:
      - laravel: 12.*
        testbench: ^10
      - laravel: 13.*
        testbench: ^11
```

- **`prefer-lowest` is the one that matters.** `prefer-stable` proves the package works
  with today's versions; `prefer-lowest` proves the *bottom* of every constraint
  actually works. It is where you discover that `^12.0` should have been `^12.14`
  because you used a method added in a patch release.
- **Testbench tracks Laravel majors** and must be mapped per row with `include`, not
  guessed.
- **`exclude` impossible combinations** rather than loosening the matrix — a PHP version
  a Laravel release never supported, a peer version that dropped an old framework.
- Keep the matrix honest with the constraint. A matrix narrower than `composer.json` is
  a promise you are not testing.

## Testbench

`orchestra/testbench` boots a minimal Laravel app around the package:

```php
class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [PeerServiceProvider::class, MyPackageServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app->config->set('database.default', 'testing');
    }
}
```

- **Register peers' providers too** when a driver needs them; order matters where one
  provider reads another's config.
- **`defineEnvironment` is for config the package assumes exists.** Anything a host app
  would normally have set up — a middleware group, a disk, a connection — has to be
  created here, and a new Laravel version may start requiring something that used to be
  implicit.
- **Stubs for optional peers.** When a peer is not installed for a given matrix row,
  a stub class in `tests/Stubs` lets the test still exercise the code path, which is how
  you test an Octane listener without depending on Octane.
- **Test the absent case explicitly** — that the right exception is thrown, with the
  right message, when a peer is missing. That path is what most users hit first.

## Semantic versioning for packages

What counts as breaking is wider than it looks. All of these are major:

- Renaming or removing a config key, or changing its default in a way that changes
  behaviour.
- Renaming a class, contract, method, or the string name of an event.
- Adding a method to a contract that consumers implement.
- Narrowing an accepted type or widening a return type.
- Changing the shape of anything serialized — a payload, a shared prop, a response key.
- Dropping a PHP, framework or peer version from the supported range.

Not breaking: adding a config key with a default that preserves current behaviour;
adding a driver; widening an accepted type; adding an optional constructor parameter to
a class consumers do not construct themselves.

**Deprecate before removing.** Keep the old name working, forward it to the new one,
mark it `@deprecated` with the version that removes it, and remove at the next major.

## UPGRADING.md

A changelog says what changed. An upgrade guide says what the reader has to *do*, and it
is a separate file:

```markdown
## From 0.8.x to 1.0.0

### Minimum version requirements

- PHP 8.2 or higher is now required (was 8.1)
- Laravel 12 or higher is now required (was 10)

### The prop is always present

Previously the `breadcrumbs` prop was only shared when breadcrumbs existed. It is now
always present, and `null` when there are none.

```diff
- <nav v-if="'breadcrumbs' in $page.props">
+ <nav v-if="$page.props.breadcrumbs">
```
```

- **One section per breaking change**, with a diff where a diff is possible.
- **Write it in the PR that makes the break**, not at release. The details are in your
  head exactly once.
- **Include the reason** when the change is surprising. A reader who understands why is
  a reader who does not open an issue.
- Keep old sections forever. People upgrade across several majors at once.

## Release mechanics

- Tag from a green matrix, not from a green single run.
- Generate the changelog from PR titles only if PR titles are disciplined enough to read
  as a changelog; otherwise write it.
- A `build/` or `dist/` artifact committed to the repo needs a check that it matches its
  source, or it will drift from the code that generated it.
