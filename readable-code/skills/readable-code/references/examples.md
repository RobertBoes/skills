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
