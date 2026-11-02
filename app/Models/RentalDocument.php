<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalDocument extends Model
{
    public const TYPES = ['ktp', 'sim', 'selfie'];

    protected $fillable = [
        'user_id',
        'type',
        'file_path',
        'original_name',
        'status',
        'verified_by',
        'verified_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function label(): string
    {
        return match ($this->type) {
            'ktp' => 'KTP',
            'sim' => 'SIM',
            'selfie' => 'Selfie',
            default => ucfirst($this->type),
        };
    }
}
