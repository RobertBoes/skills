# Worked examples

Each runs several practices in sequence, in the order the skill
prescribes. Structural refactors (splitting long methods, loops to pipelines) live
in the `refactoring` skill's references.

---

## 1. Dead code → nesting → naming

Topics: formatting, dead code, control flow, naming.

```php
function chk($t, $u){
  if(Auth::user()->isSuperAdmin()){ return true; }
  else {
    switch($t){
      case 'open':
        return true;
        break;
      case 'owner':
        if(Auth::user()->id === $u)
          return true;
        break;
      // case 'team':
      //   return Auth::user()->onTeam($u);
      default: return false;
    }
    return false;
  }
}
```

**Formatting.** Run the formatter. Do not hand-align anything — this step is free
and it makes the remaining problems visible.

**Dead code.** The commented-out `case 'team'` goes. Both `break` statements follow
`return` and are unreachable — they go. The trailing `return false` after a `switch`
whose every branch returns is unreachable too.

**Nesting.** The first `if` already returns, so the `else` is redundant — drop it
and de-indent. That lifts the `switch` to the top level, where its cost becomes
obvious: two real cases, one of them nesting, and the primary action hiding in
`default`. Convert to guard clauses.

**Naming.** `chk` → `canAccess`. `$t` → `$scope`. `$u` → `$ownerId`.

```php
function canAccess(string $scope, int $ownerId): bool
{
    if (Auth::user()->isSuperAdmin()) {
        return true;
    }

    if ($scope === 'open') {
        return true;
    }

    if ($scope === 'owner' && Auth::user()->id === $ownerId) {
        return true;
    }

    return false;
}
```

Three guards, all at the top level, then the primary action. Resist collapsing them
into one compound `return` — density is not readability. (`$scope` as a raw string is a further
opportunity — an enum would make the values expressive. Defer it until there is a
reason.)

---

## 2. Comments → naming → extraction

Topics: comments, naming, extraction.

```php
// get orders placed this month for the customer
public function recent($c)
{
    // first day of the current month
    $d = date('Y-m-01');

    // array of orders to return
    $retval = [];

    // the paginated endpoint is used because the bulk one
    // caps out at 100 and silently truncates
    $orders = $this->client->paginate('orders', ['customer' => $c]);

    // loop the orders and keep the ones from this month
    foreach ($orders as $order) {
        if ($order->placed_at >= $d) {
            $retval[] = $order;
        }
    }

    // return the orders
    return $retval;
}
```

**Challenge every comment.** `// return the orders` restates the code — delete it.
The method comment restates the signature — delete it, and fold what it said into
the method name.

**Comments that hold information become names.** `// first day of the current month`
and `// array of orders to return` describe what the variables hold. That belongs in
the names: `$d` → `$firstOfMonth`, `$retval` → `$ordersThisMonth`. `$c` → `$customerId`.

**Use proximity.** Renaming leaves the variables floating far from their use. Move
them down to where they are used; the comment was partly doing that work.

**The loop comment is more succinct than the code.** That is an extraction signal —
pull the loop out and let a name carry the summary. (See the `refactoring` skill.)

**One comment survives.** The `paginate` comment explains *why*, not what. It cannot
be expressed in code. Keep it — and fix its grammar, since English is now its
primary means of communication.

```php
public function ordersThisMonth(int $customerId): array
{
    // The paginated endpoint is used because the bulk one caps at 100
    // results and truncates silently.
    $orders = $this->client->paginate('orders', ['customer' => $customerId]);

    return $this->placedSince($orders, date('Y-m-01'));
}
```

The rule of thumb: a comment describing **what** or **how** is a naming or
extraction opportunity. A comment describing **why** has earned its place.

---

---

## 3. Test code is code

Topics: control flow, naming, returns, plus loops-to-pipelines from the `refactoring`
skill. This one is a real Pest helper, written mid-debugging by someone who had just
finished applying these rules to somebody else's code.

```php
function badgedTeamNames(string $html): array
{
    preg_match_all(badgePattern(), $html, $badges, PREG_OFFSET_CAPTURE);
    $teamNames = Team::query()->pluck('name')->all();
    $badged = [];
    foreach ($badges[0] as [$_, $badgePosition]) {
        $closest = null;
        $closestPosition = -1;
        foreach ($teamNames as $name) {
            $position = strpos($html, (string) $name);
            if ($position !== false && $position < $badgePosition && $position > $closestPosition) {
                $closest = $name;
                $closestPosition = $position;
            }
        }
        if ($closest !== null) { $badged[] = $closest; }
    }
    return $badged;
}
```

It passed the formatter, the static analyser, and the test suite. Six rules fire
anyway:

| # | Rule | Where |
|---|---|---|
| 1 | Loop appending to an array declared just above → `map` | `$badged = []` and the outer `foreach` |
| 2 | Inner loop is a filter plus a max-by → `filter` + `sortDesc` + `first` | the whole inner `foreach` |
| 3 | Magic sentinel value | `$closestPosition = -1` |
| 4 | Abbreviated name | `$_` in the destructure |
| 5 | Nesting past one level | the `if` inside the inner `foreach` |
| 6 | Invariant recomputed in a loop | `strpos($html, $name)` doesn't depend on the badge, but runs once per badge per team |

**Hoist the invariant first.** `strpos($html, $name)` is the same on every pass —
compute the positions once, and the `!== false` check disappears from the hot path
at the same time:

```php
$namePositions = Team::query()->pluck('name')
    ->mapWithKeys(fn (string $name) => [$name => strpos($html, $name)])
    ->filter(fn (int|false $position) => $position !== false);
```

**Name what you're iterating.** `$badges[0]` under `PREG_OFFSET_CAPTURE` is a list of
`[match, offset]` pairs, and only the offset is wanted — so pluck it and the `$_`
goes away with the destructure.

**The inner loop is "the latest position still before the badge."** Said that way it
is a filter and a max-by, which extracts cleanly into a named function:

```php
function badgedTeamNames(string $html): array
{
    preg_match_all(badgePattern(), $html, $badges, PREG_OFFSET_CAPTURE);

    $namePositions = Team::query()->pluck('name')
        ->mapWithKeys(fn (string $name) => [$name => strpos($html, $name)])
        ->filter(fn (int|false $position) => $position !== false);

    return collect($badges[0])
        ->pluck(1)
        ->map(fn (int $badgePosition) => nameNearestBefore($namePositions, $badgePosition))
        ->filter(fn (?string $name) => $name !== null)
        ->values()
        ->all();
}

function nameNearestBefore(Collection $namePositions, int $badgePosition): ?string
{
    return $namePositions
        ->filter(fn (int $position) => $position < $badgePosition)
        ->sortDesc()
        ->keys()
        ->first();
}
```

The sentinel is gone because `sortDesc()->keys()->first()` expresses "the largest"
directly, rather than simulating it with a running comparison seeded at an impossible
value.

**One honest tension.** `nameNearestBefore` returns `?string`, which the returns rule
would normally push back on. Here the absence is real — a badge genuinely may have no
preceding name — and the caller filters it out immediately, one line later. That is
null being handled at the level where it arises instead of propagating, which is what
the rule is actually protecting. Returning `''` to dodge the `?` would be worse.

**The lesson is not the six rules.** It is that this was written an hour after
applying the same rules to someone else's code, by someone who had mentally filed
them under "the audit task" and filed this under "just a test helper." Both filings
were wrong.
