<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class LedgerTransaction extends Model
{
    protected $fillable = [
        'category',
        'reference_type',
        'reference_id',
        'idempotency_key',
        'request_hash',
        'amount',
        'status',
        'description',
        'occurred_at',
        'created_by',
        'reversal_of',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $transaction): void {
            $dirty = array_diff(array_keys($transaction->getDirty()), ['status', 'updated_at']);
            if ($dirty !== [] || ($transaction->isDirty('status')
                && ($transaction->getOriginal('status') !== 'posted' || $transaction->status !== 'reversed'))) {
                throw new LogicException('Ledger transaction is immutable except for its one-way reversal status.');
            }
        });
        static::deleting(fn () => throw new LogicException('Ledger transactions cannot be deleted.'));
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'transaction_id');
    }

    public function reversal(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of');
    }
}
