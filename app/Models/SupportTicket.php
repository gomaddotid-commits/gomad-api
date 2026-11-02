<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SupportTicket extends Model
{
    public const CATEGORIES = ['pembayaran', 'perjalanan', 'pesanan', 'akun', 'lain'];

    public const STATUSES = ['open', 'answered', 'closed'];

    public const PRIORITIES = ['normal', 'high'];

    protected $fillable = [
        'code',
        'user_id',
        'category',
        'subject',
        'status',
        'priority',
        'related_type',
        'related_id',
        'last_message_at',
        'closed_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id')->orderBy('id');
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }
}
