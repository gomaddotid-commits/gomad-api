<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Core\Ledger\Domain\LedgerMoney;
use App\Core\Loyalty\Application\LoyaltySummary;
use App\Core\Promo\Application\ResolvePromo;
use App\Core\Referral\Application\ReferralCode;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Promo;
use App\Models\Referral;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Promo, referral, dan level untuk klien mobile — memakai kelas Core yang sama dengan web.
 */
class EngagementController extends Controller
{
    public function validatePromo(Request $request, ResolvePromo $resolve): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'applies_to' => ['required', 'in:travel,rental'],
            'business_id' => ['nullable', 'integer', 'exists:businesses,id'],
            'base_amount' => ['required', 'numeric', 'min:1'],
        ]);

        $business = isset($data['business_id']) ? Business::query()->find($data['business_id']) : null;

        try {
            $quote = $resolve->execute(
                $data['code'],
                $data['applies_to'],
                $business,
                $request->user(),
                LedgerMoney::from(number_format((float) $data['base_amount'], 2, '.', '')),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'PROMO_REJECTED',
                'errors' => (object) [],
            ], 422);
        }

        return response()->json([
            'data' => [
                'code' => $quote->promo->code,
                'name' => $quote->promo->name,
                'discount_amount' => $quote->discount->toDecimal(),
                'cost_bearer' => $quote->bearer->value,
                'applies_to' => $quote->promo->applies_to,
            ],
        ]);
    }

    public function referral(Request $request, ReferralCode $codes): JsonResponse
    {
        $user = $request->user();
        $code = $codes->for($user);

        $invites = Referral::query()
            ->with(['referee:id,name', 'referrerPromo:id,code,discount_type,discount_value,max_discount,used_count'])
            ->where('referrer_user_id', $user->getKey())
            ->latest()
            ->get();

        $welcome = Referral::query()
            ->with('refereePromo:id,code,discount_type,discount_value,max_discount,used_count')
            ->where('referee_user_id', $user->getKey())
            ->first();

        return response()->json([
            'data' => [
                'code' => $code,
                'share_url' => route('register', ['ref' => $code]),
                'invites' => $invites->map(fn (Referral $referral): array => [
                    'name' => $referral->referee?->name,
                    'status' => $referral->status,
                    'joined_at' => $referral->created_at?->toIso8601String(),
                ])->all(),
                'rewards' => $invites
                    ->pluck('referrerPromo')
                    ->filter()
                    ->unique('id')
                    ->values()
                    ->map(fn (Promo $promo): array => [
                        'code' => $promo->code,
                        'discount_type' => $promo->discount_type,
                        'discount_value' => (string) $promo->discount_value,
                        'max_discount' => (string) ($promo->max_discount ?? '0.00'),
                        'cost_bearer' => $promo->cost_bearer,
                        'used_count' => (int) $promo->used_count,
                    ])
                    ->all(),
                'welcome_promo' => $welcome?->refereePromo === null ? null : [
                    'code' => $welcome->refereePromo->code,
                    'discount_type' => $welcome->refereePromo->discount_type,
                    'discount_value' => (string) $welcome->refereePromo->discount_value,
                    'max_discount' => (string) ($welcome->refereePromo->max_discount ?? '0.00'),
                    'used_count' => (int) $welcome->refereePromo->used_count,
                ],
            ],
        ]);
    }

    public function loyalty(Request $request, LoyaltySummary $summary): JsonResponse
    {
        $data = $summary->for($request->user());

        return response()->json([
            'data' => [
                'points' => $data['points'],
                'level' => $data['level'],
                'next_level' => $data['next'],
                'points_to_next_level' => $data['to_next'],
                'progress_percent' => $data['progress_percent'],
                'missions' => $data['missions'],
            ],
        ]);
    }
}
