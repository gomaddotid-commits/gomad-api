<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberLimit extends Model
{
    protected $fillable = [
        'business_id',
        'user_id',
        'daily_amount_limit',
        'per_order_limit',
        'requires_approval_above',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'daily_amount_limit' => 'decimal:2',
            'per_order_limit' => 'decimal:2',
            'requires_approval_above' => 'decimal:2',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
