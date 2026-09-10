# Naming

Naming is hard mostly when you force it. Two things make it easy: **context** and
**time**. Context points you at a vocabulary. Time lets the right word surface.
Forcing context produces overly technical names; forcing time produces stress.

## Rules

These are firm. Following them clears the easy cases so attention goes to the names
that actually need thought.

### Avoid abbreviations

There was a reason for `$scp`, `$retval`, `$e`, `$func`, `$d`, `$perm` — character
budgets and slow typing. Neither exists now. IDEs complete; readers do not.

```php
$exp = 3600;
Cache::put($k, $v, $exp);
```

A reader can reconstruct that, but why make them? Across a whole codebase, that
reconstruction is real mental cost with nothing bought.

Exception: established conventions (see below).

### Follow conventions

Decades of programming have produced conventions. Honor them — they are shared
vocabulary across codebases and languages.

`i` in a `for` loop is a convention, not an abbreviation. Do not rename it to
`counter` or `lcv`. Same for language-level conventions: `I`-prefixed interfaces in
.NET, class prefixes in Objective-C. Adopt them even when they feel alien; they
help readers move between codebases.

Know the boundary. `i`, `j`, `k`, `l` for nested loops has left convention behind —
that is a signal to apply *Nested Code* or *Big Blocks* instead.

A convention is formalized by official language documentation or appears across
languages. A habit inside one codebase is not a convention.

### Leverage context

Each word in a name has a cost. You have a budget. Words that repeat information
already available from the surroundings waste it.

```
orders table:  order_status, order_total, order_placed_at  →  status, total, placed_at
{"filters": [{"filter": "status", "value": "open"}]}       →  {"filters": [{"field": "status", …}]}
arrayFlatten($items)                                       →  flatten($items)
```

The column is already in `orders`. The property is already inside a `filters` entry.
The function already takes and returns an array. Spend the freed budget on a better
word — `total` can become `total_excluding_tax` if that is what it actually is.

Watch for three forms: repeating the parent/container name, injecting the type, and
repeating the class name in its own method (`QueueManager::queue()`).

## Guidelines

Softer, and they vary by codebase and language.

### Human readable

Names carry the human signal in a wall of syntax. Keywords and technical terms
(`client`, `service`, `handler`, `manager`) tell the reader almost nothing they
couldn't infer. Let the reader — who is technical — infer the technical parts, and
spend names on what only you know.

Aim for code that reads as a sentence. Read it aloud. If you have to insert words
to make it a sentence, the names are not there yet.

### Express the domain

The best names come from the vocabulary the team already uses out loud about the
project. In a hospital rostering system, `Shift`, `Ward` and `cover()` beat `Assignment`,
`Location` and `process()`. In a courier system, `Dispatcher::assign($delivery,
$courier)` beats `QueueManager::handle($item, $worker)`.

A dictionary or thesaurus is a legitimate tool here — once you have the domain
word, related words come with it.

### Background processing

Do not force the perfect name on the first try.

- If you are stuck, use a **temporary name** — a long one. A full sentence is fine:
  `createUserIfUnauthenticated`. It is not ridiculous; it gives your brain keywords
  to chew on, and you will often return with the right name already formed.
- The best names commonly take two or three iterations, sometimes needing more of
  the application to exist before the domain vocabulary is rich enough.
- When context changes, **rename**. Names are not permanent, and the point is to
  keep code and real world tightly coupled.

## Worked example

```php
class QueueManager
{
    public function proc($m, $fn) {}
    public function remove($m) {}
}
```

1. Expand abbreviations → `proc($message, $function)`, `remove($message)`
2. Follow conventions → in a queue, the thing that runs on a message is a `$handler`,
   not a generic `$function`
3. Leverage context → `QueueManager::proc` restates its own class → `process($message, $handler)`
4. Read it as a sentence → "a QueueManager processes a message and a handler" — the
   words had to be bent to fit, and `remove` doesn't pair with `process`. Pair them:
   `accept` / `discard`
5. Express the domain → this is a courier dispatch system, not a generic queue.
   `Dispatcher::accept($delivery, $courier)`
6. Enrich from the domain vocabulary → the words a dispatcher actually uses:

```php
class Dispatcher
{
    public function assign(Delivery $delivery, Courier $courier) {}
    public function recall(Delivery $delivery) {}
}
```

Steps 1–3 are the rules and are close to mechanical. Steps 4–6 are the guidelines and
take judgment — most people stop after step 3, which is defensible, but the last
three steps are where the readability actually comes from. Note that step 4 also
bought symmetry: `assign`/`recall` are proper opposites in a way `process`/`remove`
never were.
