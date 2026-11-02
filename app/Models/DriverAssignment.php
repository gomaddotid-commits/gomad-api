<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverAssignment extends Model
{
    protected $fillable = ['schedule_id', 'driver_id', 'status', 'assigned_by'];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TravelSchedule::class, 'schedule_id');
    }

    public function driverMembership(): BelongsTo
    {
        return $this->belongsTo(BusinessMember::class, 'driver_id');
    }
}
