<?php

namespace App\Http\Controllers\Web\Platform\Admin;

use App\Core\Promo\Application\CreatePromo;
use App\Core\Promo\Domain\PromoCostBearer;
use App\Http\Controllers\Controller;
use App\Models\PlatformUserRole;
use App\Models\Promo;
use App\Models\PromoRedemption;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Promo yang dibuat admin platform. Verifikasi/pembuatan hanya untuk admin GoMad,
 * bukan agency — sama seperti verifikasi dokumen sewa.
 */
class AdminPromoController extends Controller
{
    public function index(Request $request): View
    {
        $this->guardAdmin($request);

        return view('platform.admin.promos', [
            'promos' => Promo::query()->where('owner_type', Promo::OWNER_PLATFORM)->latest()->get(),
            'totalDiscount' => (string) PromoRedemption::query()
                ->where('cost_bearer', PromoCostBearer::Platform->value)
                ->sum('discount_amount'),
            'redemptions' => PromoRedemption::query()
                ->with('promo:id,code')
                ->where('cost_bearer', PromoCostBearer::Platform->value)
                ->latest()
                ->limit(20)
                ->get(),
        ]);
    }

    public function store(Request $request, CreatePromo $create): RedirectResponse
    {
        $this->guardAdmin($request);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:120'],
            'discount_type' => ['required', 'in:percent,amount'],
            'discount_value' => ['required', 'numeric', 'min:0.01'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'min_spend' => ['nullable', 'numeric', 'min:0'],
            'applies_to' => ['required', 'in:all,travel,rental'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_user_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $promo = $create->execute(
                $request->user(),
                $data,
                Promo::OWNER_PLATFORM,
                PromoCostBearer::Platform,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['code' => $exception->getMessage()]);
        }

        return back()->with('status', 'Promo platform '.$promo->code.' dibuat (ditanggung platform).');
    }

    private function guardAdmin(Request $request): void
    {
        $user = $request->user();
        abort_unless(
            $user instanceof User
                && PlatformUserRole::query()->where('user_id', $user->getKey())->where('role', 'admin')->exists(),
            403,
        );
    }
}
