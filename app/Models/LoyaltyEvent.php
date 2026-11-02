<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyEvent extends Model
{
    public const KIND_TRAVEL_PAID = 'travel_paid';

    public const KIND_RENTAL_PAID = 'rental_paid';

    public const KIND_REFERRAL = 'referral_rewarded';

    protected $fillable = [
        'user_id',
        'kind',
        'points',
        'reference_type',
        'reference_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
