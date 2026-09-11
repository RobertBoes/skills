---
name: request-handling
description: Decisions about Laravel's HTTP layer that the framework's own guidance leaves open — invokable versus grouped controllers, the signals that a resource route has stopped describing a resource, middleware as an authorisation location, and splitting a queued job from the action it calls. Use when adding a controller or route, when a resource route is accumulating siblings, when deciding where an authorisation check goes, or when the same operation must run both synchronously and queued.
---

# Request handling

A supplement, not a survey. **Laravel Boost ships a `laravel-best-practices` skill**
(`vendor/laravel/boost/.ai/laravel/skill/`) whose `routing.md`, `architecture.md`,
`validation.md` and `security.md` already cover route model binding, scoped bindings,
resource versus explicit routes, modelling a custom verb as its own resource, keeping
controllers focused on HTTP, extracting action classes, dependency injection, and where
authorisation may live. **Read those first where they are installed; this file does not
restate them.**

What follows is the handful of decisions they leave open, plus two pieces of folklore
worth killing. If Boost is not installed in a project, its rules are still the better
starting point — they ship with the framework and are versioned with it.

Also adjacent: `api-design` for the response contract, `web-security` for headers and
CORS, `eloquent-queries` for where a query constraint goes, `queued-jobs` for the
reliability rules a job has to satisfy.

## When to apply

Adding a controller or a route; watching a resource route accumulate siblings; deciding
where an authorisation check goes; needing one operation both synchronously and queued.

**The project wins.** A shape already applied consistently outranks anything here.

## Invokable or grouped?

Boost says organise controllers around one resource. It does not say when a controller
should hold a single action instead.

**Group when actions share both identity and dependencies.** Two actions on the same
noun that need the same repository belong in one class:

```php
class BlogController
{
    public function __construct(private BlogPostRepository $posts) {}

    public function index(): View { … }
    public function show(string $slug): View { … }
}
```

**Go invokable when there is no noun to group by** — a webhook receiver, a sitemap, an
unsubscribe link:

```php
class SitemapController
{
    public function __invoke(): Response { … }
}
```

Two actions that merely share a URL prefix are not a group. A controller with eight
methods covering unrelated operations is a namespace pretending to be a class.

**Invokable controllers are not the goal.** Aiming for them everywhere produces
`PostPublishController`, `PostUnpublishController` and `PostArchiveController` — three
files that should have been one resource. That is the failure Boost's "model the verb as
a resource" rule prevents; this rule is its other half.

## When a resource route has stopped describing a resource

`Route::resource` claims a URL space is one noun with the standard operations. Boost
covers taking that claim when it is true. These are the signals it has quietly stopped
being true:

1. **`->only()` excludes more than it includes.** Two actions in a resource's clothes.
   (`->only(['index','show','store','update','destroy'])` is a different problem: that
   is `Route::apiResource` written longhand.)
2. **Sibling routes are accumulating** next to the resource that the seven do not cover,
   so what exists for that noun can only be learned by reading two places.
3. **The methods have stopped being about the same record.**
4. **A different authorisation *model* is needed per action** — not merely different
   middleware, which `middlewareFor()` handles on the resource itself.
5. **A method name has become aspirational** — a `store` that does not store.

On signal 2, apply Boost's rule first: look for the noun hiding in the verb, and check
whether `Route::singleton(...)->creatable()` models it. Break out to explicit routes only
when there genuinely is no noun.

**Then match the file you are in.** Two structurally identical resources routed
differently ten lines apart is worse than either style applied throughout — the reader
cannot predict which they will meet. This is the specific case of Boost's
"Consistency First", and it is the one that decays fastest under per-change edits,
because every individual change looks locally reasonable.

## Middleware is the third authorisation location

Boost names policies, gates and form requests. It omits the one that covers
request-level state:

| Check | Where |
|---|---|
| Precondition shared by many routes | **Middleware** — team is active, user is not suspended, IP is not blocked |
| May this user act on this record | **Policy** |
| Is the payload well-formed | **Form request `rules()`** |

The distinction that matters: a policy answers a question about a *record*, middleware
answers one about the *request*. Putting "is this team still active" in every policy
duplicates it; putting "does this user own this post" in middleware means it silently
stops applying the moment someone adds a route to the group.

**Diagnosing `authorize(): return true`.** It is correct when a policy or middleware does
the work, and a hole when nothing does — the same line of code, opposite verdicts. The
question to answer before accepting it is *what else authorises this?* "The route is
behind `auth`" only proves the user is signed in.

## Jobs and the actions they call

Boost's `architecture.md` covers extracting an action; its `queue-jobs.md` covers
retries, uniqueness and backoff. Neither covers the seam between them.

Keep the job thin and let it call the action:

```php
class ProcessDataDeletionJob implements ShouldQueue
{
    public function __construct(private DataDeletionRequest $request) {}

    public function handle(PurgeSubmissionData $action): void
    {
        $action->handle($this->request);
    }
}
```

- **The job owns queue concerns** — retries, backoff, uniqueness, terminal state.
- **The action owns the work**, and stays callable synchronously from a controller,
  command or panel.
- **The action must be idempotent anyway**, because the job may re-enter it after a
  successful run. See `queued-jobs` for why that is not optional.

Putting the work in the job makes it unreachable outside a queue; putting queue concerns
in the action makes it untestable without one.

## Where to stop

- **Don't restate Boost.** If a rule is already in its `routing.md` or
  `architecture.md`, defer to it — one maintained source beats two drifting ones.
- **Don't add a fourth authorisation location.** A bespoke check inside a controller is
  the one that gets forgotten on the next route.
- **Don't convert routing styles as a drive-by.** Match the file; convert deliberately
  or not at all.
- **Don't split a cohesive controller** just to make it invokable.

## Version notes

Verified against **Laravel 13.x** on 2026-09-12, framework source and docs:

- **Closure routes do not break `route:cache`.** `Route::prepareForSerialization()`
  wraps them with `SerializableClosure` — identical code in 13.x and back through 10.x,
  with no exception thrown. The rule is repeated everywhere and has been wrong for
  years. A closure that *captures* something unserializable still fails, which is a
  different problem and names itself in the error.
- **`middlewareFor()` and `withoutMiddlewareFor()`** apply middleware to named methods of
  a resource or singleton route, so "these two actions need different middleware" is no
  longer a reason to abandon the resource.
- `Route::apiResource` registers exactly `index`, `show`, `store`, `update`, `destroy`.
- The base `Controller` generated by current Laravel is an empty abstract class; it does
  not bring `AuthorizesRequests` or `ValidatesRequests` with it.
