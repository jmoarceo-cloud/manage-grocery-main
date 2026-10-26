<?php

namespace Tests\Feature;

use Tests\TestCase;

class TestTargetGuardTest extends TestCase
{
    public function test_reset_command_refuses_a_non_testing_environment_before_any_ddl(): void
    {
        $this->app->instance('env', 'local');
        $this->artisan('migrate:fresh')->expectsOutputToContain('Refusing reset')->assertFailed();
    }
}
