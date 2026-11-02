<?php

namespace App\Core\MadTrans\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\MadTrans\Domain\GeoPoint;
use App\Models\Business;
use App\Models\RouteStop;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Menentukan titik kumpul sebuah stop rute dari tautan peta atau angka koordinat.
 *
 * Owner cukup membuka OpenStreetMap/Google Maps, menyalin tautannya, lalu menempeĺ
 * di sini — tidak perlu API berbayar. Titik inilah yang dipakai menghitung jarak
 * jemput pelanggan.
 */
final class UpdateStopPickupPoint
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    public function execute(User $owner, Business $agency, RouteStop $stop, ?string $input): RouteStop
    {
        $this->authorizer->authorize($owner, 'schedule.manage', $agency);

        if ((int) $stop->route?->business_id !== (int) $agency->getKey()) {
            throw new AuthorizationException('Titik ini bukan milik rute usaha Anda.');
        }

        $point = $input !== null && trim($input) !== '' ? GeoPoint::fromInput($input) : null;

        $stop->forceFill([
            'latitude' => $point?->latitude,
            'longitude' => $point?->longitude,
        ])->save();

        return $stop;
    }
}
