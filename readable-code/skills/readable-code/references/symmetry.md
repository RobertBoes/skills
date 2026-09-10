# Symmetry

> Symmetry in code is where the same idea is expressed the same way everywhere it
> appears. — Kent Beck, *Implementation Patterns*

Symmetry underlies every other readability practice. It is also the slowest to
master and the most codebase-dependent. **Apply it last**, after formatting, dead
code, nesting, naming, big blocks and returns — it works on code that is already
clean, and it shows you the remaining rough edges.

Work the three levels in order. Each pass makes the next one's problems visible.

## 1. Syntactic symmetry

The same shapes everywhere.

- Brace style, spacing, indentation — consistent. (The formatter handles this.)
- Structural conventions — declarations at the top, methods grouped by visibility,
  consistent parameter order across a family of functions. (PHP's own
  `array_filter($input, $callback)` vs `array_map($callback, $input)` is the
  cautionary example.)
- Operators — pick `&&` or `and` and use it everywhere.
- **Consistent abstraction level within a block.** `input(); incrementCount(); output();`
  is asymmetric — two abstractions and one implementation detail. So is a block of
  method-call conditions with one raw `$this->plan === Plan::TRIAL` among them.
- **Group returns.** In a run of guard clauses, put all the `return false` checks
  together, then all the `return true` ones. It gives the reader a clear division
  between guards and early-return optimizations.

## 2. Semantic symmetry

The same meaning expressed the same way.

- **Pair names properly.** `on`/`off`, not `on`/`disable`. `connect`/`disconnect`.
  `bind`/`unbind`. If you inject a word or pattern into one name, inject it into all
  of them in scope.
- **Unify synonyms.** `last`, `latest`, and `previous` used interchangeably in one
  class is noise. Pick one term and one tense; use it everywhere unless the domain
  genuinely distinguishes them.
- **Be consistent about prefixes.** `get`/`set`, or neither. `is`/`can`/`has` for
  booleans, or not. Either choice is fine; mixing is not.
- **Keep one tone.** Mixed positive and negative conditions force the reader to
  invert logic mid-flow. Prefer the positive form:
  `!$this->enabled` → `$this->disabled()`,
  `!$account->hasValidPaymentMethodOrIsExempt()` → `$account->needsPaymentMethod()`.
- Names that are *too* rich are also asymmetric — `jobHasAlreadyBeenQueuedOrIsRunning`
  inside `Job` can be `queuedOrRunning`. Balance human readability against context
  (see `naming.md`).

## 3. Systemic symmetry

The same ideas expressed the same way across the whole codebase.

- **Consistent abstraction levels** — a reader should be able to open any file and
  find the same altitude of code where they expect it.
- **Stop playing design pattern bingo.** Given a choice between a new pattern and
  one already in the codebase, symmetry favors reuse. Too many patterns prevents
  anyone from getting a feel for the application.
- Asymmetric abstractions usually point at another practice: something wants to be
  an object (`Using Objects`) or wants to move levels (`Big Blocks`).

## The feedback loop

Symmetry compounds. Each pass makes the next asymmetry obvious — a shape you could
not have seen at the start emerges as the code gets more consistent.

A typical chain: grouping the returns exposes one condition that is a raw comparison
rather than a method call; inverting the negations removes an implementation detail;
abstracting the last compound condition removes both a null-guard wrapper (an
`optional()`-style helper, or a `?->` chain) and a hidden temporal dependency, since
the order of the guards stops mattering.

## When to stop

Symmetry has no completion state. Stop when a reader can flow through the code
without stumbling. Remaining imperfections — one chained call, one method taking an
argument where the neighbors take none — are worth *noting*, not necessarily fixing.
