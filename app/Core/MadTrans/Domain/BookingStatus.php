<?php

namespace App\Core\MadTrans\Domain;

enum BookingStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Paid = 'paid';
    case OnGoing = 'on_going';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
