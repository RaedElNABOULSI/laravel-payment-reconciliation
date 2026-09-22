<?php

declare(strict_types=1);

namespace Raedev\LaravelPaymentReconciliation\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Raedev\LaravelPaymentReconciliation\Tests\TestCase;

/**
 * PaymentReconciliationServiceProvider::boot() previously gated
 * $this->commands([...]) behind runningInConsole(). Since PHPUnit
 * itself runs under the CLI SAPI, a normal test can't reproduce "not
 * running in console" - runningInConsole() would report true either
 * way.
 *
 * Illuminate\Foundation\Application::runningInConsole() checks the
 * APP_RUNNING_IN_CONSOLE env var before falling back to PHP_SAPI, so
 * we force it to simulate a web request. Two things make this fiddly:
 * - Testbench disables the putenv() adapter (Env::disablePutenv()),
 *   so the override has to go through $_ENV/$_SERVER instead.
 * - The value has to be set before parent::setUp() ever runs, because
 *   Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables already
 *   calls runningInConsole() during application bootstrap - well
 *   before the defineEnvironment() hook fires - which permanently
 *   memoizes the result on the Application instance.
 */
class ConsoleCommandRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['APP_RUNNING_IN_CONSOLE'] = 'false';
        $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'false';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_RUNNING_IN_CONSOLE'], $_SERVER['APP_RUNNING_IN_CONSOLE']);

        parent::tearDown();
    }

    public function test_reconcile_command_is_registered_even_outside_a_console_context(): void
    {
        $this->assertFalse($this->app->runningInConsole());

        $this->assertArrayHasKey('payments:reconcile', Artisan::all());
    }

    public function test_status_command_is_registered_even_outside_a_console_context(): void
    {
        $this->assertFalse($this->app->runningInConsole());

        $this->assertArrayHasKey('payments:status', Artisan::all());
    }
}
