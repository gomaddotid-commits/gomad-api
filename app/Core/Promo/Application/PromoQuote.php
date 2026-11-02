<?php

namespace App\Core\Promo\Application;

use App\Core\Ledger\Domain\LedgerMoney;
use App\Core\Promo\Domain\PromoCostBearer;
use App\Models\Promo;

final readonly class PromoQuote
{
    public function __construct(
        public Promo $promo,
        public LedgerMoney $discount,
        public PromoCostBearer $bearer,
        public ?int $businessId,
    ) {}

    public function isPlatformBorne(): bool
    {
        return $this->bearer === PromoCostBearer::Platform;
    }
}
