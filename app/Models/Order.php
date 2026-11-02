<?php

namespace App\Models;

use App\Core\Warung\Domain\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'code',
        'business_id',
        'wholesaler_id',
        'created_by',
        'approved_by',
        'status',
        'subtotal',
        'platform_fee',
        'ppn_amount',
        'total_amount',
        'delivery_method',
        'delivery_address',
        'idempotency_key',
        'request_hash',
        'payment_intent_id',
        'received_confirmed_by',
        'approved_at',
        'paid_at',
        'ready_at',
        'received_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'platform_fee' => 'decimal:2',
            'ppn_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'ready_at' => 'datetime',
            'received_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function wholesaler(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'wholesaler_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(OrderApproval::class);
    }

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }

    public function requiresInventoryReservation(): bool
    {
        return OrderStatus::from($this->status)->reservesInventory();
    }
}
