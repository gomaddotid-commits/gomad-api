<?php

namespace App\Console\Commands;

use App\Core\Warung\Application\ExpireStaleWarungOrders;
use Illuminate\Console\Command;

class ExpireStaleWarungOrdersCommand extends Command
{
    protected $signature = 'mad-warung:expire-stale-orders';

    protected $description = 'Release stock reserved by expired Mad Warung orders';

    public function handle(ExpireStaleWarungOrders $expireOrders): int
    {
        $count = $expireOrders->execute();
        $this->info("Expired {$count} Mad Warung order(s).");

        return self::SUCCESS;
    }
}
