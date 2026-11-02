<?php

namespace App\Http\Controllers\Web\Shared;

use App\Core\Referral\Application\ReferralCode;
use App\Http\Controllers\Controller;
use App\Models\Promo;
use App\Models\Referral;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReferralController extends Controller
{
    public function index(Request $request, ReferralCode $codes): View
    {
        $user = $request->user();
        $code = $codes->for($user);

        return view('shared.referral.index', [
            'code' => $code,
            'link' => route('register', ['ref' => $code]),
            'invites' => Referral::query()
                ->with('referee:id,name,created_at')
                ->where('referrer_user_id', $user->getKey())
                ->latest()
                ->get(),
            'welcome' => Referral::query()
                ->with('refereePromo:id,code,discount_type,discount_value,max_discount,used_count')
                ->where('referee_user_id', $user->getKey())
                ->first(),
            'rewards' => Promo::query()
                ->whereIn('id', Referral::query()
                    ->where('referrer_user_id', $user->getKey())
                    ->whereNotNull('referrer_promo_id')
                    ->pluck('referrer_promo_id'))
                ->get(),
        ]);
    }
}
