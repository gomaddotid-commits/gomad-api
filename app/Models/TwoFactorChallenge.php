<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TwoFactorChallenge extends Model
{
    public const PURPOSE_LOGIN = 'login';

    public const PURPOSE_ENABLE = 'enable';

    public const MAX_ATTEMPTS = 5;

    protected $fillable = [
        'user_id',
        'purpose',
        'channel',
        'code_hash',
        'token_hash',
        'attempts',
        'expires_at',
        'consumed_at',
        'requested_ip',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < self::MAX_ATTEMPTS;
    }
}
