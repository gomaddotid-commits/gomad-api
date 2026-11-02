<?php

namespace App\Core\MadTrans\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\MadTrans\Domain\ScheduleStatus;
use App\Core\Payment\Domain\PaymentConflict;
use App\Models\BusinessMember;
use App\Models\TravelSchedule;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class StartTravelSchedule
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    public function execute(User $actor, TravelSchedule $schedule): TravelSchedule
    {
        $agency = $schedule->business;
        $this->authorizer->authorize($actor, 'trip.manage', $agency);
        $isOwner = (int) $agency->owner_user_id === (int) $actor->getKey();
        $isAssignedDriver = $schedule->driver_id !== null
            && BusinessMember::query()
                ->whereKey($schedule->driver_id)
                ->where('user_id', $actor->getKey())
                ->where('business_id', $agency->getKey())
                ->where('role', 'driver')
                ->where('status', 'active')
                ->exists();
        if (! $isOwner && ! $isAssignedDriver) {
            throw new AuthorizationException('Hanya pemilik atau pengemudi yang ditugaskan dapat memulai perjalanan.');
        }

        return DB::transaction(function () use ($schedule): TravelSchedule {
            $locked = TravelSchedule::query()->whereKey($schedule->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === ScheduleStatus::InProgress->value) {
                return $locked;
            }
            if ($locked->status !== ScheduleStatus::Published->value) {
                throw new PaymentConflict('Jadwal tidak siap untuk dimulai.');
            }
            $departureAt = Carbon::parse(
                $locked->departure_date->format('Y-m-d').' '.$locked->departure_time,
            );
            if ($departureAt->isFuture()) {
                throw new PaymentConflict('Perjalanan belum mencapai waktu keberangkatan.');
            }

            $locked->forceFill([
                'status' => ScheduleStatus::InProgress->value,
                'started_at' => now(),
            ])->save();

            return $locked;
        }, attempts: 3);
    }
}
