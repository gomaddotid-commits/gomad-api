<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TravelRoute extends Model
{
    protected $table = 'routes';

    protected $fillable = ['business_id', 'origin_city', 'destination_city', 'status'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function stops(): HasMany
    {
        return $this->hasMany(RouteStop::class, 'route_id')->orderBy('stop_order');
    }

    public function pricing(): HasMany
    {
        return $this->hasMany(RoutePricing::class, 'route_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(TravelSchedule::class, 'route_id');
    }
}
