<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Referral extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_QUALIFIED = 'qualified';

    public const STATUS_REWARDED = 'rewarded';

    protected $fillable = [
        'referrer_user_id',
        'referee_user_id',
        'code',
        'status',
        'referee_promo_id',
        'referrer_promo_id',
        'qualified_at',
        'rewarded_at',
    ];

    protected function casts(): array
    {
        return [
            'qualified_at' => 'datetime',
            'rewarded_at' => 'datetime',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referee_user_id');
    }

    public function refereePromo(): BelongsTo
    {
        return $this->belongsTo(Promo::class, 'referee_promo_id');
    }

    public function referrerPromo(): BelongsTo
    {
        return $this->belongsTo(Promo::class, 'referrer_promo_id');
    }
}
