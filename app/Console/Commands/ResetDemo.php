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

        if (config('database.default') === 'mysql'
            && ! in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1', 'db'], true)) {
            $this->error('The demo database cannot be reset on a remote MySQL server.');

            return self::FAILURE;
        }

        return $this->call('migrate:fresh', [
            '--seed' => true,
            '--force' => true,
        ]);
    }
}
