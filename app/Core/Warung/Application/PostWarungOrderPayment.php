<?php

namespace App\Core\Warung\Application;

use App\Core\Ledger\Application\PostLedgerTransaction;
use App\Core\Ledger\Domain\LedgerCategory;
use App\Core\Ledger\Domain\LedgerDirection;
use App\Core\Ledger\Domain\LedgerEntryInput;
use App\Core\Ledger\Domain\LedgerMoney;
use App\Core\Ledger\Domain\PostLedgerTransactionData;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Core\Notification\Application\OrderStatusNotification;
use App\Core\Payment\Domain\PaymentConflict;
use App\Core\Warung\Domain\OrderStatus;
use App\Models\LedgerTransaction;
use App\Models\Order;
use App\Models\PaymentIntent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

final class PostWarungOrderPayment
{
    public function __construct(
        private readonly PostLedgerTransaction $postTransaction,
        private readonly LedgerAccountSeeder $accountSeeder,
        private readonly OrderInventory $inventory,
    ) {}

    public function execute(PaymentIntent $payment): LedgerTransaction
    {
        if ($payment->purpose !== 'warung_order' || $payment->order_id === null) {
            throw new \LogicException('Payment intent tidak terhubung ke pesanan Mad Warung.');
        }

        return DB::transaction(function () use ($payment): LedgerTransaction {
            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            if ($order->status === OrderStatus::Paid->value
                || in_array($order->status, [
                    OrderStatus::Preparing->value,
                    OrderStatus::Ready->value,
                    OrderStatus::InDelivery->value,
                    OrderStatus::ReadyForPickup->value,
                    OrderStatus::Received->value,
                    OrderStatus::Completed->value,
                ], true)) {
                return LedgerTransaction::query()
                    ->where('idempotency_key', 'midtrans:payment:'.$payment->getKey())
                    ->firstOrFail();
            }
            if ($order->status !== OrderStatus::PendingPayment->value) {
                Log::critical('Paid Midtrans callback arrived for a Mad Warung order that is no longer payable.', [
                    'order_id' => $order->getKey(),
                    'payment_intent_id' => $payment->getKey(),
                    'order_status' => $order->status,
                ]);
                throw new PaymentConflict('Pembayaran diterima untuk pesanan yang tidak lagi dapat dibayar; perlu rekonsiliasi manual.');
            }
            if ((int) $order->business->owner_user_id !== (int) $payment->user_id
                || (int) $payment->business_id !== (int) $order->business_id
                || LedgerMoney::from((string) $payment->amount)
                    ->compare(LedgerMoney::from((string) $order->total_amount)) !== 0) {
                throw new PaymentConflict('Data pembayar atau nominal pesanan tidak cocok.');
            }

            $this->accountSeeder->ensureBusinessAccounts($order->wholesaler);
            $entries = [
                new LedgerEntryInput(
                    'PLATFORM.CASH',
                    LedgerDirection::Debit,
                    (string) $order->total_amount,
                ),
                new LedgerEntryInput(
                    'BUSINESS.PAYABLE:'.$order->wholesaler_id,
                    LedgerDirection::Credit,
                    (string) $order->subtotal,
                ),
            ];
            if ((string) $order->platform_fee !== '0.00') {
                $entries[] = new LedgerEntryInput(
                    'PLATFORM.REVENUE.PLATFORM_FEE',
                    LedgerDirection::Credit,
                    (string) $order->platform_fee,
                );
            }
            if ((string) $order->ppn_amount !== '0.00') {
                $entries[] = new LedgerEntryInput(
                    'PLATFORM.TAX_PAYABLE_PPN',
                    LedgerDirection::Credit,
                    (string) $order->ppn_amount,
                );
            }

            $transaction = $this->postTransaction->execute(new PostLedgerTransactionData(
                category: LedgerCategory::OrderPaidToGrosir,
                idempotencyKey: 'midtrans:payment:'.$payment->getKey(),
                entries: $entries,
                referenceType: 'warung_order',
                referenceId: (int) $order->getKey(),
                description: 'Pembayaran pesanan Mad Warung '.$order->code,
                createdBy: (int) $payment->user_id,
            ));
            $this->inventory->capture($order);
            $order->forceFill([
                'status' => OrderStatus::Paid->value,
                'paid_at' => now(),
            ])->save();
            Notification::send(
                $order->creator,
                new OrderStatusNotification($order->code, 'paid'),
            );
            if ((int) $order->wholesaler->owner_user_id !== (int) $order->creator->getKey()) {
                Notification::send(
                    $order->wholesaler->owner,
                    new OrderStatusNotification($order->code, 'paid'),
                );
            }

            return $transaction;
        }, attempts: 3);
    }
}
