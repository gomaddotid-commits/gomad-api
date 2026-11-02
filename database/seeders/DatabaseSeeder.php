<?php

namespace Database\Seeders;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(AccessControlSeeder::class);
        $this->call(LedgerAccountSeeder::class);

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '+6281234567890',
        ]);
    }
}
