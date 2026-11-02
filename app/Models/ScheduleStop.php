<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleStop extends Model
{
    protected $fillable = ['schedule_id', 'route_stop_id', 'stop_order', 'pickup_zone'];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TravelSchedule::class, 'schedule_id');
    }

    public function routeStop(): BelongsTo
    {
        return $this->belongsTo(RouteStop::class);
    }
}
