---
name: queued-jobs
description: Design reliable background jobs — idempotency, self-contained payloads, serialization limits, retries and backoff, concurrency and rate limiting, batches and chains, deployment restarts. Use when writing or reviewing a queued job or listener, dispatching work to a queue, debugging a job that ran twice or lost work, or configuring retries, timeouts and workers. Examples are Laravel; the failure model applies to any queue.
---

# Queued jobs

Background jobs fail in ways request code does not, and the failures are usually
invisible in development. This skill is about correctness under retry and
concurrency — not style. For how the code reads, see `readable-code`.

## The failure model

Everything below follows from one fact:

> A job may run **zero times, once, or many times**, on **different machines**, **in
> parallel with itself**, at an **unknown later moment**, after the world has changed.

The part people miss is *why* "many times" is unavoidable. An attempt is counted as
failed when:

- the worker couldn't fetch or deserialize the payload — **the job never ran**
- the job threw, or hit its timeout — the job ran **partially**
- the worker crashed, or couldn't mark the job complete, **after `handle()` returned
  successfully** — the job ran **fully, and will run again**

That last case is the important one. **A job can succeed and still be retried.** So
"my job only runs twice if it throws" is false, and idempotency is not an optional
hardening step — it is a precondition for anything with a side effect.

This is at-least-once delivery. It is the same in Sidekiq, Celery, BullMQ and SQS.

## First: read the project's queue setup

Before adding or changing a job, find out what already exists:

1. **Driver and connections** — `config/queue.php`. Redis, SQS, database? Payload
   limits and semantics differ (SQS caps messages at 256 KB).
2. **Queues in use** and how work is separated — are slow or third-party jobs
   isolated onto their own queue and workers?
3. **Worker configuration** — supervisor config, Horizon config, or the deploy
   script. What are `--tries`, `--timeout`, `--max-time` set to globally?
4. **Existing job conventions** — do jobs in this codebase use attributes or
   properties, middleware or hand-rolled limiting, batches or chains?
5. **Failure handling** — is there a `failed()` method convention, a `failed_jobs`
   table being monitored, alerting?

Match what's there. A job that retries 20 times in a codebase where everything else
retries 3 is a surprise waiting to page someone.

## Design rules

### Self-contained

Capture what the job needs **at dispatch**, not at execution, unless you genuinely
want the value as of run time.

```php
// The promotion may have ended by the time this runs.
ApplyDiscount::dispatch($order, $promotion->percentage);
```

For every input, ask: *should this be frozen at dispatch, or resolved when it runs?*
Both are valid; the bug is not deciding. A job that re-reads "the current rate" hours
later is correct for a sync job and wrong for a user-initiated action.

### Idempotent

Any job with an external side effect — money, email, third-party writes — must be
safe to run twice.

- **Guard on the real source of truth, not your own flag.** A local `shipped` column
  doesn't help if the crash happened between the carrier accepting the shipment and
  your write.
- **Prefer an idempotency key** the remote service honours. That's the only guard
  that closes the window completely.
- **Make the check cheap** — if verifying costs a slow API call on every run, cache
  the outcome or reach for `ShouldBeUnique` to reduce how often you need it.

Full treatment, including what to do when the provider offers no key:
`references/reliability.md`.

### Light and simple payloads

Job properties are serialized, stored, and moved over a network.

- Simple types — strings, ints, arrays, enums. Resolve services from the container
  inside `handle()` rather than constructor-injecting them.
- Pass Eloquent models and let the framework store a reference, not the row.
- Pass a reference to large data (an ID, a storage path), never the data.
- A queued closure stores **its own body and its `use` variables**. More than a few
  lines: make it a class.
- A chain nests every later job's payload inside the first job's.

Details and worked examples in `references/payloads.md`.

### Parallel-safe

Two copies of the same job can run at the same moment, on different machines. If
that would corrupt state, say so explicitly with a lock or overlap middleware —
don't assume the queue serializes anything. It doesn't.

### Aware of shared worker state

A worker boots **one application instance** and reuses it for every job it processes.
Static properties, container bindings, config values and the app locale all persist
between unrelated jobs on that worker.

So a job that changes state leaks it into whatever the worker picks up next:

```php
public function handle(): void
{
    app()->setLocale($this->locale);   // every later job on this worker
    // ...                             // now runs in $this->locale
}
```

Three rules:

1. **Restore what you change**, at the end of `handle()`.
2. **Restore it in `failed()` too** — an exception skips the rest of `handle()`, so
   the cleanup never runs and the leak persists.
3. If several jobs do this, **reset defaults in job middleware** rather than
   duplicating cleanup, so a job that forgets still starts from a known state.

**State needed before the job is deserialized** — the classic case being a
multi-tenant database connection, which must be configured before a serialized model
can be resolved — cannot be set inside `handle()` or middleware, because both run too
late. Set it from the `JobProcessing` event, which fires after the worker picks the
job up but before the instance is built. The job's properties aren't available yet,
so stash what you need in the raw payload at dispatch time (`Queue::createPayloadUsing`)
and read it from the event.

This is a silent-corruption bug class: it never reproduces in a single-job test, and
it surfaces as an unrelated job behaving strangely.

## Problem → mechanism

Name the problem first, then reach for the mechanism. In Laravel these are job
middleware, returned from a `middleware()` method.

| Problem | Reach for |
|---|---|
| Two copies of this job must not run at once | `WithoutOverlapping` keyed on the resource id |
| Only N of these may run concurrently | `WithoutOverlapping`, or a concurrency limiter keyed per tenant |
| Third-party API has a rate limit | `RateLimited` / `RateLimitedWithRedis` against a named limiter |
| Remote service is down and every job is burning a worker | `ThrottlesExceptions` — stop attempting for a while (circuit breaker) |
| Some exceptions are permanent and shouldn't retry | `FailOnException` for those classes |
| This job should be skipped under some condition | `Skip::when()` / `Skip::unless()` |
| Only one of these should be queued at a time | `ShouldBeUnique` — set `uniqueId()`, or the lock key defaults to the class name and dedupes across unrelated subjects. Use `ShouldBeUniqueUntilProcessing` if a new one should be queueable once work starts |
| Dispatched repeatedly; only the last matters | `#[DebounceFor]` (Laravel 13.6+) |
| Many jobs, one completion callback | `Bus::batch()` with `then` / `catch` / `finally` — dispatch in chunks if there are many (see below) |
| Steps that must run in order, stopping on failure | `Bus::chain()` |
| Payload contains sensitive data | `ShouldBeEncrypted` |

**Isolate slow and unreliable work onto its own queue**, with its own workers. If ten
workers all block for a ten-second timeout against a dead service, nothing else in
the system gets processed. Dedicating a subset of workers bounds the damage — a
bulkhead. This is a worker-configuration decision, not a code one, and it is often
more effective than any middleware.

## Attempts, timeouts, backoff

- **Set attempts deliberately.** One attempt is a sane local default and a bad
  production one. Note that in Laravel 13 `--tries=0` means retry *indefinitely*.
- **Back off between retries**, and prefer an increasing series over a fixed delay —
  an immediate retry against a service that just failed usually fails again.
- **`maxExceptions` is not `tries`.** A job released back to the queue by a limiter
  consumes an attempt without throwing. Allowing many attempts but few exceptions
  lets a job wait its turn repeatedly while still failing fast on real errors.
- **`retryUntil()` is often the better bound** for time-sensitive work — retrying a
  notification about an event that has already happened is pointless.
- **Timeouts must be shorter than the connection's `retry_after`**, or a job will be
  retried while the original is still running. This is a common source of
  accidental double execution.
- **Implement `failed()`** for anything a human needs to know about. A job that ends
  up in `failed_jobs` unnoticed is silent data loss.
- **If you hold a lock, backoff must outlast the lock's expiry.** A job that fails
  while holding a 10-second lock and retries immediately finds its own lock still in
  place and does nothing useful — burning attempts until it gives up.

**"Job has been attempted too many times or run too long"** has four causes, and
they are worth distinguishing before changing `tries`:

1. The job timed out on the last attempt.
2. The worker or server crashed mid-attempt.
3. The job was released back to the queue (often by a limiter) during the attempt.
4. `retry_after` is shorter than the job's timeout, so a second instance started
   while the first was still running.

Only the first is "the job is too slow". The fourth is a configuration bug that also
causes duplicate execution, and it's the one to rule out first.

## Large batches

Dispatching a batch writes a row to `job_batches` and holds a lock on it while the
jobs are being written to the queue. With a large batch that lock is held for a long
time, and every worker finishing a job in that batch queues up behind it. Two
consequences, both nasty:

- A worker waiting on the lock can hit its own timeout and be killed — so a job that
  **already completed its work** is retried.
- On MySQL/InnoDB the update fails outright after 50 seconds: `Lock wait timeout
  exceeded`.

**Dispatch large batches in chunks**, so the lock is released between them. The same
applies when adding jobs to an existing batch.

## Deployment

Workers are long-lived processes running the code they booted with. After deploying:

```
php artisan queue:restart        # graceful: finish current job, then exit
php artisan horizon:terminate    # Horizon equivalent
```

Consequences worth knowing: web traffic hits new code while workers still run the
old code until they finish. So a deploy that changes a job's constructor signature
can leave already-queued payloads that the new code cannot deserialize. Add
properties with defaults rather than changing signatures, or drain the queue first.

## Reviewing a job

1. Does it have an external side effect, and is it idempotent against the real source
   of truth?
2. Is every input deliberately frozen-at-dispatch or resolved-at-run?
3. Are the payload properties simple and small?
4. What happens if two copies run simultaneously?
5. Are attempts, backoff and timeout set, and is the timeout under `retry_after`?
6. Does it need a dedicated queue to avoid starving other work?
7. Is there a `failed()` path, and does anyone find out?

## Version note

Mechanism names and syntax move between Laravel versions — Laravel 13 shifted much
job configuration to attributes (`#[Tries]`, `#[Timeout]`, `#[Backoff]`,
`#[MaxExceptions]`, `#[FailOnTimeout]`, `#[UniqueFor]`, `#[WithoutRelations]`,
`#[DebounceFor]`) alongside the older properties and methods, and added `Release` and
`FailOnException` middleware, a `Failover` driver, and SQS fair queues and
deduplication.

**Check what the installed version actually supports** — `composer show laravel/framework`
and <https://laravel.com/framework/docs/queues> — rather than trusting this list. The
failure model above doesn't move; the API does.
