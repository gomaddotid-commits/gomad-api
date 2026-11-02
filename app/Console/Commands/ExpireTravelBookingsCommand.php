<?php

namespace App\Console\Commands;

use App\Core\MadTrans\Application\ExpirePendingTravelBookings;
use Illuminate\Console\Command;

class ExpireTravelBookingsCommand extends Command
{
    protected $signature = 'mad-trans:expire-bookings {--limit=200 : Jumlah pemesanan yang diproses sekali jalan}';

    protected $description = 'Lepas pemesanan travel yang tidak dibayar sampai batas waktunya';

    public function handle(ExpirePendingTravelBookings $expire): int
    {
        $result = $expire->execute((int) $this->option('limit'));

        $this->info(sprintf(
            'Pemesanan kedaluwarsa: %d, kursi dilepas: %d.',
            $result['expired'],
            $result['released_seats'],
        ));

        return self::SUCCESS;
    }
}
