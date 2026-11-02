<?php

namespace App\Core\Warung\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Notification\Application\OrderStatusNotification;
use App\Core\Payment\Domain\PaymentConflict;
use App\Core\Warung\Domain\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

final class CompleteWarungOrder
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    public function execute(User $owner, Order $order): Order
    {
        $this->authorizer->authorize($owner, 'order.approve', $order->business);
        if ((int) $order->business->owner_user_id !== (int) $owner->getKey()) {
            throw new AuthorizationException('Hanya pemilik warung dapat menyelesaikan pesanan.');
        }

        return DB::transaction(function () use ($order): Order {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === OrderStatus::Completed->value) {
                return $locked;
            }
            if ($locked->status !== OrderStatus::Received->value) {
                throw new PaymentConflict('Pesanan harus diterima sebelum ditandai selesai.');
            }
            $locked->forceFill(['status' => OrderStatus::Completed->value])->save();
            Notification::send(
                $locked->wholesaler->owner,
                new OrderStatusNotification($locked->code, 'completed'),
            );

            return $locked;
        }, attempts: 3);
    }
}
