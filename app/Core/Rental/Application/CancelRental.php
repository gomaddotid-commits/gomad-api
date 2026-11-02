<?php

namespace App\Core\Rental\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Ledger\Application\ReleaseWalletHold;
use App\Core\Payment\Domain\PaymentConflict;
use App\Core\Rental\Domain\RentalStatus;
use App\Models\Rental;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CancelRental
{
    public function __construct(
        private readonly ActionAuthorizer $authorizer,
        private readonly ReleaseWalletHold $releaseHold,
    ) {}

    public function execute(User $actor, Rental $rental): Rental
    {
        $this->authorizer->authorize($actor, 'schedule.manage', $rental->business);

        return DB::transaction(function () use ($rental): Rental {
            $locked = Rental::query()->whereKey($rental->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== RentalStatus::Pending->value) {
                throw new PaymentConflict('Hanya sewa yang belum dibayar dapat dibatalkan.');
            }

            $holds = DB::table('wallet_holds')
                ->where('source_type', 'rental')
                ->where('source_id', $locked->getKey())
                ->where('reason', 'ots_deposit')
                ->where('status', 'active')
                ->lockForUpdate()
                ->pluck('id');

            foreach ($holds as $holdId) {
                $this->releaseHold->execute((int) $holdId);
            }

            $locked->forceFill([
                'status' => RentalStatus::Cancelled->value,
                'cancelled_at' => now(),
                'deposit_status' => 'none',
            ])->save();

            return $locked;
        }, attempts: 3);
    }
}
