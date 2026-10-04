<?php

namespace App\Console\Commands;

use App\Services\BackupArchive;
use Illuminate\Console\Command;
use Pdo\Mysql;
use Symfony\Component\Process\Process;
use Throwable;

class BackupDatabase extends Command
{
    protected $signature = 'app:backup-db';

    protected $description = 'Create a retained MariaDB backup';

    public function __construct(private readonly BackupArchive $archive)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $connection = config('database.connections.'.config('database.default'));

        if (($connection['driver'] ?? null) !== 'mysql') {
            $this->error('Database backups require the MySQL/MariaDB connection.');

            return self::FAILURE;
        }

        $directory = config('backup.directory');

        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            $this->error("Could not create backup directory: {$directory}");

            return self::FAILURE;
        }

        if (! chmod($directory, 0700)) {
            $this->error("Could not secure backup directory: {$directory}");

            return self::FAILURE;
        }

        $backupPath = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR
            .'gomad-'.now()->utc()->format('Ymd\THis\Z').'-'.bin2hex(random_bytes(4)).'.sql';
        $credentialsPath = tempnam(sys_get_temp_dir(), 'gomad-mariadb-credentials-');

        if ($credentialsPath === false) {
            $this->error('Could not create a temporary database credentials file.');

            return self::FAILURE;
        }

        $previousUmask = umask(0077);

        try {
            $credentials = [
                '[client]',
                'host="'.self::escapeOptionFileValue((string) $connection['host']).'"',
                'port="'.(int) $connection['port'].'"',
                'user="'.self::escapeOptionFileValue((string) $connection['username']).'"',
                'password="'.self::escapeOptionFileValue((string) $connection['password']).'"',
                'default-character-set=utf8mb4',
                '',
            ];

            if (! chmod($credentialsPath, 0600)
                || file_put_contents($credentialsPath, implode(PHP_EOL, $credentials)) === false) {
                $this->error('Could not secure the temporary database credentials file.');

                return self::FAILURE;
            }

            $process = new Process([
                'mariadb-dump',
                "--defaults-extra-file={$credentialsPath}",
                ...self::tlsDumpOptions($connection),
                '--single-transaction',
                '--quick',
                '--skip-lock-tables',
                config('database.connections.'.config('database.default').'.database'),
                "--result-file={$backupPath}",
            ]);
            $process->setTimeout(300);
            $process->run();

            if (! $process->isSuccessful()) {
                if (is_file($backupPath) && ! unlink($backupPath)) {
                    $this->error("Could not remove the incomplete backup: {$backupPath}");
                }
                $this->error('Database backup failed: '.trim($process->getErrorOutput()));

                return self::FAILURE;
            }

            if (! is_file($backupPath) || filesize($backupPath) === 0) {
                if (is_file($backupPath) && ! unlink($backupPath)) {
                    $this->error("Could not remove the incomplete backup: {$backupPath}");
                }
                $this->error('Database backup completed without producing a non-empty dump.');

                return self::FAILURE;
            }

            if (! chmod($backupPath, 0600)) {
                if (! unlink($backupPath)) {
                    $this->error("Could not remove the insecure backup: {$backupPath}");
                }
                $this->error("Could not secure database backup: {$backupPath}");

                return self::FAILURE;
            }
        } finally {
            umask($previousUmask);

            if (is_file($credentialsPath) && ! unlink($credentialsPath)) {
                throw new \RuntimeException('Could not remove the temporary database credentials file.');
            }
        }

        try {
            $objectKey = $this->archive->store($backupPath);
            $this->archive->prune((int) config('backup.retention_days'));
        } catch (Throwable $exception) {
            report($exception);
            $this->error("Cloud backup upload or verification failed; the private local copy was retained at {$backupPath}.");

            return self::FAILURE;
        }

        $cutoff = now()->subDays((int) config('backup.retention_days'));

        foreach (glob(rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'gomad-*.sql') ?: [] as $oldBackup) {
            if (filemtime($oldBackup) < $cutoff->getTimestamp() && ! unlink($oldBackup)) {
                $this->error("Could not remove expired backup: {$oldBackup}");

                return self::FAILURE;
            }
        }

        $this->info("Database backup created locally and verified in cloud storage as {$objectKey}.");

        return self::SUCCESS;
    }

    private static function escapeOptionFileValue(string $value): string
    {
        return str_replace(
            ['\\', '"', "\n", "\r"],
            ['\\\\', '\\"', '\\n', '\\r'],
            $value,
        );
    }

    private static function tlsDumpOptions(array $connection): array
    {
        $caPath = $connection['options'][Mysql::ATTR_SSL_CA] ?? null;

        if (! is_string($caPath) || $caPath === '') {
            return [];
        }

        if (! is_file($caPath) || ! is_readable($caPath)) {
            throw new \RuntimeException('The configured MySQL CA certificate is missing or unreadable.');
        }

        return [
            "--ssl-ca={$caPath}",
            '--ssl-verify-server-cert',
        ];
    }
}
