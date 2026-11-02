<?php

namespace App\Models;

use App\Core\MadTrans\Domain\ScheduleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TravelSchedule extends Model
{
    protected $table = 'schedules';

    protected $fillable = [
        'business_id',
        'vehicle_id',
        'route_id',
        'driver_id',
        'departure_date',
        'departure_time',
        'travel_class',
        'price_per_seat',
        'status',
        'proposed_by',
        'approved_by',
        'allow_cod',
        'payment_methods',
        'risk_status',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'departure_date' => 'date',
            'price_per_seat' => 'decimal:2',
            'allow_cod' => 'boolean',
            'payment_methods' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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

    public function route(): BelongsTo
    {
        return $this->belongsTo(TravelRoute::class, 'route_id');
    }

    public function driverMembership(): BelongsTo
    {
        return $this->belongsTo(BusinessMember::class, 'driver_id');
    }

    public function stops(): HasMany
    {
        return $this->hasMany(ScheduleStop::class, 'schedule_id')->orderBy('stop_order');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(TravelBooking::class, 'schedule_id');
    }

    /**
     * Penumpang pada jadwal ini. Dipakai pelacakan untuk menghitung yang sudah
     * naik tanpa memuat seluruh booking beserta riwayatnya.
     */
    public function passengers(): HasMany
    {
        return $this->hasMany(BookingPassenger::class, 'schedule_id');
    }

    public function statusEnum(): ScheduleStatus
    {
        return ScheduleStatus::from($this->status);
    }
}
