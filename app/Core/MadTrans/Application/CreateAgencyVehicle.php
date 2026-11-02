<?php

namespace App\Core\MadTrans\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Models\Business;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final class CreateAgencyVehicle
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    /**
     * @param  list<string>  $seatLayout
     */
    public function execute(
        User $owner,
        Business $agency,
        string $name,
        string $plateNumber,
        array $seatLayout,
        bool $hasDriverSeat = false,
    ): Vehicle {
        $this->authorizer->authorize($owner, 'schedule.manage', $agency);
        if ($agency->type !== 'agency'
            || $agency->status !== 'active'
            || (int) $agency->owner_user_id !== (int) $owner->getKey()) {
            throw new AuthorizationException('Hanya pemilik agency aktif dapat mengelola armada.');
        }
        $name = trim($name);
        $plateNumber = strtoupper(trim($plateNumber));
        $normalizedSeats = array_map(fn (string $seat): string => strtoupper(trim($seat)), $seatLayout);
        if ($name === '' || $plateNumber === '' || count($normalizedSeats) < 1 || count($normalizedSeats) > 50) {
            throw new InvalidArgumentException('Data kendaraan atau jumlah kursi tidak valid.');
        }
        if (count(array_unique($normalizedSeats)) !== count($normalizedSeats)
            || collect($normalizedSeats)->contains(fn (string $seat): bool => ! preg_match('/^[A-Z0-9-]{1,12}$/', $seat))) {
            throw new InvalidArgumentException('Label kursi harus unik dan hanya berisi huruf besar, angka, atau tanda hubung.');
        }

        return Vehicle::query()->create([
            'business_id' => $agency->getKey(),
            'name' => $name,
            'plate_number' => $plateNumber,
            'seat_layout' => $normalizedSeats,
            'has_driver_seat' => $hasDriverSeat,
            'capacity' => count($normalizedSeats),
            'status' => 'active',
        ]);
    }
}
