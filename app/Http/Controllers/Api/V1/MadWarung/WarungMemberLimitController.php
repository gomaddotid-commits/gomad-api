<?php

namespace App\Http\Controllers\Api\V1\MadWarung;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Warung\Application\SetMemberLimit;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\MemberLimit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarungMemberLimitController extends Controller
{
    public function index(Business $business, ActionAuthorizer $authorizer): JsonResponse
    {
        $authorizer->authorize(request()->user(), 'member_limit.manage', $business);
        abort_unless($business->type === 'warung', 404);

        $limits = MemberLimit::query()
            ->with('user:id,name,phone')
            ->where('business_id', $business->getKey())
            ->orderBy('user_id')
            ->get()
            ->map(fn (MemberLimit $limit): array => $this->limitData($limit));

        return response()->json(['data' => $limits]);
    }

    public function update(
        Request $request,
        Business $business,
        User $member,
        SetMemberLimit $setLimit,
    ): JsonResponse {
        $data = $request->validate([
            'daily_amount_limit' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(?:\.00)?$/'],
            'per_order_limit' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(?:\.00)?$/'],
            'requires_approval_above' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(?:\.00)?$/'],
        ]);
        $limit = $setLimit->execute(
            $request->user(),
            $business,
            $member,
            $data['daily_amount_limit'],
            $data['per_order_limit'],
            $data['requires_approval_above'],
        );

        return response()->json(['data' => $this->limitData($limit)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function limitData(MemberLimit $limit): array
    {
        return [
            'user_id' => $limit->user_id,
            'name' => $limit->user?->name,
            'phone' => $limit->user?->phone,
            'daily_amount_limit' => $limit->daily_amount_limit,
            'per_order_limit' => $limit->per_order_limit,
            'requires_approval_above' => $limit->requires_approval_above,
        ];
    }
}
