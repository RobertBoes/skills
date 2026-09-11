<?php

namespace Skills\ClaimChecks;

use Illuminate\Support\Facades\Vite;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Spatie\Csp\Nonce\NonceGenerator;
use Spatie\Csp\Preset;

/**
 * Claims made by: web-security/skills/web-security/
 */
class WebSecurityClaimsTest extends TestCase
{
    #[Test]
    public function the_package_uses_the_preset_api_and_no_longer_the_policy_api(): void
    {
        // "spatie/laravel-csp v3 replaced policy classes with presets. A v2-era
        // `extends Spatie\Csp\Policies\Policy` with addDirective() is the old API."
        $this->assertTrue(interface_exists(Preset::class));
        $this->assertFalse(
            class_exists('Spatie\Csp\Policies\Policy'),
            'The v2 Policy base class is back; the version note claims it is gone',
        );
    }

    #[Test]
    public function the_nonce_generator_contract_is_unchanged_between_v2_and_v3(): void
    {
        // "The Spatie\Csp\Nonce\NonceGenerator interface (a single generate(): string)
        // is unchanged between v2 and v3" — which is why a Vite nonce generator still
        // works after the upgrade.
        $this->assertTrue(interface_exists(NonceGenerator::class));

        $generate = new ReflectionMethod(NonceGenerator::class, 'generate');

        $this->assertSame('string', (string) $generate->getReturnType());
        $this->assertCount(0, $generate->getParameters());
    }

    #[Test]
    public function vite_exposes_the_nonce_helper_the_generator_delegates_to(): void
    {
        // references/csp.md: "Vite::useCspNonce() both generates the value and makes
        // Vite stamp it on every tag it renders."
        $this->assertTrue(method_exists(Vite::getFacadeRoot(), 'useCspNonce'));
        $this->assertIsString(Vite::useCspNonce());
    }

    #[Test]
    public function the_config_keys_the_skill_names_exist(): void
    {
        // "Three config keys do work that is otherwise hand-rolled: report_only_presets
        // / report_uri, enabled_while_hot_reloading, nonce_generator."
        $config = require __DIR__.'/../vendor/spatie/laravel-csp/config/csp.php';

        foreach ([
            'presets',
            'report_only_presets',
            'report_uri',
            'enabled_while_hot_reloading',
            'nonce_generator',
        ] as $key) {
            $this->assertArrayHasKey($key, $config, "csp config key '{$key}' is referenced by the skill but no longer exists");
        }
    }
}
