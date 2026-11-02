<?php

namespace App\Core\Warung\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Notification\Application\OrderStatusNotification;
use App\Core\Payment\Domain\PaymentConflict;
use App\Core\Warung\Domain\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

final class UpdateOrderFulfillment
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    public function execute(User $wholesalerMember, Order $order, string $action): Order
    {
        $this->authorizer->authorize($wholesalerMember, 'order.fulfill', $order->wholesaler);
        if ($order->wholesaler->type !== 'grosir') {
            throw new InvalidArgumentException('Pesanan tidak ditujukan kepada bisnis grosir.');
        }
        $next = match ($action) {
            'prepare' => [OrderStatus::Paid->value, OrderStatus::Preparing->value],
            'ready' => [
                OrderStatus::Preparing->value,
                $order->delivery_method === 'ambil_sendiri'
                    ? OrderStatus::ReadyForPickup->value
                    : OrderStatus::Ready->value,
            ],
            'dispatch' => [OrderStatus::Ready->value, OrderStatus::InDelivery->value],
            default => throw new InvalidArgumentException('Aksi pemenuhan pesanan tidak dikenal.'),
        };

        return DB::transaction(function () use ($order, $next): Order {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === $next[1]) {
                return $locked;
            }
            if ($locked->status !== $next[0]) {
                throw new PaymentConflict('Status pesanan tidak mendukung transisi ini.');
            }

            $locked->forceFill([
                'status' => $next[1],
                'ready_at' => in_array($next[1], [
                    OrderStatus::Ready->value,
                    OrderStatus::ReadyForPickup->value,
                ], true) ? now() : $locked->ready_at,
            ])->save();
            Notification::send(
                $locked->creator,
                new OrderStatusNotification($locked->code, $next[1]),
            );

            return $locked;
        }, attempts: 3);
    }
}
