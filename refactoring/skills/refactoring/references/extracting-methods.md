# Extracting methods by level

A long method is not a problem because of its length. It is a problem because it
mixes levels — high-level intent sitting next to low-level implementation detail —
and forces the reader to hold all of it at once.

Most people jump straight to extracting methods. That step is hard on its own and
easy when the two steps before it are done first.

## Step 1 — Recognize the level

Determine the *reading level* of the code from its context: where it lives and what
its role is. A controller is an entry point — it handles a request, delegates, and
returns a response. A model owns persistence and domain rules. A view owns output.

Then ask what a reader arriving here expects to see. In a controller they expect
request/response handling and delegation. They do *not* expect password hashing,
role-id mappings, or query building. Code pitched above or below the current level
is the extra code making the block big.

For a callback or event handler with no obvious level of its own, use the level of
whatever calls it.

## Step 2 — Regroup into sub-blocks

Add blank lines between groups of related statements, and label each group with a
**temporary** comment describing its *action*, in plain words:

```php
// ensure the request is valid
// find the subscription by id
// check the caller may cancel it
// tell the billing provider to cancel
// write the cancellation reason to our records
// email the account owner
// redirect back to the billing page
```

This is an outline of what the method does. It reveals the primary action, exposes
duplicate actions across branches, and — importantly — frees you from the current
implementation, which otherwise acts as a box you keep thinking inside.

Focus on the *action*, not on Single Responsibility. Two programmers will disagree
about where code belongs; they rarely disagree about what a block does. Action is
the more concrete question.

The comments are scaffolding. They all come out by the end.

## Step 3 — Refactor each sub-block

Walk the sub-blocks top to bottom. For each, ask two questions in order:

**1. Is there a more native way to do this?**

Search the codebase, framework, or language for the action, using the words from your
temporary comment as the search terms. In a Laravel app the first three comments above
each dissolve this way: "request validation" → a Form Request, "find model by id" →
route model binding, "check the caller may" → a Policy plus `authorize()`. Three
sub-blocks gone without writing any code.

A native mechanism removes the sub-block entirely and teaches you the framework. This
is the highest-value question in the process — ask it before you reach for extraction.

**2. Does this action belong at the current level?**

- **Yes, and it's duplicated** → extract to a private method in the same class.
  The reader gets a named summary instead of re-reading the same lines, and the
  duplication is now exposed in one place.
- **Yes, and it reads fine** → leave it.
- **No, it's lower-level** → move it down, to the model or a collaborator. Talking to
  the billing provider's SDK belongs behind `Subscription::cancel()`, not in a
  controller. Password hashing belongs in a factory method on the model, not in
  `store()`.
- **No, it's higher-level** → move it up to the caller.

Low-level detail reaching through a class it shouldn't know about ("inappropriate
intimacy", e.g. `$order->customer->account->billing->isDelinquent()`) is a signal the
code belongs at a different level.

## When you get stuck

Pseudo-code the ideal flow — usually just the primary actions restated:

```php
public function destroy(CancelRequest $request, Subscription $subscription)
{
    // cancel the subscription
    // redirect back to billing
}
```

Turn what you can into real code and leave the gap visible:

```php
$subscription->cancel(/* the reason, from somewhere */);

return redirect(route('billing.show', $subscription->account));
```

Now it is a fill-in-the-blank, not a rewrite. The blank becomes a well-named helper.

## The conservation law

Unless you found a native alternative, **code is never removed, only moved**. Five
lines out of one method are five lines into another. Expecting this makes the
refactor calmer and stops you from chasing a line count.

And not everything collapses to one line. Nor should it. A codebase where every
method is one line is exhausting in the opposite direction — the reader bounces
around reassembling the pieces.


---

## Worked example: recognize → regroup → refactor

Topics: extraction by level, deferring abstraction, avoiding null.

```php
class SubscriptionController extends Controller
{
    public function store(Request $request)
    {
        Validator::make($request->all(), [
            'plan' => 'required|string',
            'token' => 'required|string',
        ])->validate();

        $team = Team::find($request->input('team_id'));
        if ($team === null) {
            abort(404);
        }

        if ($team->subscription_id !== null) {
            $sub = Billing::retrieve($team->subscription_id);
            $sub->plan = $request->input('plan');
            $sub->save();
        } else {
            $customer = Billing::createCustomer($team->owner->email, $request->input('token'));
            $sub = Billing::subscribe($customer->id, $request->input('plan'));
            $team->subscription_id = $sub->id;
            $team->save();
        }

        Mail::to($team->owner)->send(new SubscriptionConfirmed($team));

        return redirect(route('billing.show', $team));
    }
}
```

**Recognize the level.** A web controller. Readers expect request handling,
delegation, and a response. They do not expect billing-provider mechanics or
`subscription_id` bookkeeping.

**Regroup.** Blank lines and temporary comments:

```
// ensure the request is valid
// find the team or 404
// update the existing subscription
// or create a customer and subscribe
// notify the owner
// redirect to billing
```

The outline reveals the primary action — *subscribe the team to a plan and show
billing* — and shows the branch differs only in how it reaches the same end state.

**Refactor.**

- *Native way?* Validation → a Form Request. Find-or-404 → route model binding.
  Two sub-blocks gone with no code written.
- *Right level?* The billing branch is entirely low-level. It belongs behind one
  method that expresses intent, not mechanics. `Team::subscribeTo($plan)` reads at
  the controller's level and hides `Billing::` and `subscription_id` where they
  belong.
- *Right level?* The mail send and the redirect are response-adjacent and fine here.

```php
class SubscriptionController extends Controller
{
    public function store(SubscriptionRequest $request, Team $team)
    {
        $team->subscribeTo($request->plan(), $request->token());

        Mail::to($team->owner)->send(new SubscriptionConfirmed($team));

        return redirect(route('billing.show', $team));
    }
}
```

**Note what did not happen.** The branch inside `subscribeTo` was not abstracted into
a strategy, and no `SubscriptionService` was introduced. There is one call site.
*Defer until necessary* says wait — the right seam will be obvious once there is a second and
third one, and it will probably not be the one you would have guessed today.

**Note the return.** `Team::find()` returning `null` forced an explicit abort. Route
model binding removes the `null` from the flow entirely. That is the null-avoidance rule at work — the cheapest `null` to handle is the one that never reaches you.
