<?php

namespace App\Core\Promo\Application;

use App\Core\Promo\Domain\PromoCostBearer;
use App\Core\Promo\Domain\PromoDiscountType;
use App\Models\Business;
use App\Models\Promo;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreatePromo
{
    /**
     * @param  array{code: string, name: string, discount_type: string, discount_value: numeric, max_discount?: numeric|null, min_spend?: numeric|null, applies_to?: string, starts_at?: string|null, ends_at?: string|null, usage_limit?: int|null, per_user_limit?: int|null, status?: string}  $data
     */
    public function execute(
        User $actor,
        array $data,
        string $ownerType,
        PromoCostBearer $costBearer,
        ?Business $business = null,
    ): Promo {
        if (! in_array($ownerType, [Promo::OWNER_PLATFORM, Promo::OWNER_BUSINESS], true)) {
            throw new InvalidArgumentException('Pemilik promo tidak dikenal.');
        }

        if ($ownerType === Promo::OWNER_PLATFORM && $business !== null) {
            throw new InvalidArgumentException('Promo platform tidak boleh terikat pada satu usaha.');
        }

        if ($ownerType === Promo::OWNER_BUSINESS && $business === null) {
            throw new InvalidArgumentException('Promo usaha wajib menyebutkan usaha pemiliknya.');
        }

        if ($ownerType === Promo::OWNER_BUSINESS && $costBearer !== PromoCostBearer::Business) {
            throw new InvalidArgumentException('Promo milik usaha harus ditanggung usaha itu sendiri.');
        }

        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{2,39}$/', $code) !== 1) {
            throw new InvalidArgumentException('Kode promo 3–40 karakter, hanya huruf, angka, minus, dan garis bawah.');
        }

        if (Promo::query()->where('code', $code)->exists()) {
            throw new InvalidArgumentException('Kode promo sudah dipakai.');
        }

        $name = trim((string) ($data['name'] ?? ''));
        if (mb_strlen($name) < 3) {
            throw new InvalidArgumentException('Nama promo minimal 3 karakter.');
        }

        $discountType = PromoDiscountType::tryFrom((string) ($data['discount_type'] ?? ''));
        if ($discountType === null) {
            throw new InvalidArgumentException('Jenis diskon promo tidak dikenal.');
        }

        $value = (float) ($data['discount_value'] ?? 0);
        if ($value <= 0) {
            throw new InvalidArgumentException('Nilai diskon promo harus lebih besar dari nol.');
        }
        if ($discountType === PromoDiscountType::Percent && $value > 100) {
            throw new InvalidArgumentException('Persentase diskon maksimal 100%.');
        }

        $appliesTo = (string) ($data['applies_to'] ?? 'all');
        if (! in_array($appliesTo, Promo::APPLIES_TO, true)) {
            throw new InvalidArgumentException('Cakupan promo tidak dikenal.');
        }

        $perUserLimit = (int) ($data['per_user_limit'] ?? 1);
        if ($perUserLimit < 1 || $perUserLimit > 100) {
            throw new InvalidArgumentException('Batas pemakaian per pengguna harus 1–100.');
        }

        $usageLimit = isset($data['usage_limit']) && $data['usage_limit'] !== null && $data['usage_limit'] !== ''
            ? (int) $data['usage_limit']
            : null;
        if ($usageLimit !== null && ($usageLimit < 1 || $usageLimit > 1000000)) {
            throw new InvalidArgumentException('Kuota promo harus antara 1 dan 1.000.000.');
        }

        $startsAt = ! empty($data['starts_at']) ? Carbon::parse((string) $data['starts_at']) : null;
        $endsAt = ! empty($data['ends_at']) ? Carbon::parse((string) $data['ends_at']) : null;
        if ($startsAt !== null && $endsAt !== null && $endsAt->lte($startsAt)) {
            throw new InvalidArgumentException('Akhir masa promo harus setelah awalnya.');
        }

        $status = (string) ($data['status'] ?? 'active');
        if (! in_array($status, ['active', 'inactive'], true)) {
            throw new InvalidArgumentException('Status promo tidak dikenal.');
        }

        return DB::transaction(fn (): Promo => Promo::query()->create([
            'code' => $code,
            'name' => $name,
            'owner_type' => $ownerType,
            'business_id' => $business?->getKey(),
            'cost_bearer' => $costBearer->value,
            'discount_type' => $discountType->value,
            'discount_value' => number_format($value, 2, '.', ''),
            'max_discount' => isset($data['max_discount']) && $data['max_discount'] !== null && $data['max_discount'] !== ''
                ? number_format((float) $data['max_discount'], 2, '.', '')
                : null,
            'min_spend' => number_format((float) ($data['min_spend'] ?? 0), 2, '.', ''),
            'applies_to' => $appliesTo,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'usage_limit' => $usageLimit,
            'per_user_limit' => $perUserLimit,
            'status' => $status,
            'created_by' => $actor->getKey(),
        ]), attempts: 3);
    }
}
