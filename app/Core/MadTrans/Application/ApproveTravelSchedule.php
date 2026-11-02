<?php

namespace App\Core\MadTrans\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\MadTrans\Domain\ScheduleStatus;
use App\Core\Payment\Domain\PaymentConflict;
use App\Models\TravelSchedule;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ApproveTravelSchedule
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    public function execute(User $owner, TravelSchedule $schedule): TravelSchedule
    {
        $this->authorizer->authorize($owner, 'schedule.approve', $schedule->business);
        if ((int) $schedule->business->owner_user_id !== (int) $owner->getKey()) {
            throw new AuthorizationException('Hanya pemilik agency dapat menyetujui jadwal.');
        }

        return DB::transaction(function () use ($owner, $schedule): TravelSchedule {
            $locked = TravelSchedule::query()->whereKey($schedule->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === ScheduleStatus::Published->value) {
                return $locked;
            }
            if ($locked->status !== ScheduleStatus::PendingApproval->value) {
                throw new PaymentConflict('Jadwal tidak lagi menunggu persetujuan.');
            }
            $departureAt = Carbon::parse(
                $locked->departure_date->format('Y-m-d').' '.$locked->departure_time,
            );
            if ($departureAt->isPast()) {
                throw new PaymentConflict('Jadwal yang sudah dimulai tidak dapat diterbitkan.');
            }
            $locked->forceFill([
                'status' => ScheduleStatus::Published->value,
                'approved_by' => $owner->getKey(),
            ])->save();

            return $locked;
        }, attempts: 3);
    }
}
