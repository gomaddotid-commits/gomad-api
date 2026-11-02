<?php

namespace App\Core\Warung\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Ledger\Domain\LedgerMoney;
use App\Models\Business;
use App\Models\MemberLimit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SetMemberLimit
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    public function execute(
        User $owner,
        Business $warung,
        User $member,
        string|int $dailyAmountLimit,
        string|int $perOrderLimit,
        string|int $requiresApprovalAbove,
    ): MemberLimit {
        $this->authorizer->authorize($owner, 'member_limit.manage', $warung);
        if ($warung->type !== 'warung' || (int) $warung->owner_user_id !== (int) $owner->getKey()) {
            throw new AuthorizationException('Hanya pemilik warung dapat mengatur plafon penjaga.');
        }
        $membership = DB::table('business_members')
            ->where('business_id', $warung->getKey())
            ->where('user_id', $member->getKey())
            ->where('role', 'penjaga')
            ->where('status', 'active')
            ->exists();
        if (! $membership) {
            throw new InvalidArgumentException('Plafon hanya dapat diatur untuk penjaga warung yang aktif.');
        }
        $daily = LedgerMoney::from($dailyAmountLimit);
        $perOrder = LedgerMoney::from($perOrderLimit);
        $approval = LedgerMoney::from($requiresApprovalAbove);
        foreach ([$daily, $perOrder, $approval] as $amount) {
            if ($amount->toCents() % 100 !== 0) {
                throw new InvalidArgumentException('Semua plafon harus berupa rupiah bulat.');
            }
        }

        return MemberLimit::query()->updateOrCreate(
            ['business_id' => $warung->getKey(), 'user_id' => $member->getKey()],
            [
                'daily_amount_limit' => $daily->toDecimal(),
                'per_order_limit' => $perOrder->toDecimal(),
                'requires_approval_above' => $approval->toDecimal(),
                'updated_by' => $owner->getKey(),
            ],
        );
    }
}
