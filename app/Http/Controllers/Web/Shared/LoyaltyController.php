<?php

namespace App\Http\Controllers\Web\Shared;

use App\Core\Loyalty\Application\LoyaltySummary;
use App\Http\Controllers\Controller;
use App\Models\LoyaltyEvent;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LoyaltyController extends Controller
{
    public function index(Request $request, LoyaltySummary $summary): View
    {
        $user = $request->user();

        return view('shared.loyalty.index', [
            'summary' => $summary->for($user),
            'events' => LoyaltyEvent::query()
                ->where('user_id', $user->getKey())
                ->latest('occurred_at')
                ->limit(20)
                ->get(),
        ]);
    }
}
