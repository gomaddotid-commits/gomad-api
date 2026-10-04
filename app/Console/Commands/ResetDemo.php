<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ResetDemo extends Command
{
    protected $signature = 'app:reset-demo';

    protected $description = 'Rebuild the local demo database and seed it';

    public function handle(): int
    {
        if (config('app.env') !== 'local') {
            $this->error('The demo database can only be reset when APP_ENV=local.');

            return self::FAILURE;
        }

        return $this->call('migrate:fresh', [
            '--seed' => true,
            '--force' => true,
        ]);
    }
}
