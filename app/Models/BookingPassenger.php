<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingPassenger extends Model
{
    protected $fillable = [
        'booking_id',
        'schedule_id',
        'name',
        'phone',
        'seat_number',
        'seat_snapshot',
        'seat_released_at',
        'boarded_at',
        'no_show_at',
        'cod_fee_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'seat_released_at' => 'datetime',
            'boarded_at' => 'datetime',
            'no_show_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(TravelBooking::class, 'booking_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TravelSchedule::class, 'schedule_id');
    }
}
