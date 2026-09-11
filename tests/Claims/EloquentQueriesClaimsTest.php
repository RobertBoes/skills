<?php

namespace Skills\ClaimChecks;

use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder as EloquentContract;
use Illuminate\Contracts\Database\Query\Builder as QueryContract;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Skills\ClaimChecks\Stubs\OnlyActive;
use Skills\ClaimChecks\Stubs\Widget;

/**
 * Claims made by: eloquent-queries/skills/eloquent-queries/
 */
class EloquentQueriesClaimsTest extends TestCase
{
    #[Test]
    public function the_query_builder_contract_is_the_widest_hint_for_a_tappable_scope(): void
    {
        // SKILL.md: "Type-hint Illuminate\Contracts\Database\Query\Builder, not the
        // Eloquent builder — the contract also covers query and relation builders."
        $this->assertTrue(interface_exists(QueryContract::class));
        $this->assertTrue(is_subclass_of(EloquentContract::class, QueryContract::class));

        foreach ([EloquentBuilder::class, QueryBuilder::class, Relation::class] as $class) {
            $this->assertTrue(
                is_subclass_of($class, QueryContract::class),
                "{$class} no longer satisfies the query builder contract, which the pinned scope shape depends on",
            );
        }
    }

    #[Test]
    public function tap_discards_the_callbacks_return_value(): void
    {
        // references/tappable-scopes.md: "The return value is discarded... Return void
        // so the contract is honest."
        $builder = Widget::query();

        $this->assertSame($builder, $builder->tap(fn () => 'a value tap should ignore'));
    }

    #[Test]
    public function when_consumes_the_callbacks_return_value(): void
    {
        // references/tappable-scopes.md: "when() — unlike tap() — does consume the
        // return value, so a scope returning something else would hijack the chain."
        $builder = Widget::query();

        $this->assertSame('hijacked', $builder->when(true, fn () => 'hijacked'));
    }

    #[Test]
    public function where_has_requires_a_closure_not_any_callable(): void
    {
        // references/tappable-scopes.md: "whereHas type-hints ?Closure, so the object
        // has to be turned into one" — hence the first-class callable syntax.
        $parameter = (new ReflectionMethod(EloquentBuilder::class, 'whereHas'))->getParameters()[1];

        $this->assertSame(Closure::class, (string) $parameter->getType()->getName());
    }

    #[Test]
    public function eager_load_constraints_accept_a_plain_invokable_object(): void
    {
        // references/tappable-scopes.md: "with() funnels every constraint through
        // combineConstraints(), which wraps it in a closure."
        $eagerLoads = Widget::with(['parts' => new OnlyActive])->getEagerLoads();

        $this->assertArrayHasKey('parts', $eagerLoads);
        $this->assertInstanceOf(Closure::class, $eagerLoads['parts']);
    }

    #[Test]
    public function local_scopes_are_declared_with_the_scope_attribute(): void
    {
        // SKILL.md version notes: the scopeXxx prefix is legacy.
        $this->assertTrue(
            class_exists(Scope::class),
            'Illuminate\Database\Eloquent\Attributes\Scope is gone; the version notes need rewriting',
        );
    }
}
