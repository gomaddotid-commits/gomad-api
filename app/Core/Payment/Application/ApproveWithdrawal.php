<?php

namespace App\Core\Payment\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Notification\Application\WithdrawalStatusNotification;
use App\Core\Payment\Domain\PaymentConflict;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

final class ApproveWithdrawal
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    public function execute(User $admin, Withdrawal $withdrawal): Withdrawal
    {
        $this->authorizer->authorize($admin, 'withdrawal.review');

        return DB::transaction(function () use ($admin, $withdrawal): Withdrawal {
            $locked = Withdrawal::query()->whereKey($withdrawal->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === 'approved' && $locked->approved_by === $admin->getKey()) {
                return $locked;
            }
            if ($locked->status !== 'pending') {
                throw new PaymentConflict('Pencairan tidak berada dalam status menunggu persetujuan.');
            }
            if ((int) $locked->requested_by === (int) $admin->getKey()) {
                throw new PaymentConflict('Pengaju tidak dapat menyetujui pencairannya sendiri.');
            }

            $locked->forceFill([
                'status' => 'approved',
                'approved_by' => $admin->getKey(),
                'approved_at' => now(),
            ])->save();
            $this->notify($locked, 'approved');

            return $locked;
        }, attempts: 3);
    }

    private function notify(Withdrawal $withdrawal, string $status): void
    {
        Notification::send($withdrawal->user, new WithdrawalStatusNotification(
            $withdrawal->code,
            $status,
            (string) $withdrawal->amount,
        ));
    }
}
