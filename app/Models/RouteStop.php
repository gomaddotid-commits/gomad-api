<?php

namespace App\Models;

use App\Core\MadTrans\Domain\GeoPoint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteStop extends Model
{
    protected $fillable = ['route_id', 'stop_order', 'city_name', 'name', 'pickup_zone', 'latitude', 'longitude'];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(TravelRoute::class, 'route_id');
    }

    /**
     * Titik kumpul dalam bentuk koordinat (null bila owner belum menandainya).
     */
    public function point(): ?GeoPoint
    {
        return GeoPoint::maybe($this->latitude, $this->longitude);
    }
}
