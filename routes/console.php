<?php

use App\Core\Identity\Domain\PhoneNumber;
use App\Models\PlatformUserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:assign-platform-admin {phone}', function (string $phone): int {
    try {
        $normalizedPhone = PhoneNumber::fromInput($phone)->value;
    } catch (InvalidArgumentException $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }

    $user = User::query()->where('phone', $normalizedPhone)->where('status', 'active')->first();
    if ($user === null) {
        $this->error('Akun aktif dengan nomor tersebut tidak ditemukan.');

        return Command::FAILURE;
    }

    PlatformUserRole::query()->firstOrCreate([
        'user_id' => $user->getKey(),
        'role' => 'admin',
    ]);
    $this->info('Peran admin platform ditetapkan.');

    return Command::SUCCESS;
})->purpose('Assign the platform admin role to an existing active account');

Schedule::command('app:backup-db')
    ->dailyAt('02:00')
    ->withoutOverlapping();

Schedule::command('mad-warung:expire-stale-orders')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// B1/B2: kursi pemesanan travel yang tidak dibayar harus dilepas otomatis.
Schedule::command('mad-trans:expire-bookings')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Pemantauan uang: keluar non-zero + log critical bila ada yang tidak seimbang.
Schedule::command('gomad:money-health')
    ->everyTenMinutes()
    ->withoutOverlapping();

// Rekonsiliasi penuh harian; hasilnya juga disimpan di log.
Schedule::command('gomad:reconcile')
    ->dailyAt('02:30')
    ->withoutOverlapping();
