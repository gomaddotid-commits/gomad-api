<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        app(LedgerAccountSeeder::class)->run();
    }

    public function test_money_routes_stop_after_thirty_attempts_per_minute(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);

        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $this->postJson('/mad-trans/bookings/999999/pay')->assertNotFound();
        }

        $this->postJson('/mad-trans/bookings/999999/pay')->assertStatus(429);
    }

    public function test_document_upload_and_promo_creation_have_their_own_limits(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $this->actingAs($user);

        // 20 unggahan per menit, lalu ditolak dengan 429.
        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $this->post('/mad-trans/rental-documents')->assertStatus(302);
        }
        $this->post('/mad-trans/rental-documents')->assertStatus(429);
    }
}
