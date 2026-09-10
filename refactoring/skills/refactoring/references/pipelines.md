# Worked pipelines

Examples use current Laravel Collection signatures — closures receive
`($value, $key)`. Verify against the version installed in the project before copying
a signature.

---

## 1. The full refactor: filter → flatMap → sum

**Problem.** Total the tax across every line item of every unsettled invoice for a
customer. Invoices carry a `status` and a list of `lines`; the lines carry the tax.

```php
$totalTax = 0;

foreach ($invoices as $invoice) {
    $status = $invoice['status'];

    if ($status == 'unpaid' || $status == 'overdue') {
        foreach ($invoice['lines'] as $line) {
            $totalTax += $line['tax'];
        }
    }
}

return $totalTax;
```

**Step 1 — the `if` wraps the whole body, so it's a filter.** Hoisting it out
deletes the conditional entirely:

```php
$unsettled = $invoices->filter(function ($invoice) {
    return $invoice['status'] == 'unpaid' || $invoice['status'] == 'overdue';
});
```

**Step 2 — a chain of `==` against one variable is a membership test.** `contains`
says that directly, and unlike `in_array` there's no ambiguity about argument order:

```php
$unsettled = $invoices->filter(
    fn ($invoice) => collect(['unpaid', 'overdue'])->contains($invoice['status'])
);
```

**Step 3 — the nested loop is a flatMap.** We want to work with lines, but we hold
invoices. Mapping each invoice to its lines gives a collection *of arrays*;
`flatten(1)` collapses it, and the two together are `flatMap`:

```php
$lines = $unsettled->flatMap(fn ($invoice) => $invoice['lines']);
```

**Step 4 — plucking one field and summing is just `sum`.** `sum` accepts a
pluck-style key, so the last two steps collapse:

```php
return $invoices
    ->filter(fn ($invoice) => collect(['unpaid', 'overdue'])->contains($invoice['status']))
    ->flatMap(fn ($invoice) => $invoice['lines'])
    ->sum('tax');
```

Three named operations, no accumulator, no nesting. A 3-link chain with
single-expression closures — comfortably inside the limit.

---

## 2. Replace a switch with a lookup table

```php
$weights = $tickets->map(function ($ticket) {
    switch ($ticket['type']) {
        case 'outage':     return 10;
        case 'billing':    return 5;
        case 'bug':        return 3;
        case 'question':   return 1;
        default:           return 1;
    }
});
```

Almost any `switch` mapping a value to a value is an associative array in disguise —
the `case` becomes the key. But a plain array loses the default, and guarding with
`isset` is no better than the `switch`:

```php
if (! isset($weights[$type])) {
    return 1;
}

return $weights[$type];
```

That *asks* the table a question about itself before acting. **Tell, don't ask** —
`Collection::get()` takes the default directly:

```php
private const TYPE_WEIGHTS = [
    'outage'   => 10,
    'billing'  => 5,
    'bug'      => 3,
    'question' => 1,
];

$weights = $tickets->map(
    fn ($ticket) => collect(self::TYPE_WEIGHTS)->get($ticket['type'], 1)
);
```

Hoist the table to a constant or config value — building it inside the closure
rebuilds it once per item.

---

## 3. Replace iteration with `first` — and what to return on no match

The search loop:

```php
private function rendererFor($report)
{
    foreach ($this->renderers as $renderer) {
        if ($renderer->supports($report)) {
            return $renderer;
        }
    }
}
```

`first` with a closure is a "first where":

```php
private function rendererFor($report)
{
    return $this->renderers->first(fn ($renderer) => $renderer->supports($report));
}
```

The companion "do we have one?" loop is the same shape returning a boolean, which is
`contains`:

```php
private function hasRendererFor($report)
{
    return $this->renderers->contains(fn ($renderer) => $renderer->supports($report));
}
```

**Now look at the pair.** Callers must call `hasRendererFor()` before
`rendererFor()` — an unwritten rule, and a smell. `first` returns `null` on no
match, so the `null` leaks outward. Three options, worst to best:

1. **Return `null`.** Every caller must guard. That's the problem, not a fix.
2. **Throw.** Better than a silent `null` — right when "no match" is genuinely a bug.
3. **Return a null object.** `first` takes a default as its second argument, so hand
   it something satisfying the same interface that does nothing:

```php
private function rendererFor($report)
{
    return $this->renderers->first(
        fn ($renderer) => $renderer->supports($report),
        new NullRenderer(),
    );
}
```

`NullRenderer::render()` returns an empty collection. `hasRendererFor()` disappears,
the unwritten rule goes with it, and callers have no branch left:

```php
private function render($report)
{
    return new RenderedReport($report, $this->rendererFor($report)->render($report));
}
```

An **empty collection is itself a null object** — `each` over it runs nothing, `map`
returns empty, `sum` returns 0. Returning one instead of `null` deletes the caller's
guard for free.

---

## 4. Breaking a long chain without breaking the pipeline

A monthly revenue report that fills gaps for months with no sales, then computes
month-over-month growth, then orders the result, runs well past five links. The
steps cluster into two domain concepts, but neither deserves to be a general
collection method:

```php
function monthlyReport($sales)
{
    return collect($sales)
        ->pipe(fn ($months) => fillMissingMonths($months))
        ->pipe(fn ($months) => addGrowthRates($months))
        ->sortBy('month');
}

function fillMissingMonths($months)
{
    return $months->groupBy('month')
        ->map(fn ($rows) => ['month' => $rows->first()['month'], 'total' => $rows->sum('total')])
        ->union(emptyMonthsForPeriod($months));
}

function addGrowthRates($months)
{
    return $months->sortBy('month')->values()
        ->zip($months->sortBy('month')->values()->skip(1))
        ->map(function ($pair) {
            [$previous, $current] = $pair;

            return $current === null
                ? $previous
                : array_merge($current, ['growth' => growthBetween($previous, $current)]);
        });
}
```

Each function is a readable short chain, the top level reads as three named steps,
and no intermediate variables are needed. This is the tool for "the chain got too
long" — reach for it before accepting a 9-link chain *or* falling back to a loop.
