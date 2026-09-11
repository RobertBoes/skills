# Payloads and serialization

A job is not a method call. It is an object that gets serialized, JSON-encoded,
written to a store, moved over a network, read back, and reconstructed — possibly
hours later, on a different machine, running different code.

Every design rule about payloads follows from that trip.

## Keep properties simple

```php
// Serializes a whole service object into the payload.
public function __construct(
    private Filesystem $disk,
    private Invoice $invoice,
) {}
```

The filesystem manager is a large object graph. Serializing it is CPU-heavy, it
bloats the stored payload, and it may not survive the round trip at all.

Resolve services inside `handle()` instead — a worker runs every job against one
booted application, so container resolution is cheap:

```php
public function __construct(
    private Invoice $invoice,
) {}

public function handle(Filesystem $disk): void
{
    // $disk resolved at execution; only $invoice is serialized
}
```

Laravel injects `handle()` dependencies from the container, which is the idiomatic
form. Constructor arguments are payload; `handle()` arguments are not.

## Models are stored as references

With the framework's model serialization, an Eloquent model is reduced to a class
name plus an id, and re-fetched when the job runs. Two consequences:

- **The model is re-read at execution time.** Changes made after dispatch are
  visible. That's usually what you want, but it interacts with the
  frozen-at-dispatch decision — if you need the value as it was, pass that value as
  its own property.
- **A deleted model makes the job fail** on deserialization. For jobs that may
  outlive their subject, pass the id and handle the missing case yourself, or use a
  `ModelNotFoundException`-tolerant path.

**Loaded relations are serialized too.** A model with an eager-loaded collection
carries all of it into the payload. Laravel 13's `#[WithoutRelations]` attribute —
per property or on the class — drops them. Worth reaching for whenever you pass a
model you've already eager-loaded for a view.

## Large data goes somewhere else

Pass a reference, not the content:

```php
// Bad: the whole file in the payload.
ProcessUpload::dispatch($request->file('report')->get());

// Good: a path.
ProcessUpload::dispatch($request->file('report')->store('uploads'));
```

SQS caps a message at 256 KB. Redis will accept a much larger payload and quietly
consume memory for it. Neither limit is one you want to discover under load.

## Closures carry more than you think

A queued closure is serialized by storing **the text of its body**, plus every
variable captured with `use`:

```php
dispatch(function () use ($invoice, $settings) {
    // body stored as a string
});
```

The body is signed with the application key so it can be validated on the way back
out. Two practical consequences: captured variables are payload like any other
property, and a closure long enough to be interesting is a closure that should be a
job class. More than a few lines — convert it.

## Chains nest their payloads

```php
Bus::chain([
    new ProvisionServer($plan),
    new InstallDependencies($plan),
    new DeployApplication($plan),
])->dispatch();
```

The second and third jobs are serialized *inside* the first job's payload. A long
chain therefore produces one large first message, and the size compounds if each job
carries meaningful state.

For long chains, dispatch a short one and extend it from within the last job, so no
single payload carries the whole sequence. For genuinely independent work, a batch is
usually the better structure anyway — chains are for ordered steps that must stop on
failure.

## Deploys and payload compatibility

Queued payloads outlive the code that created them. A job sitting in the queue was
serialized by the old code and will be deserialized by the new code.

This makes changing a job's constructor a breaking change:

- **Adding a required property** breaks every queued instance of that job.
- **Renaming or removing a property** does the same.
- **Adding an optional property with a default** is safe.

If you must make an incompatible change, either drain the queue first, or ship it in
two deploys — add the new property with a default, deploy, then start relying on it.
The same reasoning applies to deleting a job class outright: check nothing is still
queued under that name.
