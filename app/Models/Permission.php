<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Permission extends Model
{
    protected $fillable = ['code', 'label', 'group'];

    public function roles(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }
}
