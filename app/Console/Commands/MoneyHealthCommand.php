<?php

namespace App\Console\Commands;

use App\Core\Ledger\Application\ReconcileLedger;
use App\Core\Monitoring\Application\SendAlert;
use App\Models\PaymentIntent;
use App\Models\Rental;
use App\Models\TravelBooking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pemantauan uang: memberi tahu (log critical + exit code non-zero) kalau ada
 * jejak yang menandakan uang tidak seimbang atau menggantung.
 */
class MoneyHealthCommand extends Command
{
    protected $signature = 'gomad:money-health';

    protected $description = 'Periksa kesehatan ledger & pembayaran; keluar dengan kode 1 bila ada masalah kritis';

    public function handle(ReconcileLedger $reconcile, SendAlert $alerts): int
    {
        $critical = [];
        $warning = [];

        $report = $reconcile->execute();
        $mismatched = array_values(array_filter(
            $report['accounts'],
            static fn (array $account): bool => $account['matches'] === false,
        ));

        foreach ($mismatched as $account) {
            $critical[] = sprintf(
                'Saldo %s tidak cocok (tersimpan %s, rekonstruksi %s).',
                $account['account_code'],
                $account['cached_balance'],
                $account['reconstructed_balance'],
            );
        }

        if ($report['unbalanced_transactions'] !== []) {
            $critical[] = 'Transaksi ledger tidak seimbang: #'.implode(', #', $report['unbalanced_transactions']);
        }

        $failedWebhooks = DB::table('payment_webhook_events')
            ->whereIn('processing_status', ['failed', 'error'])
            ->count();
        if ($failedWebhooks > 0) {
            $critical[] = $failedWebhooks.' callback pembayaran gagal diproses.';
        }

        $timeout = (int) config('services.midtrans.payment_timeout', 30);
        $stalePayments = PaymentIntent::query()
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subMinutes($timeout * 4))
            ->count();
        if ($stalePayments > 0) {
            $warning[] = $stalePayments.' pembayaran masih menggantung melewati batas waktu.';
        }

        $expiredBookings = TravelBooking::query()
            ->where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now()->subHours(2))
            ->count();
        if ($expiredBookings > 0) {
            $warning[] = $expiredBookings.' pemesanan travel kedaluwarsa belum dilepas.';
        }

        $stuckDeposits = Rental::query()
            ->where('deposit_status', 'held')
            ->whereIn('status', ['completed', 'cancelled'])
            ->count();
        if ($stuckDeposits > 0) {
            $critical[] = $stuckDeposits.' deposit sewa masih ditahan padahal sewanya sudah selesai/batal.';
        }

        foreach ($warning as $message) {
            $this->warn($message);
            $alerts->warning($message, ['source' => 'gomad:money-health']);
        }

        foreach ($critical as $message) {
            $this->error($message);
            $alerts->critical($message, ['source' => 'gomad:money-health']);
        }

        if ($critical === []) {
            $this->info('Kesehatan uang: sehat'.($warning === [] ? '.' : ' (dengan peringatan).'));

            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
