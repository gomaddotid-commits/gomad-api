<?php

namespace App\Console\Commands;

use App\Services\BackupArchive;
use Illuminate\Console\Command;
use Throwable;

class DownloadBackup extends Command
{
    protected $signature = 'app:download-backup {key? : Cloud backup object key}';

    protected $description = 'Download a verified private cloud backup for restore testing';

    public function handle(BackupArchive $archive): int
    {
        $key = $this->argument('key') ?: $archive->latest();

        if ($key === null) {
            $this->error('No database backups are available in cloud storage.');

            return self::FAILURE;
        }

        $directory = config('backup.directory');
        $temporaryPath = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR
            .'.restore-'.bin2hex(random_bytes(8)).'.sql';

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            $this->error('Could not prepare the private backup directory.');

            return self::FAILURE;
        }

        try {
            $archive->download($key, $temporaryPath);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('The cloud backup could not be downloaded and verified.');

            return self::FAILURE;
        }

        $this->line($temporaryPath);

        return self::SUCCESS;
    }
}
