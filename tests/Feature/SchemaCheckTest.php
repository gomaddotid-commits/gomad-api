<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_schema_matches_the_migrations(): void
    {
        $this->artisan('gomad:schema-check')
            ->expectsOutputToContain('Skema cocok dengan migration')
            ->assertExitCode(0);
    }

    public function test_a_column_outside_the_migrations_is_reported_as_drift(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('kolom_tanpa_migration', 20)->nullable();
        });

        $this->artisan('gomad:schema-check')
            ->expectsOutputToContain('Kolom di luar migration: users.kolom_tanpa_migration')
            ->assertExitCode(1);
    }

    public function test_json_output_lists_the_drift(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('kolom_tanpa_migration', 20)->nullable();
        });

        $this->artisan('gomad:schema-check', ['--json' => true])
            ->expectsOutputToContain('kolom_tanpa_migration')
            ->assertExitCode(1);
    }
}
