<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class UserDevice extends Model
{
    protected $fillable = ['user_id', 'platform', 'push_token', 'push_token_hash', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'push_token' => 'encrypted',
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $device): void {
            if ($device->isDirty(['user_id', 'push_token', 'push_token_hash'])) {
                throw new LogicException('Perangkat immutable; daftarkan token baru.');
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
