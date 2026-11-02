<?php

namespace App\Core\MadTrans\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\MadTrans\Domain\ScheduleStatus;
use App\Models\TravelSchedule;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class EnableTravelScheduleCod
{
    public function __construct(
        private readonly ActionAuthorizer $authorizer,
    ) {}

    public function execute(User $owner, TravelSchedule $schedule): TravelSchedule
    {
        $agency = $schedule->business;
        $this->authorizer->authorize($owner, 'schedule.manage', $agency);
        if ((int) $agency->owner_user_id !== (int) $owner->getKey()) {
            throw new AuthorizationException('Hanya pemilik agency dapat mengaktifkan COD.');
        }

        return DB::transaction(function () use ($schedule): TravelSchedule {
            $locked = TravelSchedule::query()->whereKey($schedule->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->allow_cod) {
                return $locked;
            }
            if ($locked->status !== ScheduleStatus::Published->value
                || $locked->business->status !== 'active'
                || $locked->vehicle->status !== 'active') {
                throw new InvalidArgumentException('COD hanya dapat diaktifkan pada jadwal terbit dengan agency dan kendaraan aktif.');
            }

            $locked->forceFill([
                'allow_cod' => true,
                'risk_status' => 'ok',
                'payment_methods' => ['midtrans', 'cod'],
            ])->save();

            return $locked;
        }, attempts: 3);
    }
}
