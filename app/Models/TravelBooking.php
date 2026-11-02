<?php

namespace App\Models;

use App\Core\MadTrans\Domain\BookingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TravelBooking extends Model
{
    protected $table = 'bookings';

    protected $fillable = [
        'code',
        'schedule_id',
        'customer_id',
        'origin_stop_id',
        'destination_stop_id',
        'route_pricing_id',
        'pickup_address',
        'pickup_latitude',
        'pickup_longitude',
        'pickup_distance_km',
        'pickup_fee',
        'destination_address',
        'destination_latitude',
        'destination_longitude',
        'total_passengers',
        'base_price',
        'service_fee',
        'platform_fee',
        'discount_amount',
        'promo_id',
        'promo_code',
        'discount_bearer',
        'ppn_amount',
        'total_price',
        'payment_method',
        'status',
        'idempotency_key',
        'request_hash',
        'payment_intent_id',
        'expires_at',
        'confirmed_at',
        'paid_at',
        'started_at',
        'completed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'pickup_latitude' => 'decimal:7',
            'pickup_longitude' => 'decimal:7',
            'pickup_distance_km' => 'decimal:2',
            'pickup_fee' => 'decimal:2',
            'destination_latitude' => 'decimal:7',
            'destination_longitude' => 'decimal:7',
            'base_price' => 'decimal:2',
            'service_fee' => 'decimal:2',
            'platform_fee' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'ppn_amount' => 'decimal:2',
            'total_price' => 'decimal:2',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'paid_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TravelSchedule::class, 'schedule_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function promo(): BelongsTo
    {
        return $this->belongsTo(Promo::class);
    }

    public function passengers(): HasMany
    {
        return $this->hasMany(BookingPassenger::class, 'booking_id');
    }

    public function originStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class, 'origin_stop_id');
    }

    public function destinationStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class, 'destination_stop_id');
    }

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }

    public function routePricing(): BelongsTo
    {
        return $this->belongsTo(RoutePricing::class, 'route_pricing_id');
    }

    public function statusEnum(): BookingStatus
    {
        return BookingStatus::from($this->status);
    }
}
