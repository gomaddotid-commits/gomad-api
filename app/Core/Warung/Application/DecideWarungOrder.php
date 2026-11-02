<?php

namespace App\Core\Warung\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Notification\Application\OrderStatusNotification;
use App\Core\Payment\Domain\PaymentConflict;
use App\Core\Warung\Domain\OrderStatus;
use App\Models\Order;
use App\Models\OrderApproval;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

final class DecideWarungOrder
{
    public function __construct(
        private readonly ActionAuthorizer $authorizer,
        private readonly OrderInventory $inventory,
    ) {}

    public function execute(User $owner, Order $order, string $decision, ?string $reason = null): Order
    {
        $this->authorizer->authorize($owner, 'order.approve', $order->business);
        if ((int) $order->business->owner_user_id !== (int) $owner->getKey()) {
            throw new AuthorizationException('Hanya pemilik warung dapat memutuskan approval.');
        }
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Keputusan approval harus approved atau rejected.');
        }
        if ($decision === 'rejected' && trim((string) $reason) === '') {
            throw new InvalidArgumentException('Alasan penolakan wajib diisi.');
        }

        return DB::transaction(function () use ($owner, $order, $decision, $reason): Order {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== OrderStatus::PendingApproval->value) {
                if ($locked->status === ($decision === 'approved'
                    ? OrderStatus::PendingPayment->value
                    : OrderStatus::Rejected->value)) {
                    return $locked;
                }
                throw new PaymentConflict('Pesanan tidak lagi menunggu persetujuan.');
            }

            $approval = OrderApproval::query()
                ->where('order_id', $locked->getKey())
                ->where('decision', 'pending')
                ->lockForUpdate()
                ->firstOrFail();
            $approval->forceFill([
                'decision' => $decision,
                'decided_by' => $owner->getKey(),
                'reason' => $decision === 'rejected' ? trim((string) $reason) : 'Disetujui pemilik warung.',
                'decided_at' => now(),
            ])->save();
            $locked->forceFill([
                'status' => $decision === 'approved'
                    ? OrderStatus::PendingPayment->value
                    : OrderStatus::Rejected->value,
                'approved_by' => $decision === 'approved' ? $owner->getKey() : null,
                'approved_at' => $decision === 'approved' ? now() : null,
            ])->save();
            if ($decision === 'rejected') {
                $this->inventory->release($locked);
            }
            Notification::send(
                $locked->creator,
                new OrderStatusNotification(
                    $locked->code,
                    $decision === 'approved' ? 'approved' : 'rejected',
                    $decision === 'rejected' ? trim((string) $reason) : null,
                ),
            );

            return $locked;
        }, attempts: 3);
    }
}
