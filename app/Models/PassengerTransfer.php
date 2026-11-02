<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PassengerTransfer extends Model
{
    protected $fillable = [
        'booking_id',
        'from_schedule_id',
        'to_schedule_id',
        'fee',
        'status',
        'approved_by',
        'note',
    ];

    protected function casts(): array
    {
        return ['fee' => 'decimal:2'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(TravelBooking::class, 'booking_id');
    }

    public function fromSchedule(): BelongsTo
    {
        return $this->belongsTo(TravelSchedule::class, 'from_schedule_id');
    }

    public function toSchedule(): BelongsTo
    {
        return $this->belongsTo(TravelSchedule::class, 'to_schedule_id');
    }
}
