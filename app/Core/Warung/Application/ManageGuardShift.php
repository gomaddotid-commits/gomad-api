<?php

namespace App\Core\Warung\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Models\Business;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ManageGuardShift
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    public function schedule(
        User $owner,
        Business $warung,
        User $guard,
        Carbon $startsAt,
        Carbon $endsAt,
        ?string $notes = null,
    ): Shift {
        $this->authorizer->authorize($owner, 'shift.manage', $warung);
        if ($warung->type !== 'warung' || (int) $warung->owner_user_id !== (int) $owner->getKey()) {
            throw new AuthorizationException('Hanya pemilik warung dapat menjadwalkan shift.');
        }
        if ($endsAt->lessThanOrEqualTo($startsAt) || $startsAt->isPast()) {
            throw new InvalidArgumentException('Jadwal shift harus memiliki waktu selesai setelah waktu mulai di masa depan.');
        }

        return DB::transaction(function () use ($warung, $guard, $startsAt, $endsAt, $notes): Shift {
            $membership = DB::table('business_members')
                ->where('business_id', $warung->getKey())
                ->where('user_id', $guard->getKey())
                ->where('role', 'penjaga')
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            if ($membership === null) {
                throw new InvalidArgumentException('Shift hanya dapat dijadwalkan untuk penjaga aktif warung ini.');
            }

            $overlap = Shift::query()
                ->where('business_id', $warung->getKey())
                ->where('user_id', $guard->getKey())
                ->whereIn('status', ['scheduled', 'active'])
                ->where(function ($query) use ($startsAt, $endsAt): void {
                    $query->whereNull('scheduled_start_at')
                        ->orWhereNull('scheduled_end_at')
                        ->orWhere(function ($overlapQuery) use ($startsAt, $endsAt): void {
                            $overlapQuery->where('scheduled_start_at', '<', $endsAt)
                                ->where('scheduled_end_at', '>', $startsAt);
                        });
                })
                ->exists();
            if ($overlap) {
                throw new InvalidArgumentException('Jadwal shift bertumpang tindih dengan shift penjaga yang masih aktif.');
            }

            return Shift::query()->create([
                'business_id' => $warung->getKey(),
                'user_id' => $guard->getKey(),
                'scheduled_start_at' => $startsAt,
                'scheduled_end_at' => $endsAt,
                'status' => 'scheduled',
                'notes' => $notes,
            ]);
        }, attempts: 3);
    }

    public function clockIn(User $guard, Shift $shift): Shift
    {
        $this->authorizeOwnShift($guard, $shift);

        return DB::transaction(function () use ($guard, $shift): Shift {
            $locked = Shift::query()->whereKey($shift->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === 'active' && (int) $locked->user_id === (int) $guard->getKey()) {
                return $locked;
            }
            if ($locked->status !== 'scheduled') {
                throw new InvalidArgumentException('Hanya shift terjadwal yang dapat dimulai.');
            }
            if ($locked->scheduled_start_at?->isFuture()) {
                throw new InvalidArgumentException('Shift belum mencapai waktu mulai terjadwal.');
            }

            $locked->forceFill(['status' => 'active', 'started_at' => now()])->save();

            return $locked;
        }, attempts: 3);
    }

    public function clockOut(User $guard, Shift $shift): Shift
    {
        $this->authorizeOwnShift($guard, $shift);

        return DB::transaction(function () use ($guard, $shift): Shift {
            $locked = Shift::query()->whereKey($shift->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === 'completed' && (int) $locked->user_id === (int) $guard->getKey()) {
                return $locked;
            }
            if ($locked->status !== 'active') {
                throw new InvalidArgumentException('Hanya shift aktif yang dapat diakhiri.');
            }

            $locked->forceFill(['status' => 'completed', 'ended_at' => now()])->save();

            return $locked;
        }, attempts: 3);
    }

    private function authorizeOwnShift(User $guard, Shift $shift): void
    {
        $this->authorizer->authorize($guard, 'shift.self', $shift->business);
        if ($shift->business->type !== 'warung') {
            throw new AuthorizationException('Shift hanya tersedia untuk anggota warung.');
        }
        if ((int) $shift->user_id !== (int) $guard->getKey()) {
            throw new AuthorizationException('Penjaga hanya dapat mengelola shift miliknya sendiri.');
        }
    }
}
