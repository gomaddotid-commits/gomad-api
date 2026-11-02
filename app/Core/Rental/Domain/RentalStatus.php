<?php

namespace App\Core\Rental\Domain;

enum RentalStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Active = 'active';
    case Returned = 'returned';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
