<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Ledger\Application\PostLedgerTransaction;
use App\Core\Ledger\Domain\LedgerCategory;
use App\Core\Ledger\Domain\LedgerDirection;
use App\Core\Ledger\Domain\LedgerEntryInput;
use App\Core\Ledger\Domain\PostLedgerTransactionData;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Models\Business;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

class LedgerConcurrencyTest extends TestCase
{
    public function test_concurrent_requests_with_the_same_key_post_once(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrent idempotency test.');
        }

        $databasePath = tempnam(sys_get_temp_dir(), 'gomad-ledger-race-');
        $this->assertIsString($databasePath);
        unlink($databasePath);
        $barrierPath = $databasePath.'-barrier';
        mkdir($barrierPath);
        $defaultConnection = DB::getDefaultConnection();
        $children = [];

        config(['database.connections.ledger_race' => [
            'driver' => 'sqlite',
            'database' => $databasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 10000,
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
        ]]);
        DB::purge('ledger_race');
        DB::setDefaultConnection('ledger_race');

        try {
            Artisan::call('migrate:fresh', ['--database' => 'ledger_race', '--force' => true]);
            $this->seed(AccessControlSeeder::class);
            app(LedgerAccountSeeder::class)->run();
            $business = Business::query()->create([
                'type' => 'agency',
                'name' => 'Concurrent ledger test',
                'code' => 'GM-AGENCY-RACE001',
                'status' => 'active',
            ]);
            app(LedgerAccountSeeder::class)->ensureBusinessAccounts($business);

            for ($worker = 0; $worker < 2; $worker++) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid, 'Could not fork a ledger worker process.');
                if ($pid === 0) {
                    DB::purge('ledger_race');
                    DB::setDefaultConnection('ledger_race');
                    file_put_contents($barrierPath.'/ready-'.$worker, 'ready');

                    $deadline = microtime(true) + 10;
                    while (! is_file($barrierPath.'/start') && microtime(true) < $deadline) {
                        usleep(1000);
                    }

                    try {
                        $transaction = app(PostLedgerTransaction::class)->execute(new PostLedgerTransactionData(
                            category: LedgerCategory::BookingPaidOnline,
                            idempotencyKey: 'same-concurrent-callback',
                            entries: [
                                new LedgerEntryInput('PLATFORM.CASH', LedgerDirection::Debit, '50000'),
                                new LedgerEntryInput(
                                    'BUSINESS.PAYABLE:'.$business->getKey(),
                                    LedgerDirection::Credit,
                                    '50000',
                                ),
                            ],
                        ));
                        file_put_contents($barrierPath.'/result-'.$worker, (string) $transaction->getKey());
                        exit(0);
                    } catch (Throwable $exception) {
                        file_put_contents($barrierPath.'/result-'.$worker, 'error:'.$exception::class.':'.$exception->getMessage());
                        exit(1);
                    }
                }

                $children[] = $pid;
            }

            $deadline = microtime(true) + 10;
            while ((! is_file($barrierPath.'/ready-0') || ! is_file($barrierPath.'/ready-1'))
                && microtime(true) < $deadline) {
                usleep(1000);
            }
            $this->assertFileExists($barrierPath.'/ready-0');
            $this->assertFileExists($barrierPath.'/ready-1');
            file_put_contents($barrierPath.'/start', 'go');

            $exitCodes = [];
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $exitCodes[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1;
            }

            $results = [
                file_get_contents($barrierPath.'/result-0'),
                file_get_contents($barrierPath.'/result-1'),
            ];
            $this->assertSame([0, 0], $exitCodes, implode('; ', array_filter($results, fn ($result): bool => str_starts_with($result, 'error:'))));
            $this->assertSame($results[0], $results[1]);
            $this->assertSame(1, LedgerTransaction::query()->count());
            $this->assertSame('50000.00', LedgerAccount::query()
                ->where('code', 'PLATFORM.CASH')
                ->value('balance_cached'));
        } finally {
            foreach ($children as $pid) {
                if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                    posix_kill($pid, SIGTERM);
                    pcntl_waitpid($pid, $status);
                }
            }
            DB::purge('ledger_race');
            DB::setDefaultConnection($defaultConnection);
            foreach (glob($barrierPath.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($barrierPath);
            foreach ([$databasePath, $databasePath.'-wal', $databasePath.'-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
