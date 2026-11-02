<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kebijakan radius jemput/pengantaran milik satu usaha.
 */
class BusinessPickupPolicy extends Model
{
    protected $fillable = [
        'business_id',
        'free_radius_km',
        'fee_per_km',
    ];

    protected function casts(): array
    {
        return [
            'free_radius_km' => 'integer',
            'fee_per_km' => 'decimal:2',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
