<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Harga jual warung untuk satu produk (dasar layar kasir).
 *
 * Dibuat otomatis saat barang dari pesanan grosir diterima, memakai harga beli
 * terakhir sebagai titik awal. Pemilik menyesuaikan harga jualnya; penjaga tidak
 * pernah menentukan harga.
 */
class RetailListing extends Model
{
    protected $fillable = ['business_id', 'product_id', 'price', 'is_active'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
