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
    public function the_handler_receives_exactly_the_model_and_the_relation_name(): void
    {
        // "The handler receives exactly two arguments — the model and the relation
        // name — so build the exception yourself." A closure declaring a third
        // parameter fails with an ArgumentCountError on released versions.
        $received = [];

        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing(function (...$arguments) use (&$received): void {
            $received = $arguments;
        });

        $widget = Widget::hydrate([['id' => 1], ['id' => 2]])->first();
        $widget->parts;

        $this->assertCount(
            2,
            $received,
            'The handler signature changed; query-performance.md documents two arguments and builds the exception itself',
        );
        $this->assertInstanceOf(Widget::class, $received[0]);
        $this->assertSame('parts', $received[1]);
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
