<?php

namespace Tests\Feature;

use App\Services\BackupArchive;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupArchiveTest extends TestCase
{
    private string $localBackup;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        config([
            'backup.disk' => 's3',
            'backup.prefix' => 'backups',
        ]);

        $directory = sys_get_temp_dir().'/gomad-backup-test-'.bin2hex(random_bytes(4));
        mkdir($directory, 0700);
        $this->localBackup = $directory.'/gomad-20261004T120000Z-a1b2c3d4.sql';
        file_put_contents($this->localBackup, 'private database dump');
    }

    protected function tearDown(): void
    {
        if (isset($this->localBackup) && is_file($this->localBackup)) {
            unlink($this->localBackup);
            rmdir(dirname($this->localBackup));
        }

        parent::tearDown();
    }

    public function test_private_backup_can_be_uploaded_listed_and_downloaded_for_restore(): void
    {
        $archive = app(BackupArchive::class);
        $key = $archive->store($this->localBackup);

        Storage::disk('s3')->assertExists($key);
        $this->assertSame($key, $archive->latest());

        $restorePath = tempnam(sys_get_temp_dir(), 'gomad-backup-restore-test-');
        unlink($restorePath);

        try {
            $archive->download($key, $restorePath);

            $this->assertSame('private database dump', file_get_contents($restorePath));
            $this->assertSame(0600, fileperms($restorePath) & 0777);
        } finally {
            if (is_file($restorePath)) {
                unlink($restorePath);
            }
        }
    }

    public function test_restore_rejects_keys_outside_the_backup_prefix(): void
    {
        $restorePath = tempnam(sys_get_temp_dir(), 'gomad-backup-restore-test-');
        unlink($restorePath);

        try {
            $this->expectException(\RuntimeException::class);
            app(BackupArchive::class)->download('../private.sql', $restorePath);
        } finally {
            if (is_file($restorePath)) {
                unlink($restorePath);
            }
        }
    }
}
