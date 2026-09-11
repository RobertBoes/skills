<?php

namespace Skills\ClaimChecks;

use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Claims made by: laravel-packages/skills/laravel-packages/
 */
class LaravelPackagesClaimsTest extends TestCase
{
    #[Test]
    public function package_tools_still_provides_the_hooks_the_skill_uses(): void
    {
        // "spatie/laravel-package-tools provides PackageServiceProvider with
        // configurePackage(), plus packageRegistered() / packageBooted() hooks."
        $this->assertTrue(class_exists(PackageServiceProvider::class));

        foreach (['configurePackage', 'packageRegistered', 'packageBooted'] as $method) {
            $this->assertTrue(
                method_exists(PackageServiceProvider::class, $method),
                "PackageServiceProvider::{$method}() is gone; the service provider section names it",
            );
        }
    }

    #[Test]
    public function middleware_can_still_be_pushed_into_a_group_at_boot(): void
    {
        // The auto-registration snippet in the skill depends on this router method.
        $this->assertTrue(method_exists(\Illuminate\Routing\Router::class, 'pushMiddlewareToGroup'));
    }
}
