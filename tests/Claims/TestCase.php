<?php

namespace Skills\ClaimChecks;

use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base case for the claim checks.
 *
 * These tests do not test the framework. They assert that the specific,
 * version-sensitive statements made by the skills in this repository are still
 * true of the currently installed framework and packages, so that a stale claim
 * fails here instead of misleading someone months later.
 */
abstract class TestCase extends Orchestra
{
    protected function defineEnvironment($app): void
    {
        $app->config->set('database.default', 'testing');
    }

    protected function defineDatabaseMigrations(): void
    {
        // A real table, so that a violation handler which reports instead of
        // throwing can let the lazy load proceed — as it does in production.
        Schema::create('widgets', function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('parent_id')->nullable();
            $table->timestamp('deactivated_at')->nullable();
        });
    }
}
