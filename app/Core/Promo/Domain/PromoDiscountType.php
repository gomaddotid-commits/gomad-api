<?php

namespace App\Core\Promo\Domain;

enum PromoDiscountType: string
{
    case Percent = 'percent';
    case Amount = 'amount';
}
