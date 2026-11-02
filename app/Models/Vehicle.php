<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    protected $fillable = [
        'business_id',
        'name',
        'plate_number',
        'seat_layout',
        'has_driver_seat',
        'capacity',
        'rental_daily_price',
        'rental_driver_daily_fee',
        'rental_deposit',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'seat_layout' => 'array',
            'has_driver_seat' => 'boolean',
            'rental_daily_price' => 'decimal:2',
            'rental_driver_daily_fee' => 'decimal:2',
            'rental_deposit' => 'decimal:2',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(TravelSchedule::class);
    }
}
