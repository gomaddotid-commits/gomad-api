<?php

namespace App\Core\Warung\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Notification\Application\OrderStatusNotification;
use App\Core\Payment\Domain\PaymentConflict;
use App\Core\Warung\Domain\OrderStatus;
use App\Models\Order;
use App\Models\RetailListing;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

final class ConfirmOrderReceipt
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    public function execute(User $member, Order $order): Order
    {
        $this->authorizer->authorize($member, 'order.receive', $order->business);
        if ((int) $order->business->owner_user_id !== (int) $member->getKey()
            && ! $order->business->members()->whereKey($member->getKey())->wherePivot('role', 'penjaga')->exists()) {
            throw new AuthorizationException('Hanya pemilik atau penjaga warung dapat mengonfirmasi penerimaan.');
        }

        return DB::transaction(function () use ($member, $order): Order {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $allowedStatus = $locked->delivery_method === 'ambil_sendiri'
                ? OrderStatus::ReadyForPickup->value
                : OrderStatus::InDelivery->value;
            if ($locked->status === OrderStatus::Received->value) {
                if ((int) $locked->received_confirmed_by === (int) $member->getKey()) {
                    return $locked;
                }
                throw new PaymentConflict('Penerimaan pesanan sudah dicatat oleh anggota lain.');
            }
            if ($locked->status !== $allowedStatus) {
                throw new PaymentConflict('Pesanan belum siap diterima.');
            }

            $locked->forceFill([
                'status' => OrderStatus::Received->value,
                'received_confirmed_by' => $member->getKey(),
                'received_at' => now(),
            ])->save();

            $this->registerRetailListings($locked);
            Notification::send(
                $locked->creator,
                new OrderStatusNotification($locked->code, 'received'),
            );
            if ((int) $locked->business->owner_user_id !== (int) $member->getKey()
                && (int) $locked->business->owner_user_id !== (int) $locked->creator->getKey()) {
                Notification::send(
                    $locked->business->owner,
                    new OrderStatusNotification($locked->code, 'received'),
                );
            }

            return $locked;
        }, attempts: 3);
    }

    /**
     * Barang yang baru diterima langsung bisa dijual di kasir.
     *
     * Harga awalnya harga beli terakhir — titik awal yang jujur, bukan markup
     * yang dikarang. `firstOrCreate` menjaga harga yang sudah disesuaikan pemilik
     * tidak tertimpa saat warung memesan barang yang sama lagi.
     */
    private function registerRetailListings(Order $order): void
    {
        if ($order->business->type !== 'warung') {
            return;
        }

        foreach ($order->items as $item) {
            if ($item->product_id === null) {
                continue;
            }

            RetailListing::query()->firstOrCreate(
                [
                    'business_id' => $order->business_id,
                    'product_id' => $item->product_id,
                ],
                [
                    'price' => $item->unit_price,
                    'is_active' => true,
                ],
            );
        }
    }
}
