<?php

namespace App\Core\Promo\Application;

use App\Core\Ledger\Domain\LedgerMoney;
use App\Core\Promo\Domain\PromoDiscountType;
use App\Models\Business;
use App\Models\Promo;
use App\Models\PromoRedemption;
use App\Models\User;
use InvalidArgumentException;

/**
 * Memvalidasi kode promo dan menghitung nominal diskon.
 *
 * Diskon hanya menyentuh bagian penjual (harga tiket / harga sewa + tarif supir),
 * tidak pernah biaya layanan, fee platform, PPN, atau deposit.
 */
final class ResolvePromo
{
    public function execute(
        ?string $code,
        string $appliesTo,
        ?Business $business,
        User $customer,
        LedgerMoney $base,
    ): ?PromoQuote {
        $code = $code !== null ? strtoupper(trim($code)) : '';
        if ($code === '') {
            return null;
        }

        if (! in_array($appliesTo, ['travel', 'rental'], true)) {
            throw new InvalidArgumentException('Jenis transaksi promo tidak dikenal.');
        }

        $promo = Promo::query()->where('code', $code)->first();
        if ($promo === null) {
            throw new InvalidArgumentException('Kode promo tidak ditemukan.');
        }

        if ($promo->status !== 'active') {
            throw new InvalidArgumentException('Kode promo sudah tidak aktif.');
        }

        $now = now();
        if ($promo->starts_at !== null && $promo->starts_at->isAfter($now)) {
            throw new InvalidArgumentException('Kode promo belum dapat dipakai.');
        }
        if ($promo->ends_at !== null && $promo->ends_at->isBefore($now)) {
            throw new InvalidArgumentException('Kode promo sudah kedaluwarsa.');
        }

        if ($promo->applies_to !== 'all' && $promo->applies_to !== $appliesTo) {
            throw new InvalidArgumentException('Kode promo tidak berlaku untuk transaksi ini.');
        }

        if ($promo->isPlatformPromo()) {
            if ($promo->business_id !== null) {
                throw new InvalidArgumentException('Kode promo platform tidak boleh terikat pada satu usaha.');
            }
        } else {
            if ($business === null || (int) $promo->business_id !== (int) $business->getKey()) {
                throw new InvalidArgumentException('Kode promo ini milik usaha lain.');
            }
        }

        $minSpend = LedgerMoney::from((string) $promo->min_spend);
        if ($minSpend->isPositive() && $base->toCents() < $minSpend->toCents()) {
            throw new InvalidArgumentException(
                'Kode promo butuh transaksi minimal Rp '.number_format($minSpend->toCents() / 100, 0, ',', '.').'.'
            );
        }

        if ($promo->usage_limit !== null && $promo->used_count >= $promo->usage_limit) {
            throw new InvalidArgumentException('Kuota kode promo sudah habis.');
        }

        $usedByCustomer = PromoRedemption::query()
            ->where('promo_id', $promo->getKey())
            ->where('user_id', $customer->getKey())
            ->count();
        if ($usedByCustomer >= $promo->per_user_limit) {
            throw new InvalidArgumentException('Kode promo ini sudah pernah Anda pakai.');
        }

        $discount = $this->discountFor($promo, $base);
        if (! $discount->isPositive()) {
            throw new InvalidArgumentException('Kode promo tidak menghasilkan potongan untuk transaksi ini.');
        }

        return new PromoQuote(
            promo: $promo,
            discount: $discount,
            bearer: $promo->bearer(),
            businessId: $business?->getKey(),
        );
    }

    private function discountFor(Promo $promo, LedgerMoney $base): LedgerMoney
    {
        $value = LedgerMoney::from((string) $promo->discount_value);

        if ($promo->discountType() === PromoDiscountType::Percent) {
            $percent = (float) $promo->discount_value;
            if ($percent <= 0 || $percent > 100) {
                throw new InvalidArgumentException('Besar persentase promo tidak valid.');
            }
            $baseCents = $base->toCents();
            $discountCents = (int) floor($baseCents * $percent / 100);
        } else {
            $discountCents = $value->toCents();
        }

        if ($promo->max_discount !== null) {
            $cap = (int) round((float) $promo->max_discount * 100);
            if ($cap > 0) {
                $discountCents = min($discountCents, $cap);
            }
        }

        // Diskon tidak boleh melebihi bagian penjual.
        $discountCents = min($discountCents, $base->toCents());

        return LedgerMoney::from(number_format($discountCents / 100, 2, '.', ''));
    }
}
