# skills

Claude Code skills for writing readable code, refactoring, designing interfaces, and
building Laravel apps that hold up.

Each is a set of trigger→action rules meant to fire while you work — during code
review, while writing a component, while cleaning up a long method — rather than a
document you read once.

## Skills

| Skill | Covers |
|---|---|
| [`readable-code`](readable-code/skills/readable-code/SKILL.md) | Naming, guard clauses instead of nesting, removing dead code and explanatory comments, avoiding `null` returns, symmetry. Fires when writing or reviewing any code. |
| [`refactoring`](refactoring/skills/refactoring/SKILL.md) | When duplication is ready to abstract, extracting long methods by level, objects over primitives, turning accumulating loops into pipelines. Fires on long methods, duplication, and loops that build up a result. |
| [`ui-design`](ui-design/skills/ui-design/SKILL.md) | Reading a project's design tokens, visual hierarchy, spacing and type systems, color and shades, depth and shadows, empty states. Fires on UI markup and styles. |
| [`api-design`](api-design/skills/api-design/SKILL.md) | Resources and endpoints, status codes, RFC 9457 error bodies, loading related data without n+1, pagination, versioning. Fires when adding or reviewing an HTTP endpoint. |
| [`queued-jobs`](queued-jobs/skills/queued-jobs/SKILL.md) | Idempotency, payload and serialization limits, retries and backoff, concurrency and rate limiting, deployment restarts. Fires when writing or reviewing a queued job. |
| [`laravel-audit`](laravel-audit/skills/laravel-audit/SKILL.md) | Authorisation gaps, unvalidated input, raw queries, exposed package routes, fake facades, logic in Blade, N+1 queries, dead code. Fires when auditing an existing Laravel project. |
| [`eloquent-queries`](eloquent-queries/skills/eloquent-queries/SKILL.md) | Where a query constraint belongs — inline, attribute scope, tappable scope, query object, global scope registered at a boundary, shared eager loads. Fires on a `where` written outside a model. |
| [`web-security`](web-security/skills/web-security/SKILL.md) | CSP and security headers, subresource integrity, cookie flags, CORS, the reverse-proxy trust boundary, bot protection. Fires when adding a third-party script, a public form, or a new domain. |
| [`laravel-packages`](laravel-packages/skills/laravel-packages/SKILL.md) | Service provider wiring, optional peer dependencies detected testably, errors a stranger can act on, Octane-safe state, driver extension points, version matrices, upgrade guides. Fires when writing package code. |
| [`request-handling`](request-handling/skills/request-handling/SKILL.md) | The HTTP-layer decisions Laravel Boost's own guidelines leave open — invokable vs grouped controllers, when a resource route has drifted, middleware as an authorisation location, the job/action seam. Fires when adding a route or a controller method. |

They're split by topic, not by source: `readable-code` is about how code reads where
you write it, `refactoring` is about changing its structure. A messy method usually
wants both. `web-security` and `laravel-audit` split the same way — the first is
authoring guidance for the edge of an app, the second audits application code it already
has.

## Install

```
/plugin marketplace add RobertBoes/skills
/plugin install readable-code@robertboes-skills
/plugin install refactoring@robertboes-skills
/plugin install ui-design@robertboes-skills
/plugin install api-design@robertboes-skills
/plugin install queued-jobs@robertboes-skills
/plugin install laravel-audit@robertboes-skills
/plugin install eloquent-queries@robertboes-skills
/plugin install web-security@robertboes-skills
/plugin install request-handling@robertboes-skills
/plugin install laravel-packages@robertboes-skills
```

For local development, point at the directory instead:

```
/plugin marketplace add ~/Developer/skills
```

### Without the plugin system

Install with the [skills CLI](https://www.skills.sh/docs/cli).

```
# All skills
npx skills add https://github.com/RobertBoes/skills

# Specific skill
npx skills add https://github.com/RobertBoes/skills --skill readable-code
```

## Making changes

Edit a skill, then bump `version` in **both** manifests — the entry in
`.claude-plugin/marketplace.json` and that plugin's
`<plugin>/.claude-plugin/plugin.json`. Commit and push, then on any machine that has
it installed:

```
/plugin marketplace update robertboes-skills
```

Without the version bump the update won't be picked up.

While iterating, skip the round trip entirely — add the working directory as a local
marketplace (see Install above) and changes to `SKILL.md` take effect on the next
session.

### Forking

```bash
gh repo fork RobertBoes/skills --clone
```

Then rename the marketplace in `.claude-plugin/marketplace.json` — the `name` field
is what `@robertboes-skills` refers to at install time, so leaving it unchanged will
collide with this one.

## Layout

A marketplace repo holds plugins; a plugin holds skills:

```
skills/                                <- the marketplace (this repo)
  .claude-plugin/marketplace.json
  readable-code/                       <- a plugin
    .claude-plugin/plugin.json
    skills/readable-code/              <- the skill
      SKILL.md
      references/
  refactoring/
  ui-design/
  api-design/
  queued-jobs/
  laravel-audit/
  eloquent-queries/
  web-security/
  request-handling/
  laravel-packages/
  tests/                               <- claim checks, not a plugin
```

Each skill is its own plugin so they install independently. To add another, create a
sibling directory with a `.claude-plugin/plugin.json` and a `skills/<name>/SKILL.md`,
then add an entry to `marketplace.json`.

## Verifying the claims

The skills state version-specific facts — that an interface exists, that a method
ignores its callback's return value, that a config key is still called what it was
called. Those rot silently, and a stale claim in a skill is worse than no skill: it is
confidently wrong, and it fires automatically.

`tests/` holds a small PHPUnit suite that asserts them against a real Laravel install:

```bash
cd tests
composer install
vendor/bin/phpunit
```

It runs on every push and PR, and weekly on a schedule so drift surfaces without anyone
touching the repo. **The lock file is deliberately not committed** — the point is to
test against the newest releases, not a pinned set.

**When a skill states a checkable fact, add an assertion.** Each test names the skill
file and quotes the claim, and each failure message says which claim broke:

```php
$this->assertTrue(
    is_subclass_of($class, QueryContract::class),
    "{$class} no longer satisfies the query builder contract, which the pinned scope shape depends on",
);
```

This earned itself on the first run: it caught that `handleLazyLoadingViolationUsing()`
passed the handler **two** arguments on released Laravel, not three, so the snippet
`laravel-audit` shipped would have died with an `ArgumentCountError`. The three-argument
form existed only on the framework's development branch, which is what had been read.
It caught the reverse too: when Laravel 13.32 shipped the third argument, the scheduled
run failed and the note was updated.

The idea is lifted from `robertboes/inertia-breadcrumbs`, which tests the Boost skill it
ships against its own API for the same reason — see the `laravel-packages` skill.

## Design notes

These are written to survive framework churn and to avoid the usual failure mode of
"guidelines" skills, which is being nodded at and ignored.

**Triggers, not principles.** Rules are written as a condition and an action — "an
`else` after a branch that returns → drop the `else` and de-indent" — so they can
actually fire during review. Advice phrased as a virtue does nothing.

**No hardcoded project values.** `ui-design` reads the project's own theme tokens —
spacing, type scale, palette, shadows — and only falls back to a starting scale when
a project genuinely has none. `api-design` does the same with an API's existing
envelope, error shape, and pagination style, on the grounds that a consistently
mediocre API beats an inconsistently good one. Framework defaults move; relationships
like "non-linear steps, ~25% minimum gaps, line-height inverse to font size" don't.

**Operations, not method names.** `refactoring` names the operation (keep matching,
flatten one level, first match) and maps it across PHP/JS/Python/Ruby, with an
explicit instruction to verify signatures against the installed version rather than
trusting the write-up.

**Explicit limits.** Every skill says where to stop: don't rewrite untouched code,
don't abstract code that appears once, don't build a pipeline past ~5 links, don't
override a project's design system. Unbounded advice produces overreach.

**Correctness skills state their failure model.** `queued-jobs` opens with why a job
can run more than once — including after it has already succeeded — because every
rule in it follows from that, and a rule whose reason you know survives a version
bump that changes its syntax.

**Standards over summaries.** Where a public specification already covers something —
JSON:API, RFC 9457, OpenAPI, the CSP spec and MDN's directive reference — the skill
links to it and defers to it rather than paraphrasing a book's account of it.
Paraphrases rot; specs get revised in place.

**Claims are tested, not just dated.** A verification date says when someone looked; a
test says whether it is still true. Anything checkable gets an assertion in `tests/` —
see above.

**Defer to whatever is already maintained.** Before adding a skill, check what the
framework's own tooling ships — Laravel Boost installs a `laravel-best-practices` skill
into `vendor/`, versioned with the framework. `request-handling` was cut by two thirds
once that was read, and now points at those rules rather than competing with them. Two
sources of advice on one question is worse than one, and the one that updates itself
should win.

**Preferences are labelled as preferences.** `eloquent-queries` prefers tappable
scopes, which are a community pattern the framework does not document. The skill says
so in as many words, states the cost against the built-in alternative, and defers to
whatever a project already uses — a skill that dresses up taste as consensus will
eventually be believed.

**Dated notes are quarantined.** Anything version-specific lives in a reference file
with a verification date, marked subordinate to the project's own config — see
`ui-design/skills/ui-design/references/framework-notes.md`.

If a rule ever conflicts with a project's conventions, the project wins; the skills
say so explicitly.

## Where this comes from

Three sources, in descending order of volume: books, primary documentation, and
things learned by using these skills and getting them wrong.

### Books

Most of the substance. The rules, examples, and organization here are written from
scratch, but the thinking behind them is theirs:

- **[BaseCode Field Guide](https://basecodefieldguide.com)** — Jason McCreary. The
  backbone of `readable-code` and the extraction process in `refactoring`.
- **[Refactoring to Collections](https://adamwathan.me/refactoring-to-collections/)**
  — Adam Wathan. The loops-to-pipelines material in `refactoring`.
- **[Refactoring UI](https://refactoringui.com)** — Adam Wathan & Steve Schoger.
  Nearly all of `ui-design`.
- **[Build APIs You Won't Hate](https://apisyouwonthate.com)** — Phil Sturgeon. The
  design thinking in `api-design`, particularly the two-layer approach to errors and
  the relationship-loading tradeoffs.
- **[Battle Ready Laravel](https://battle-ready-laravel.com)** — Ash Allen. The audit
  checklist and dead-code procedure in `laravel-audit`. Its companion, *The Clean
  Coder's Guide to Laravel*, supplied the query-performance material.
- **[Laravel Queues in Action](https://learn-laravel-queues.com)** — Mohamed Said
  (second edition). The failure model and reliability rules in `queued-jobs`.

Also drawn on throughout: Kent Beck's *Implementation Patterns*, Martin Fowler's
*Refactoring*, Hunt & Thomas's *The Pragmatic Programmer*, and Rob Pike on complexity.

Buy the books. They contain the reasoning, the worked examples, and — in Refactoring
UI's case especially — before/after imagery that no text summary can replace. These
skills are a working reference for an AI assistant, not a substitute for reading them.

### Standards and documentation

Where a living specification covers the ground, the skill follows the spec rather than
a book's account of it — paraphrases rot, specs get revised in place:

- [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457.html) for error bodies and
  [JSON:API](https://jsonapi.org) for document structure, in `api-design`
- [Laravel's queue documentation](https://laravel.com/framework/docs/queues) for job
  mechanisms, in `queued-jobs`
- Current framework defaults for scales, palettes and shadows, in `ui-design`
- [Laravel's Eloquent documentation](https://laravel.com/docs/13.x/eloquent) for the
  `#[Scope]` and `ScopedBy` attributes, in `eloquent-queries`

Several rules exist *because* a source had been overtaken: `api-design` uses RFC 9457
in place of the hand-rolled error codes its book predates, `queued-jobs` points at job
middleware where its book hand-rolls the same thing with Redis primitives, and
`ui-design` treats framework defaults as observations to verify rather than values to
apply.

### Articles

One skill comes from blog posts rather than a book. `eloquent-queries` is built on the
tappable-scope pattern as described by:

- **[Unorthodox Eloquent](https://muhammedsari.me/unorthodox-eloquent)** — Muhammed
  Sarı. The largest source: tappable scopes, global scopes registered at a boundary,
  fluent query objects, and shared eager loads all come from here.
- **[Elevate Your Laravel Eloquent Queries With Tappable Scopes](https://seankegel.com/elevate-your-laravel-eloquent-queries-with-tappable-scopes)**
  — Sean Kegel.
- **[Tappable Query Scopes in Laravel](https://www.juststeveking.com/articles/tappable-query-scopes-in-laravel/)**
  — Steve McDougall.

The pattern is theirs; the decision table for *when* to use it, the pinned
implementation shape, and the limits are not.

### Original to this repo

Not from any book:

- **The invocation design** — the finishing-a-change trigger, the anti-rationalization
  tables, "it's a standard, not a task", and the reconciliation with minimal-change
  disciplines. These came from watching these skills fail to fire while writing code,
  and fixing the triggering rather than the rules.
- **Conventions-first.** Every skill that could impose a house style reads the
  project's own first — design tokens, API shape, queue setup, existing job
  conventions — and says explicitly that the project wins on conflict. That is a
  decision made here, and it is the main thing keeping these from going stale.
- **Explicit limits.** Where to stop is stated everywhere: chain length, closure size,
  when a plain loop beats a pipeline, when not to introduce an object, not rewriting
  untouched code. Books rarely bound their own advice; unbounded advice produces
  overreach.
- **Corrections and additions**, including: that closure routes have not broken
  `route:cache` since at least Laravel 10, despite the rule still being repeated
  everywhere; that `middlewareFor()` removes "these actions need different middleware"
  as a reason to abandon a resource route; the 403-versus-404 security tradeoff its
  book treats purely as sloppiness; that `preventLazyLoading` only fires on code that
  actually executes; that grep output is a shortlist rather than a finding; and the
  job status lifecycle in `queued-jobs` — that a `try`/`catch` never runs for a killed
  worker, so terminal state belongs in `failed()` with a reconciliation sweep behind
  it; and that the usual case for tappable scopes ("Laravel's `scopeXxx` prefix is
  ugly") no longer holds, because current Laravel declares local scopes with a
  `#[Scope]` attribute — leaving reuse across models and name collisions as the
  arguments that survive.

### From my own projects

Some rules come from codebases rather than books. The `queued-jobs` status lifecycle
started as a project-level convention skill and was generalized here after the
underlying failure mode became clear. `laravel-packages` came out of `robertboes/inertia-breadcrumbs`: the optional-peer
checker that exists purely to be fakeable, exceptions that name the missing package and
the command to install it, clearing per-request state on an Octane event, and the
matrix that tests both ends of every constraint. `request-handling` came out of comparing the HTTP layer
of three of my own apps written years apart — what stayed constant is in the skill, what
changed between them is deliberately not. It is also deliberately small: most of what I
first wrote turned out to be covered by Laravel Boost's own `laravel-best-practices`
rules, which ship with the framework and are versioned with it, so the skill was cut back
to the decisions those leave open and now points at them instead of restating them. `web-security` was reconstructed from the
headers, CSP, cookie and proxy setup of two of my own apps — the deny-by-default posture, the
scoped dev relaxations, gating HSTS on production *and* an actually-secure request, and
the live-validation interaction that makes honeypots misfire all came from code that was
already running. The domains, hosts and analytics vendors did not: every example here is
`example.com`.

Project-level skills and general skills are different things and should stay that way.
A project skill encodes *this* codebase's conventions — its enums, its loggers, its
base classes — and rightly outranks anything general. What belongs here is only the
part that would still be true in someone else's codebase.

Two rules for anything promoted out of a private project:

1. **Generalize or leave it.** If a rule only makes sense with a specific class, table
   or service name in it, it is a project skill, not a general one.
2. **Carry nothing proprietary.** No internal service names, schema details,
   credentials, customer names, or security specifics — not in prose, and not in the
   examples. Rewrite examples in a neutral domain before they land here.

## License

[MIT](LICENSE) for the contents of this repository.
