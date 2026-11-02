<?php

namespace App\Http\Resources;

use App\Models\RetailListing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu barang yang bisa dijual di kasir.
 *
 * Harga diambil dari `retail_listings` (harga yang ditetapkan pemilik), bukan
 * dari katalog grosir — persis nilai yang nanti dipakai `RecordRetailSale`.
 * Aplikasi hanya menampilkan; tidak menghitung ulang.
 *
 * @mixin RetailListing
 */
class RetailListingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'product_id' => (int) $this->product_id,
            'name' => (string) $this->product?->name,
            // SKU dan satuan bisa kosong di database; kontrak menyebutnya teks,
            // jadi dinormalkan ke string kosong agar klien bertipe tetap sah.
            'sku' => (string) $this->product?->sku,
            'unit' => (string) $this->product?->unit,
            'category' => $this->product?->category,
            'price' => (string) $this->price,
        ];
    }
}
