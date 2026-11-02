<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['type', 'name', 'code', 'owner_user_id', 'status', 'verified_at'])]
class Business extends Model
{
    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'business_members')
            ->withPivot(['id', 'role', 'status', 'joined_at', 'left_at'])
            ->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(BusinessInvitation::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function wholesaleOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'wholesaler_id');
    }

    public function productPrices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function memberLimits(): HasMany
    {
        return $this->hasMany(MemberLimit::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function travelRoutes(): HasMany
    {
        return $this->hasMany(TravelRoute::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function travelSchedules(): HasMany
    {
        return $this->hasMany(TravelSchedule::class);
    }
}
