<?php

namespace App\Core\Warung\Application;

use App\Core\Payment\Domain\PaymentConflict;
use App\Models\Order;
use App\Models\ProductPrice;

final class OrderInventory
{
    public function capture(Order $order): void
    {
        $order->loadMissing('items');
        $items = $order->items->sortBy('product_price_id');
        $prices = ProductPrice::query()
            ->whereIn('id', $items->pluck('product_price_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($items as $item) {
            /** @var ProductPrice|null $price */
            $price = $prices->get($item->product_price_id);
            if ($price === null || $price->stock_reserved < $item->quantity || $price->stock_available < $item->quantity) {
                throw new PaymentConflict('Reservasi stok tidak valid; pembayaran perlu rekonsiliasi manual.');
            }
            $price->forceFill([
                'stock_available' => $price->stock_available - $item->quantity,
                'stock_reserved' => $price->stock_reserved - $item->quantity,
            ])->save();
        }
    }

    public function release(Order $order): void
    {
        $order->loadMissing('items');
        $items = $order->items->sortBy('product_price_id');
        $prices = ProductPrice::query()
            ->whereIn('id', $items->pluck('product_price_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($items as $item) {
            /** @var ProductPrice|null $price */
            $price = $prices->get($item->product_price_id);
            if ($price === null || $price->stock_reserved < $item->quantity) {
                throw new PaymentConflict('Reservasi stok tidak valid dan perlu rekonsiliasi manual.');
            }
            $price->forceFill([
                'stock_reserved' => $price->stock_reserved - $item->quantity,
            ])->save();
        }
    }
}
