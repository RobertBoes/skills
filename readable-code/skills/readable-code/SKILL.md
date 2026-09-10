---
name: readable-code
description: Write and review code for readability — expressive naming, guard clauses instead of nested conditionals, removing dead code and explanatory comments, avoiding null returns, and consistent symmetry. Use when writing new code, reviewing a diff or PR, or when asked to make code clearer, cleaner, simpler, or less nested. Language-agnostic; examples are PHP.
---

# Readable code

How code reads at the point where you write it: names, control flow, comments,
return values. For *structural* change — splitting a long method, replacing a loop
with a pipeline, introducing an object — see the `refactoring` skill.

## Two premises

1. **We read code far more than we write it.** Readability is the measure. When two
   versions both work, the more readable one wins — not the shorter, the cleverer, or
   the one with more patterns in it.
2. **Most code is more complex than it needs to be.** Start simple and stay simple
   as long as possible. Complexity has to earn its place, and it rarely gets asked to.

When a rule below conflicts with readability, readability wins. These are practices,
not laws. Reducing line count is never the goal; it is occasionally a side effect.

## When to apply

- **Writing new code** — the always-on rules, as you type.
- **Reviewing a diff or PR** — scan for the triggers; report violations as findings,
  naming the practice so the feedback teaches rather than nitpicks.
- **Cleaning up** — work topic by topic: dead code → control flow → naming →
  comments → returns → symmetry. Symmetry last, always.

Boy-scout the code you are already editing. Do not rewrite untouched code nobody
asked about.

## Always-on rules

Fire on sight; no deliberation needed.

**Dead code** — the most visible form of decay, and the one that signals nobody cares.

| Trigger | Action |
|---|---|
| Commented-out code | Delete it. Version control is the archive. If it toggles behavior, use a feature flag. |
| Empty `if` or `else` block | Delete it. An `if` requires no `else`. |
| `break` after `return` in a switch case | Delete — unreachable. |
| Code after an unconditional return | Delete — unreachable. |
| Unused parameter, method, variable, import, or condition | Delete it. If you can't confirm it's unused, say so rather than leaving it silently. |

**Control flow**

| Trigger | Action |
|---|---|
| `if (cond) return true; else return false;` | `return cond;` |
| `else` after a branch that returns | Drop the `else`, de-indent the body. |
| Conditional nesting more than one level deep | Invert into a guard clause (below). |

**Naming**

| Trigger | Action |
|---|---|
| Abbreviated name (`$e`, `$scp`, `$retval`, `$d`) | Expand it. Exception: real conventions like `i` in a `for` loop. |
| Name repeating its context (`orders.order_total`, `filters[].filter`, `QueueManager::queue`) | Drop the redundant part; spend the freed words on a better one. |

**Comments**

| Trigger | Action |
|---|---|
| A comment restating what the next line does | Delete it. If it held information — what a variable contains — fold that into the name. |

**Formatting** — don't hand-format. Run the project's formatter (Pint, PHP-CS-Fixer,
Prettier, gofmt). If the project has none, say so once and move on. Time spent
aligning braces is time not spent on any of the above.

## Control flow: guard clauses

Nested conditionals bury the primary action at the deepest level, and each level is
overhead the reader carries.

To convert: negate the condition (De Morgan for compound ones), return early, then
de-indent the real work.

```php
// before — the actual work is two levels down
public function publish(Post $post)
{
    if ($post->isDraft()) {
        if ($this->user->can('publish', $post)) {
            $post->markPublished();
            event(new PostPublished($post));
        }
    }
}

// after — exceptional paths first, primary action at the top level
public function publish(Post $post)
{
    if (! $post->isDraft() || $this->user->cannot('publish', $post)) {
        return;
    }

    $post->markPublished();
    event(new PostPublished($post));
}
```

Guards usually sit at the top of a method but may appear anywhere. Several
sequential guards are fine and usually better than one compound condition — don't
collapse them into a single dense `return` to save lines. Don't reach for a ternary
to flatten nesting either; it hides complexity rather than removing it.

**`switch` vs `if`.** A `switch` costs four keywords and three levels before it says
anything, and earns that only at roughly a 1:1 ratio of `case` to lines in the case
body. Prefer `if` guards when there are few cases, when case bodies nest, or when a
value must be cast to fit.

## Comments

Challenge every comment's existence. The test is simple:

- A comment describing **what** or **how** is a naming or extraction opportunity.
  Rewrite the code so the comment is unnecessary, then delete it.
- A comment describing **why** — a non-obvious constraint, a workaround, the reason
  an unusual approach was chosen — has earned its place. Keep it, and write it
  properly; English is now its means of communication, so fix the grammar.

Three reasons this matters, all of them Rob Pike's: code with good names already
explains itself; nothing verifies a comment, so it drifts out of step with the code
and eventually lies; and comments are typographic clutter that lowers the signal.

The goal is not zero comments. Docblocks, public API documentation, and licence
headers are separate things. The goal is that inline comments justify themselves.

## Returns and null

`null` communicates nothing, and handling it usually costs more than it saves. It
compounds — one `null` at a low level forces checks all the way up.

1. **Return the type's empty value** — `0`, `''`, `[]` — not `null`. A function
   returning `[]` instead of `null` deletes a check at every call site.
2. **Return a null object** when the absence of an object is a real state. A `Guest`
   that answers `getPreferredName()` lets callers skip the branch entirely.
3. **Fix it at the lowest level first** — that's where the return is highest, because
   it stops the handling from bubbling up.
4. **Make values expressive.** A bare `-1` for not-found or a magic `0` forces
   readers to memorize. Use a named constant, an enum, or a small object.
5. Exceptions carry the same cost as `null`; choose the throwing and catching levels
   deliberately rather than by reflex.

## Reference files

| File | Read when |
|---|---|
| `references/naming.md` | Naming or renaming anything — the rules, the guidelines, and what to do when stuck. |
| `references/symmetry.md` | Doing a final consistency pass, or the code feels uneven without an obvious cause. Apply after everything else. |
| `references/examples.md` | You want the practices worked end to end on real code. |
