<?php

namespace App\Http\Resources;

use App\Models\RetailSale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu nota kasir.
 *
 * Nominal dikirim apa adanya (desimal string, seperti kontrak lain di repo ini);
 * aplikasi klien yang memformat. `change_amount` ikut dikirim karena kasir
 * membutuhkannya saat menutup transaksi.
 *
 * @mixin RetailSale
 */
class RetailSaleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'code' => $this->code,
            'business_id' => (int) $this->business_id,
            'sold_by' => (int) $this->sold_by,
            'seller_name' => $this->whenLoaded('seller', fn () => $this->seller?->name),
            'shift_id' => $this->shift_id,
            'payment_method' => $this->payment_method,
            'total_amount' => $this->total_amount,
            'paid_amount' => $this->paid_amount,
            'change_amount' => $this->change_amount,
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'unit' => $item->unit,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'subtotal' => $item->subtotal,
            ])->all()),
        ];
    }
}
