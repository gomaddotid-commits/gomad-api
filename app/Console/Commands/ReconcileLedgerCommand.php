<?php

namespace App\Console\Commands;

use App\Core\Ledger\Application\ReconcileLedger;
use App\Core\Monitoring\Application\SendAlert;
use App\Models\LedgerTransaction;
use Illuminate\Console\Command;

class ReconcileLedgerCommand extends Command
{
    protected $signature = 'gomad:reconcile
        {--json : Keluarkan hasil sebagai JSON}';

    protected $description = 'Rekonsiliasi penuh: saldo tersimpan tiap akun harus sama dengan jumlah mutasinya';

    public function handle(ReconcileLedger $reconcile, SendAlert $alerts): int
    {
        $report = $reconcile->execute();

        $mismatched = array_values(array_filter(
            $report['accounts'],
            static fn (array $account): bool => $account['matches'] === false,
        ));

        $clean = $mismatched === [] && $report['unbalanced_transactions'] === [];

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'clean' => $clean,
                'accounts' => $report['accounts'],
                'mismatched_accounts' => $mismatched,
                'unbalanced_transactions' => $report['unbalanced_transactions'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $clean ? self::SUCCESS : self::FAILURE;
        }

        $this->table(
            ['Akun', 'Saldo tersimpan', 'Rekonstruksi', 'Cocok'],
            array_map(static fn (array $account): array => [
                $account['account_code'],
                $account['cached_balance'],
                $account['reconstructed_balance'],
                $account['matches'] ? 'ya' : 'TIDAK',
            ], $report['accounts']),
        );

        if ($report['unbalanced_transactions'] !== []) {
            $this->error('Transaksi tidak seimbang: #'.implode(', #', $report['unbalanced_transactions']));
            $alerts->critical('Transaksi ledger tidak seimbang', [
                'source' => 'gomad:reconcile',
                'transactions' => $report['unbalanced_transactions'],
            ]);
        }

        if ($mismatched !== []) {
            foreach ($mismatched as $account) {
                $this->error(sprintf(
                    'Selisih pada %s: tersimpan %s, rekonstruksi %s (entri bermasalah: %s)',
                    $account['account_code'],
                    $account['cached_balance'],
                    $account['reconstructed_balance'],
                    $account['invalid_entries'] === [] ? '-' : implode(',', $account['invalid_entries']),
                ));
                $alerts->critical('Saldo akun tidak cocok: '.$account['account_code'], [
                    'source' => 'gomad:reconcile',
                    'cached' => $account['cached_balance'],
                    'reconstructed' => $account['reconstructed_balance'],
                ]);
            }

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Rekonsiliasi bersih: %d akun cocok, %d transaksi seimbang, tanpa selisih.',
            count($report['accounts']),
            LedgerTransaction::query()->count(),
        ));

        return self::SUCCESS;
    }
}
