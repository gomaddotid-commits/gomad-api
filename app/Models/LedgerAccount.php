<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code',
    'owner_type',
    'owner_id',
    'account_class',
    'purpose',
    'balance_cached',
    'currency',
    'is_active',
])]
class LedgerAccount extends Model
{
    protected function casts(): array
    {
        return [
            'balance_cached' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'account_id');
    }

    public function holds(): HasMany
    {
        return $this->hasMany(WalletHold::class, 'account_id');
    }
}
