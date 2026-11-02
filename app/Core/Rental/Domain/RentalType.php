<?php

namespace App\Core\Rental\Domain;

enum RentalType: string
{
    case SelfDrive = 'self_drive';
    case WithDriver = 'with_driver';
    case Charter = 'charter';
}
