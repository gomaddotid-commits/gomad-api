<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ResetDemoTest extends TestCase
{
    public function test_test_suite_uses_an_in_memory_database(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_demo_reset_refuses_to_run_outside_local_environment(): void
    {
        Config::set('app.env', 'staging');

        $this->artisan('app:reset-demo')
            ->expectsOutput('The demo database can only be reset when APP_ENV=local.')
            ->assertExitCode(1);
    }

    public function test_demo_reset_refuses_to_run_against_a_remote_mysql_server(): void
    {
        Config::set('app.env', 'local');
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.host', 'remote.example.test');

        $this->artisan('app:reset-demo')
            ->expectsOutput('The demo database cannot be reset on a remote MySQL server.')
            ->assertExitCode(1);
    }
}
