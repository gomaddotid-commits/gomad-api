<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class Withdrawal extends Model
{
    protected $fillable = [
        'code',
        'business_id',
        'user_id',
        'requested_by',
        'approved_by',
        'bank_code',
        'bank_account_number',
        'bank_account_name',
        'amount',
        'fee_amount',
        'net_amount',
        'idempotency_key',
        'request_hash',
        'ledger_transaction_id',
        'payout_transaction_id',
        'provider_reference',
        'status',
        'failure_code',
        'failure_message',
        'approved_at',
        'paid_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'bank_account_number' => 'encrypted',
            'bank_account_name' => 'encrypted',
            'amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $withdrawal): void {
            $immutable = [
                'code', 'business_id', 'user_id', 'requested_by', 'bank_code',
                'bank_account_number', 'bank_account_name', 'amount', 'fee_amount',
                'net_amount', 'idempotency_key', 'ledger_transaction_id',
            ];
            if (array_intersect(array_keys($withdrawal->getDirty()), $immutable) !== []) {
                throw new LogicException('Detail pencairan tidak dapat diubah setelah dibuat.');
            }
        });
        static::deleting(fn () => throw new LogicException('Permintaan pencairan tidak dapat dihapus.'));
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ledgerTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class);
    }
}
