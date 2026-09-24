<?php

namespace Skills\ClaimChecks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use PHPUnit\Framework\Attributes\Test;
use Skills\ClaimChecks\Stubs\Widget;

/**
 * Claims made by: laravel-audit/skills/laravel-audit/references/query-performance.md
 */
class LaravelAuditClaimsTest extends TestCase
{
    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);
        Model::handleLazyLoadingViolationUsing(null);

        parent::tearDown();
    }

    #[Test]
    public function the_violation_exception_lives_outside_the_eloquent_namespace(): void
    {
        // "Note the namespace: Illuminate\Database\LazyLoadingViolationException,
        // not Illuminate\Database\Eloquent\."
        $this->assertTrue(class_exists(LazyLoadingViolationException::class));
        $this->assertFalse(class_exists('Illuminate\Database\Eloquent\LazyLoadingViolationException'));
    }

    #[Test]
    public function the_handler_receives_the_model_the_relation_name_and_the_exception(): void
    {
        // "Since Laravel 13.32 the handler receives three arguments — the model, the
        // relation name and a pre-built LazyLoadingViolationException."
        $received = [];

        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing(function (...$arguments) use (&$received): void {
            $received = $arguments;
        });

        $widget = Widget::hydrate([['id' => 1], ['id' => 2]])->first();
        $widget->parts;

        $this->assertCount(
            3,
            $received,
            'The handler signature changed; query-performance.md documents three arguments since Laravel 13.32',
        );
        $this->assertInstanceOf(Widget::class, $received[0]);
        $this->assertSame('parts', $received[1]);
        $this->assertInstanceOf(LazyLoadingViolationException::class, $received[2]);
    }

    #[Test]
    public function the_documented_two_parameter_handler_still_runs(): void
    {
        // "The snippet above declares two and builds the exception itself, which
        // works on both." Extra arguments to a closure are dropped, not an error.
        $violation = null;

        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) use (&$violation): void {
            $violation = new LazyLoadingViolationException($model, $relation);
        });

        $widget = Widget::hydrate([['id' => 1], ['id' => 2]])->first();
        $widget->parts;

        $this->assertInstanceOf(LazyLoadingViolationException::class, $violation);
    }

    #[Test]
    public function the_check_is_only_armed_for_queries_returning_more_than_one_row(): void
    {
        // "Builder::hydrate arms the check only on models hydrated from a query that
        // returned more than one row... A test written with a one-row fixture passes
        // regardless. Use two rows."
        Model::preventLazyLoading();

        $fromOneRow = Widget::hydrate([['id' => 1]])->first();
        $fromTwoRows = Widget::hydrate([['id' => 1], ['id' => 2]])->first();

        $this->assertFalse($fromOneRow->preventsLazyLoading, 'A single-row fixture would silently pass any lazy-loading test');
        $this->assertTrue($fromTwoRows->preventsLazyLoading);
    }

    #[Test]
    public function registering_a_handler_replaces_the_default_guard(): void
    {
        // "Registering a handler replaces the default behaviour entirely, including its
        // guard — by default a violation on a model that doesn't exist yet is ignored."
        $called = false;

        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing(function () use (&$called): void {
            $called = true;
        });

        $unsaved = new Widget;
        $unsaved->preventsLazyLoading = true;
        $unsaved->parts;

        $this->assertTrue($called, 'The custom handler no longer bypasses the exists/wasRecentlyCreated guard');
    }
}
