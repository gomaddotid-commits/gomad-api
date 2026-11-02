<?php

namespace App\Core\Loyalty\Application;

use App\Models\LoyaltyEvent;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Ringkasan level, progres, dan misi pengguna.
 */
final class LoyaltySummary
{
    /**
     * @return list<array{code: string, name: string, min_points: int}>
     */
    public function levels(): array
    {
        $configured = config('services.loyalty.levels');

        if (is_array($configured) && $configured !== []) {
            return $configured;
        }

        return [
            ['code' => 'bronze', 'name' => 'Bronze', 'min_points' => 0],
            ['code' => 'silver', 'name' => 'Silver', 'min_points' => 100],
            ['code' => 'gold', 'name' => 'Gold', 'min_points' => 300],
            ['code' => 'platinum', 'name' => 'Platinum', 'min_points' => 1000],
        ];
    }

    /**
     * @return array{
     *     points: int,
     *     level: array{code: string, name: string, min_points: int},
     *     next: array{code: string, name: string, min_points: int}|null,
     *     to_next: int|null,
     *     progress_percent: int,
     *     missions: list<array{title: string, description: string, target: int, progress: int, done: bool}>
     * }
     */
    public function for(User $user): array
    {
        $points = (int) $user->points_cached;

        $levels = collect($this->levels())->sortBy('min_points')->values();
        $current = $levels->first();
        $next = null;

        foreach ($levels as $level) {
            if ($points >= (int) $level['min_points']) {
                $current = $level;
            }
        }

        foreach ($levels as $level) {
            if ((int) $level['min_points'] > $points) {
                $next = $level;
                break;
            }
        }

        $toNext = $next !== null ? (int) $next['min_points'] - $points : null;
        $progress = 0;
        if ($next !== null) {
            $span = max(1, (int) $next['min_points'] - (int) $current['min_points']);
            $earned = $points - (int) $current['min_points'];
            $progress = (int) max(0, min(100, round($earned / $span * 100)));
        } else {
            $progress = 100;
        }

        return [
            'points' => $points,
            'level' => $current,
            'next' => $next,
            'to_next' => $toNext,
            'progress_percent' => $progress,
            'missions' => $this->missions($user),
        ];
    }

    /**
     * @return list<array{title: string, description: string, target: int, progress: int, done: bool}>
     */
    private function missions(User $user): array
    {
        $monthStart = Carbon::now()->startOfMonth();

        $travelThisMonth = LoyaltyEvent::query()
            ->where('user_id', $user->getKey())
            ->where('kind', LoyaltyEvent::KIND_TRAVEL_PAID)
            ->where('occurred_at', '>=', $monthStart)
            ->count();

        $rentalThisMonth = LoyaltyEvent::query()
            ->where('user_id', $user->getKey())
            ->where('kind', LoyaltyEvent::KIND_RENTAL_PAID)
            ->where('occurred_at', '>=', $monthStart)
            ->count();

        $referrals = Referral::query()
            ->where('referrer_user_id', $user->getKey())
            ->where('status', Referral::STATUS_REWARDED)
            ->count();

        return [
            [
                'title' => 'Perjalanan pertama bulan ini',
                'description' => 'Selesaikan pembayaran satu tiket Mad Trans.',
                'target' => 1,
                'progress' => min(1, $travelThisMonth),
                'done' => $travelThisMonth >= 1,
            ],
            [
                'title' => 'Sewa kendaraan bulan ini',
                'description' => 'Bayar satu sewa kendaraan (dengan supir, lepas kunci, atau charter).',
                'target' => 1,
                'progress' => min(1, $rentalThisMonth),
                'done' => $rentalThisMonth >= 1,
            ],
            [
                'title' => 'Ajak 1 teman',
                'description' => 'Teman yang Anda undang menyelesaikan transaksi berbayar pertamanya.',
                'target' => 1,
                'progress' => min(1, $referrals),
                'done' => $referrals >= 1,
            ],
            [
                'title' => 'Kumpulkan 100 poin',
                'description' => 'Setiap Rp 10.000 transaksi lunas menambah 1 poin.',
                'target' => 100,
                'progress' => min(100, (int) $user->points_cached),
                'done' => (int) $user->points_cached >= 100,
            ],
        ];
    }
}
