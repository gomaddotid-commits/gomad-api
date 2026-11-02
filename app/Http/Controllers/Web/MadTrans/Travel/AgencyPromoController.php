<?php

namespace App\Http\Controllers\Web\MadTrans\Travel;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Promo\Application\CreatePromo;
use App\Core\Promo\Domain\PromoCostBearer;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Promo;
use App\Models\PromoRedemption;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Promo milik agency. Agency hanya boleh membuat promo yang ditanggung dirinya sendiri;
 * promo yang ditanggung platform dibuat admin.
 */
class AgencyPromoController extends Controller
{
    public function index(Request $request, Business $business, ActionAuthorizer $authorizer): View
    {
        abort_unless($business->type === 'agency', 404);
        $authorizer->authorize($request->user(), 'business.update', $business);

        return view('madtrans.travel.promos', [
            'business' => $business,
            'promos' => Promo::query()
                ->where('owner_type', Promo::OWNER_BUSINESS)
                ->where('business_id', $business->getKey())
                ->latest()
                ->get(),
            'redemptions' => PromoRedemption::query()
                ->with('promo:id,code,name')
                ->where('business_id', $business->getKey())
                ->latest()
                ->limit(20)
                ->get(),
            'totalDiscount' => (string) PromoRedemption::query()
                ->where('business_id', $business->getKey())
                ->sum('discount_amount'),
            'borneByBusiness' => (string) PromoRedemption::query()
                ->where('business_id', $business->getKey())
                ->where('cost_bearer', PromoCostBearer::Business->value)
                ->sum('discount_amount'),
        ]);
    }

    public function store(
        Request $request,
        Business $business,
        CreatePromo $create,
        ActionAuthorizer $authorizer,
    ): RedirectResponse {
        abort_unless($business->type === 'agency', 404);
        $authorizer->authorize($request->user(), 'business.update', $business);

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
                Promo::OWNER_BUSINESS,
                PromoCostBearer::Business,
                $business,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['code' => $exception->getMessage()]);
        }

        return back()->with('status', 'Promo '.$promo->code.' dibuat dan ditanggung agency ini.');
    }
}
