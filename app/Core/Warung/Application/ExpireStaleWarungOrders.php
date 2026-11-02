<?php

namespace App\Core\Warung\Application;

use App\Core\Notification\Application\OrderStatusNotification;
use App\Core\Warung\Domain\OrderStatus;
use App\Models\Order;
use App\Models\PaymentIntent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

final class ExpireStaleWarungOrders
{
    public function __construct(private readonly OrderInventory $inventory) {}

    public function execute(): int
    {
        $expiredCount = 0;
        Order::query()
            ->whereIn('status', [
                OrderStatus::PendingApproval->value,
                OrderStatus::PendingPayment->value,
            ])
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->select('id')
            ->chunkById(100, function ($rows) use (&$expiredCount): void {
                foreach ($rows as $row) {
                    $expired = DB::transaction(function () use ($row): bool {
                        $order = Order::query()->whereKey($row->id)->lockForUpdate()->first();
                        if ($order === null || ! in_array($order->status, [
                            OrderStatus::PendingApproval->value,
                            OrderStatus::PendingPayment->value,
                        ], true) || $order->expires_at?->isFuture()) {
                            return false;
                        }

                        $payment = $order->payment_intent_id === null
                            ? null
                            : PaymentIntent::query()->whereKey($order->payment_intent_id)->lockForUpdate()->first();
                        if ($payment?->status === 'reconciliation_required') {
                            Log::critical('Mad Warung order with an uncertain gateway outcome reached its expiry.', [
                                'order_id' => $order->getKey(),
                                'payment_intent_id' => $payment->getKey(),
                            ]);

                            return false;
                        }

                        $this->inventory->release($order);
                        $order->forceFill(['status' => OrderStatus::Expired->value])->save();
                        if ($payment !== null && in_array($payment->status, ['creating', 'pending'], true)) {
                            $payment->forceFill(['status' => 'expired'])->save();
                        }
                        Notification::send(
                            $order->creator,
                            new OrderStatusNotification($order->code, 'expired'),
                        );

                        return true;
                    }, attempts: 3);
                    if ($expired) {
                        $expiredCount++;
                    }
                }
            });

        return $expiredCount;
    }
}
