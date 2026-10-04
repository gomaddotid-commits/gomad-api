<?php

namespace App\Services;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BackupArchive
{
    public function store(string $localPath): string
    {
        if (! is_file($localPath) || ! is_readable($localPath)) {
            throw new RuntimeException('The local database backup is missing or unreadable.');
        }

        $filename = basename($localPath);

        if (! preg_match('/^gomad-[A-Za-z0-9T-]+\.sql$/', $filename)) {
            throw new RuntimeException('The database backup filename is invalid.');
        }

        $key = $this->keyFor($filename);
        $stream = fopen($localPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Could not open the local database backup for upload.');
        }

        try {
            $disk = $this->disk();

            if (! $disk->put($key, $stream, ['visibility' => 'private'])) {
                throw new RuntimeException('Cloud backup storage did not confirm the backup upload.');
            }

            if (! $disk->exists($key) || $disk->size($key) !== filesize($localPath)) {
                throw new RuntimeException('The uploaded cloud backup failed its size verification.');
            }
        } finally {
            fclose($stream);
        }

        return $key;
    }

    public function latest(): ?string
    {
        $disk = $this->disk();
        $prefix = trim((string) config('backup.prefix'), '/');
        $latestKey = null;
        $latestModified = 0;

        foreach ($disk->files($prefix) as $key) {
            if (! preg_match('/(?:^|\/)gomad-[A-Za-z0-9T-]+\.sql$/', $key)) {
                continue;
            }

            $modified = $disk->lastModified($key);

            if ($modified > $latestModified) {
                $latestKey = $key;
                $latestModified = $modified;
            }
        }

        return $latestKey;
    }

    public function download(string $key, string $targetPath): void
    {
        if (! $this->isValidKey($key)) {
            throw new RuntimeException('The requested cloud backup key is invalid.');
        }

        $disk = $this->disk();

        if (! $disk->exists($key)) {
            throw new RuntimeException('The requested cloud backup does not exist.');
        }

        $input = $disk->readStream($key);

        if (! is_resource($input)) {
            throw new RuntimeException('Could not read the cloud backup stream.');
        }

        $previousUmask = umask(0077);
        $output = null;

        try {
            $output = fopen($targetPath, 'xb');

            if ($output === false) {
                throw new RuntimeException('Could not create a private temporary restore file.');
            }

            $bytes = stream_copy_to_stream($input, $output);

            if ($bytes === false || $bytes !== $disk->size($key) || ! fflush($output)) {
                throw new RuntimeException('The downloaded cloud backup failed its size verification.');
            }
        } catch (\Throwable $exception) {
            if (is_file($targetPath) && ! unlink($targetPath)) {
                throw new RuntimeException('Cloud restore failed and its incomplete temporary file could not be removed.', previous: $exception);
            }

            throw $exception;
        } finally {
            umask($previousUmask);
            fclose($input);

            if (is_resource($output)) {
                fclose($output);
            }
        }

        if (! chmod($targetPath, 0600)) {
            if (! unlink($targetPath)) {
                throw new RuntimeException('Could not secure or remove the downloaded cloud backup.');
            }

            throw new RuntimeException('Could not secure the downloaded cloud backup.');
        }
    }

    public function prune(int $retentionDays): void
    {
        $disk = $this->disk();
        $prefix = trim((string) config('backup.prefix'), '/');
        $cutoff = now()->subDays($retentionDays)->getTimestamp();

        foreach ($disk->files($prefix) as $key) {
            if (! preg_match('/(?:^|\/)gomad-[A-Za-z0-9T-]+\.sql$/', $key)
                || $disk->lastModified($key) >= $cutoff) {
                continue;
            }

            if (! $disk->delete($key) || $disk->exists($key)) {
                throw new RuntimeException('An expired cloud backup could not be removed.');
            }
        }
    }

    private function disk(): FilesystemAdapter
    {
        return Storage::disk((string) config('backup.disk'));
    }

    private function keyFor(string $filename): string
    {
        $prefix = trim((string) config('backup.prefix'), '/');

        if ($prefix === '' || str_contains($prefix, '..')) {
            throw new RuntimeException('The cloud backup prefix is invalid.');
        }

        return $prefix.'/'.$filename;
    }

    private function isValidKey(string $key): bool
    {
        $prefix = trim((string) config('backup.prefix'), '/');

        return $prefix !== ''
            && str_starts_with($key, $prefix.'/')
            && preg_match('/^'.preg_quote($prefix, '/').'\/gomad-[A-Za-z0-9T-]+\.sql$/', $key) === 1;
    }
}
