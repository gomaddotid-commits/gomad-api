<?php

namespace App\Models;

use App\Core\Rental\Domain\RentalStatus;
use App\Core\Rental\Domain\RentalType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rental extends Model
{
    protected $fillable = [
        'code',
        'business_id',
        'vehicle_id',
        'customer_id',
        'driver_id',
        'rental_type',
        'payment_mode',
        'ots_fee_transaction_id',
        'itinerary',
        'start_at',
        'end_at',
        'days',
        'price',
        'driver_fee',
        'service_fee',
        'platform_fee',
        'discount_amount',
        'promo_id',
        'promo_code',
        'discount_bearer',
        'ppn_amount',
        'total_price',
        'deposit_amount',
        'deposit_status',
        'deposit_returned_at',
        'deposit_deducted_amount',
        'deposit_refunded_amount',
        'deposit_deduction_reason',
        'status',
        'idempotency_key',
        'request_hash',
        'paid_at',
        'started_at',
        'returned_at',
        'completed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'paid_at' => 'datetime',
            'started_at' => 'datetime',
            'returned_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'deposit_returned_at' => 'datetime',
            'price' => 'decimal:2',
            'driver_fee' => 'decimal:2',
            'service_fee' => 'decimal:2',
            'platform_fee' => 'decimal:2',
            'ppn_amount' => 'decimal:2',
            'total_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'deposit_deducted_amount' => 'decimal:2',
            'deposit_refunded_amount' => 'decimal:2',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function promo(): BelongsTo
    {
        return $this->belongsTo(Promo::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function driverMembership(): BelongsTo
    {
        return $this->belongsTo(BusinessMember::class, 'driver_id');
    }

    public function statusEnum(): RentalStatus
    {
        return RentalStatus::from($this->status);
    }

    public function typeEnum(): RentalType
    {
        return RentalType::from($this->rental_type);
    }
}
