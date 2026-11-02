<?php

namespace App\Models;

use App\Core\Promo\Domain\PromoCostBearer;
use App\Core\Promo\Domain\PromoDiscountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promo extends Model
{
    public const OWNER_PLATFORM = 'platform';

    public const OWNER_BUSINESS = 'business';

    public const APPLIES_TO = ['all', 'travel', 'rental'];

    protected $fillable = [
        'code',
        'name',
        'owner_type',
        'business_id',
        'cost_bearer',
        'discount_type',
        'discount_value',
        'max_discount',
        'min_spend',
        'applies_to',
        'starts_at',
        'ends_at',
        'usage_limit',
        'used_count',
        'per_user_limit',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'min_spend' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'usage_limit' => 'integer',
            'used_count' => 'integer',
            'per_user_limit' => 'integer',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoRedemption::class);
    }

    public function bearer(): PromoCostBearer
    {
        return PromoCostBearer::from($this->cost_bearer);
    }

    public function discountType(): PromoDiscountType
    {
        return PromoDiscountType::from($this->discount_type);
    }

    public function isPlatformPromo(): bool
    {
        return $this->owner_type === self::OWNER_PLATFORM;
    }
}
