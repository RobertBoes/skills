---
name: refactoring
description: Restructure existing code — decide when duplication is ready to abstract, split long methods by moving code to the right level, replace primitive obsession with objects, and turn accumulating loops into declarative pipelines (filter, map, reduce, flatMap, first). Use when a method is too long, when code is duplicated, when a loop builds up a result, or when asked to refactor, simplify, extract, or clean up structure.
---

# Refactoring

Structural change: moving code, extracting it, and changing its shape. For local
readability — naming, comments, guard clauses, null returns — see the
`readable-code` skill.

Three questions cover most of it:

1. **Is it time yet?** → *Defer until necessary*
2. **Where does this code belong?** → *Extract by level*
3. **What shape should it take?** → *Objects over primitives*, *Loops to pipelines*

## Defer until necessary

Duplication is cheaper than the wrong abstraction. A bad abstraction has to be
maintained *and* later unwound, and you cannot tell it was bad until time passes.

The Rule of Three: write it; on the second occurrence wince but duplicate it anyway;
on the third, you finally have enough data points to see the real pattern — refactor
then.

Three is a heuristic, not a count. The actual rule is: **you will be smarter later,
so defer the decision until it is necessary.** When you do abstract, the right shape
is usually self-evident; if it still isn't obvious, you are early.

In practice this means:

- Never propose an abstraction for code that appears once.
- Don't pre-build for requirements that haven't arrived.
- Duplication that is *visible* is fine. Duplication scattered where nobody can see
  it is the thing to fix — often by extracting it into one place first, *then*
  deciding whether it deserves an abstraction.
- Shipping with known duplication is a legitimate choice. Say it out loud rather
  than quietly accruing it.

## Extract by level

A long method is rarely a problem because of its length. It is a problem because it
mixes altitudes — high-level intent next to low-level detail — forcing the reader to
hold both at once.

Three steps, and the third is only hard if you skip the first two.

**1. Recognize the level.** What is this code's role — controller, model, view,
service, entry point? What does a reader arriving here expect to see? In a controller
they expect request handling and delegation, not password hashing or role-id
mappings. Code pitched above or below the current level is what makes the method long.

**2. Regroup into sub-blocks.** Insert blank lines between related statements and
label each group with a *temporary* comment naming its **action**, in plain words.
That outline exposes the primary action and any duplicated actions, and frees you
from the current implementation. Focus on the action, not on Single Responsibility —
two people will argue about where code belongs but rarely about what a block does.
The comments are scaffolding and all come out by the end.

**3. Refactor each sub-block**, asking two questions in order:

- **Is there a more native way?** Search the codebase, framework, or language, using
  your temporary comment as the search terms. A framework mechanism deletes the
  sub-block outright and teaches you the stack.
- **Does it belong at this level?** If yes and it's duplicated, extract to a private
  method. If yes and it reads fine, leave it. If it's lower-level, push it down to
  the model or a collaborator. If higher-level, push it up to the caller.

Detail reaching through objects it shouldn't know about
(`$order->customer->account->billing->isDelinquent()`) is a reliable signal that code
is at the wrong level — each `->` past the first is a class you've taken a dependency
on without meaning to.

**When stuck**, pseudo-code the ideal flow, turn what you can into real code, and
leave the gap visible. It becomes a fill-in-the-blank instead of a rewrite.

**The conservation law:** unless you found a native alternative, code is never
removed, only moved. Five lines out of one method are five lines into another.
Expecting that keeps the refactor calm and stops you chasing a line count. Not
everything collapses to one line, and shouldn't — a codebase where every method is
one line is exhausting in the other direction.

Worked end to end in `references/extracting-methods.md`.

## Objects over primitives

Primitives are fine until they aren't. Reach for an object when you see:

- **An open-ended options/config array** → a class with named, typed properties, so
  the available options are discoverable and controlled in one place.
- **A data clump** — the same two or three primitives always travelling together
  (`$amount, $currency`; `$min, $max`) → a value object (`Money`, `Range`), immutable
  where the language allows.
- **Logic leaking out of the clump** — `$n >= $r->getMin() && $n <= $r->getMax()`
  repeated at call sites → move it inside: `$range->includes($n)`.

Do not introduce objects where there is no informal structure, no repeated clump, and
no duplicated logic. An object added without one of those makes things worse. The
goal is readability, not object count.

## Loops to pipelines

A loop describes *how* to compute an answer. A pipeline describes *what* the answer
is. The win is that debugging a sequence of simple independent steps is far easier
than debugging one compound loop.

**Fires on** a loop whose body does any of these:

- appends to an array declared just above → **map / filter / flatMap / pluck**
- adds to a running total, string, or accumulator → **reduce / sum / implode**
- `return`s from inside the loop → **first / contains**
- contains another loop → **flatMap**
- is entirely wrapped in an `if` → **filter**

Does **not** fire on a loop performing only side effects with no accumulation.

### The catalog

Names below are Laravel's; the operations are near-universal, but **use whatever the
project's language and libraries call them** — verify before writing:

| Operation | PHP/Laravel | JavaScript | Python | Ruby |
|---|---|---|---|---|
| keep matching | `filter` / `reject` | `filter` | `filter`, comprehension `if` | `select` / `reject` |
| transform each | `map` | `map` | `map`, comprehension | `map` |
| one field of each | `pluck` | `map(x => x.f)` | `attrgetter` | `pluck` / `map(&:f)` |
| flatten one level | `flatMap` | `flatMap` | `chain.from_iterable` | `flat_map` |
| accumulate | `reduce` / `sum` | `reduce` | `reduce`, `sum` | `reduce` / `sum` |
| first match | `first(fn, $default)` | `find` | `next(gen, default)` | `find` / `detect` |
| any match | `contains` | `some` | `any` | `any?` / `include?` |
| bucket by key | `groupBy` | — | `itertools.groupby` | `group_by` |
| pair by index | `zip` | — | `zip` | `zip` |

Two more worth knowing: a chain of `==` against one variable is a membership test
(`contains`), and a `switch` mapping values to values is a lookup table.

If an operation has no equivalent in the project's stack, a plain loop is the right
answer — don't hand-roll a pipeline library to satisfy this skill.

### Untangling a loop that does several things

Don't convert in one step. Turn **"I can't because…"** into **"I could if…"**:

*I can't use `map`, because it would apply to every user rather than only those with
an email* → *I could use `map` **if** I were only working with users that have one.*

That "if" names the step that must come first. Extract it, assign to a temporary
variable, and the original loop gets simpler. Repeat until the loop is gone, then
inline the temporaries into a chain. (The framing is Adam Wathan's.)

### Where to stop

A pipeline is not automatically better than the loop it replaced. Keep the loop, or
break the chain, when:

- **The chain runs past ~5 links.** Extract named helpers and rejoin them with `pipe`
  — a 9-link chain is as unreadable as the loop was.
- **A closure body grows past a couple of lines.** Extract and pass a reference;
  a pipeline of multi-line closures is a loop with extra punctuation.
- **You need genuine early exit** from expensive side-effecting work. `first` covers
  the common case; the rest is a loop.
- **The loop only performs side effects.** `each` buys nothing over `foreach`.
- **The data is large.** Chained `map`/`filter` each allocate; use lazy collections
  or generators.
- **The result is simply less clear.** If the pipeline needs a comment and the loop
  didn't, the loop wins.

An intermediate variable is not a failure — it names a step. Inline it only when the
chain still reads without it.

### Verify signatures, don't trust write-ups

Collection APIs drift. Older material (including the book this section draws on)
shows closures receiving `($key, $value)`; **current Laravel passes `($value, $key)`**.
Examples here use the current order. `pipe` is native in current Laravel, and
`Collection` still uses `Macroable`. Confirm against the version actually installed —
`vendor/laravel/framework/src/Illuminate/Collections/Collection.php` — rather than
trusting any summary, this one included.

Worked pipelines in `references/pipelines.md`.
