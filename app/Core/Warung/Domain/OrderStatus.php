<?php

namespace App\Core\Warung\Domain;

enum OrderStatus: string
{
    case PendingApproval = 'pending_approval';
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case InDelivery = 'in_delivery';
    case ReadyForPickup = 'ready_for_pickup';
    case Received = 'received';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function reservesInventory(): bool
    {
        return in_array($this, [
            self::PendingApproval,
            self::PendingPayment,
            self::Paid,
            self::Preparing,
            self::Ready,
            self::InDelivery,
            self::ReadyForPickup,
        ], true);
    }
}
