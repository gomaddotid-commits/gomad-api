<?php

namespace App\Core\Promo\Domain;

enum PromoCostBearer: string
{
    case Platform = 'platform';
    case Business = 'business';
}
