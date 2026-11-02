<?php

namespace App\Core\Warung\Application;

use App\Core\Notification\Application\OrderStatusNotification;
use App\Core\Warung\Domain\OrderStatus;
use App\Models\Order;
use App\Models\PaymentIntent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

final class ExpireWarungOrderPayment
{
    public function __construct(private readonly OrderInventory $inventory) {}

    public function execute(PaymentIntent $payment, string $paymentStatus): void
    {
        if ($payment->purpose !== 'warung_order' || $payment->order_id === null) {
            return;
        }

        DB::transaction(function () use ($payment, $paymentStatus): void {
            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            if ($order->status !== OrderStatus::PendingPayment->value) {
                return;
            }
            $this->inventory->release($order);
            $order->forceFill(['status' => OrderStatus::Expired->value])->save();
            Notification::send(
                $order->creator,
                new OrderStatusNotification(
                    $order->code,
                    'payment_failed',
                    $paymentStatus,
                ),
            );
        }, attempts: 3);
    }
}
