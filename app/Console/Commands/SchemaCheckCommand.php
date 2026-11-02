<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * E1 pada `docs/04-checklist-jangan-diulang.md`: skema = sumber kebenaran.
 *
 * Referensi dibangun dari migration (database sementara yang di-`migrate:fresh`),
 * lalu dibandingkan dengan database yang sedang dipakai. Selisih apa pun berarti
 * ada perubahan skema yang tidak tercatat di migration (atau migration belum jalan).
 */
class SchemaCheckCommand extends Command
{
    private const INTERNAL_TABLES = ['sqlite_sequence', 'sqlite_master'];

    protected $signature = 'gomad:schema-check
        {--json : Keluarkan hasil sebagai JSON}';

    protected $description = 'Bandingkan skema database aktif dengan skema hasil migration';

    public function handle(): int
    {
        $referencePath = tempnam(sys_get_temp_dir(), 'gomad-schema-ref-');
        if ($referencePath === false) {
            $this->error('Tidak dapat membuat berkas database referensi.');

            return self::FAILURE;
        }

        config(['database.connections.schema_reference' => [
            'driver' => 'sqlite',
            'database' => $referencePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        try {
            Artisan::call('migrate:fresh', ['--database' => 'schema_reference', '--force' => true]);
            $reference = $this->schemaOf('schema_reference');
            $actual = $this->schemaOf(config('database.default'));
        } catch (Throwable $exception) {
            $this->error('Gagal membangun skema referensi: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            DB::purge('schema_reference');
            foreach ([$referencePath, $referencePath.'-wal', $referencePath.'-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        $missingTables = array_values(array_diff(array_keys($reference), array_keys($actual)));
        $extraTables = array_values(array_diff(array_keys($actual), array_keys($reference)));

        $missingColumns = [];
        $extraColumns = [];
        foreach (array_intersect(array_keys($reference), array_keys($actual)) as $table) {
            $missing = array_values(array_diff($reference[$table], $actual[$table]));
            $extra = array_values(array_diff($actual[$table], $reference[$table]));
            if ($missing !== []) {
                $missingColumns[$table] = $missing;
            }
            if ($extra !== []) {
                $extraColumns[$table] = $extra;
            }
        }

        $clean = $missingTables === [] && $extraTables === [] && $missingColumns === [] && $extraColumns === [];

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'clean' => $clean,
                'missing_tables' => $missingTables,
                'extra_tables' => $extraTables,
                'missing_columns' => $missingColumns,
                'extra_columns' => $extraColumns,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $clean ? self::SUCCESS : self::FAILURE;
        }

        if ($clean) {
            $this->info(sprintf(
                'Skema cocok dengan migration: %d tabel, tanpa kolom selisih.',
                count($reference),
            ));

            return self::SUCCESS;
        }

        foreach ($missingTables as $table) {
            $this->error('Tabel belum ada di database aktif: '.$table);
        }
        foreach ($extraTables as $table) {
            $this->error('Tabel ada di database aktif tetapi tidak dibuat migration: '.$table);
        }
        foreach ($missingColumns as $table => $columns) {
            $this->error('Kolom belum ada di database aktif: '.$table.'.'.implode(', '.$table.'.', $columns));
        }
        foreach ($extraColumns as $table => $columns) {
            $this->error('Kolom di luar migration: '.$table.'.'.implode(', '.$table.'.', $columns));
        }

        return self::FAILURE;
    }

    /**
     * @return array<string, list<string>>
     */
    private function schemaOf(string $connection): array
    {
        $tables = [];
        $listing = Schema::connection($connection)->getTableListing();

        foreach ($listing as $entry) {
            $table = str_contains($entry, '.') ? substr($entry, strrpos($entry, '.') + 1) : $entry;
            if (in_array($table, self::INTERNAL_TABLES, true)) {
                continue;
            }
            $columns = array_map(
                static fn (array $column): string => (string) $column['name'],
                Schema::connection($connection)->getColumns($table),
            );
            sort($columns);
            $tables[$table] = $columns;
        }

        ksort($tables);

        return $tables;
    }
}
