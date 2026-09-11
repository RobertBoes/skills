# Idempotency and where truth lives

## Why this is mandatory, not defensive

The usual mental model is "my job reruns only if it throws." That's wrong. A worker
counts an attempt as failed when it cannot *record* success — a crash, a lost
connection to the queue store, or an OOM kill between `handle()` returning and the
job being marked complete.

So the dangerous case is the one where **your code did exactly what it was supposed
to, and runs again anyway**. No amount of exception handling inside `handle()`
prevents it. The only defence is making a second run harmless.

## The three guards, weakest to strongest

### 1. A local flag

```php
public function handle(): void
{
    if ($this->shipment->dispatched_at !== null) {
        return;
    }

    $this->carrier->createShipment($this->shipment);

    $this->shipment->update(['dispatched_at' => now()]);
}
```

Better than nothing, and it catches the common case. But look at the window: if the
carrier accepts the shipment and the process dies before the `update()`, the flag is
still null and the retry books a second shipment. The guard is checking *your*
record of the side effect, not the side effect.

That window is small, which is exactly what makes it dangerous — it will not show up
in testing, and it will show up in production eventually.

### 2. Ask the source of truth

The carrier knows whether the shipment exists. You don't.

```php
public function handle(): void
{
    if ($this->carrier->findShipment($this->shipment->reference) !== null) {
        return;
    }

    $this->carrier->createShipment($this->shipment);
}
```

This closes the window, at the cost of an extra call on every run. Two things to
watch: the lookup needs a stable reference you generated *before* the first attempt
(an id the carrier assigns is no use — you won't have it after a crash), and the
lookup itself can fail, so it needs the same care as the write.

### 3. An idempotency key

The strongest guard, when the service supports it. You generate a key once, send it
with every attempt, and the remote side collapses duplicates:

```php
public function handle(): void
{
    $this->carrier->createShipment(
        $this->shipment,
        idempotencyKey: $this->shipment->reference,
    );
}
```

The key must be **stable across retries** — derive it from the job's own data, not
from `Str::uuid()` inside `handle()`, which generates a fresh one per attempt and
defeats the entire mechanism. Most payment and logistics APIs support this; check
before hand-rolling a check-then-act.

## When the provider offers nothing

Some APIs have no idempotency key and no way to look up by your reference. Options,
in order:

1. **Make your own key and store it before acting.** Write an `attempts` row with a
   unique constraint on the reference, commit, then act. The unique constraint turns
   a duplicate run into a database error rather than a duplicate side effect.
2. **Lock around the whole operation** with `WithoutOverlapping` keyed on the
   resource, so at least concurrent copies can't interleave. This does *not* protect
   against sequential retries after a crash — only against parallel ones.
3. **Accept and detect.** If duplicates are cheap to reverse, reconcile after the
   fact with a scheduled job rather than trying to prevent them. Sometimes the honest
   answer.

Say which one you chose in a comment. The next person will wonder.

## Idempotency is not all-or-nothing

A job doing several things needs each one considered separately:

```php
public function handle(): void
{
    $this->carrier->createShipment(...);     // must not repeat
    Mail::to($customer)->send(...);          // repeating is mildly annoying
    Log::info(...);                          // repeating is fine
}
```

If the mail send throws, the retry re-runs the shipment call too. **A job that mixes
a must-not-repeat action with anything failure-prone should be split**, with the
fragile part in its own job — chained after, or dispatched from within. Ordering the
riskiest operation last is a weaker version of the same idea and sometimes enough.

## Concurrency is a separate problem

Idempotency protects against *sequential* reruns. It does nothing for two copies
running simultaneously, where both can pass the same guard before either acts:

```
worker A: findShipment() -> null
worker B: findShipment() -> null      <- both saw nothing
worker A: createShipment()
worker B: createShipment()            <- two shipments
```

For that you need a lock (`WithoutOverlapping` keyed on the resource id) or a unique
constraint in the database. Decide which of the two problems you have — usually both.

## Testing this

The failure mode never appears in a normal test run, so provoke it:

- Call `handle()` twice in a row and assert exactly one side effect.
- Call it twice with the fake/mock configured to succeed on the remote call but throw
  afterwards, simulating a crash mid-job.
- For concurrency, assert the lock or unique constraint exists rather than trying to
  race it in a test.

These are cheap tests and they document the intent, which matters when someone later
"simplifies" the guard away.
