<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu transaksi kasir.
 *
 * Tidak punya `status`: penjualan tunai di meja warung selesai saat uang
 * diterima. Membatalkannya bukan "ubah status" melainkan transaksi terpisah
 * (retur) — dan itu belum ada.
 */
class RetailSale extends Model
{
    protected $fillable = [
        'code',
        'business_id',
        'sold_by',
        'shift_id',
        'payment_method',
        'total_amount',
        'paid_amount',
        'change_amount',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'change_amount' => 'decimal:2',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RetailSaleItem::class);
    }
}
