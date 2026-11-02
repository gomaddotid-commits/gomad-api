<?php

namespace App\Core\MadTrans\Domain;

use InvalidArgumentException;

/**
 * Titik koordinat di permukaan bumi.
 *
 * Dipakai untuk titik kumpul (rute) dan titik jemput pelanggan. Semua masukan
 * dari manusia — tempelan tautan Google Maps/OpenStreetMap atau angka manual —
 * harus lewat `fromInput()` agar formatnya seragam dan tidak ada koordinat liar
 * yang lolos ke perhitungan jarak.
 */
final class GeoPoint
{
    private function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
    ) {}

    public static function fromCoordinates(float|string $latitude, float|string $longitude): self
    {
        $lat = (float) $latitude;
        $lng = (float) $longitude;

        if (! is_finite($lat) || ! is_finite($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            throw new InvalidArgumentException('Koordinat di luar rentang yang wajar.');
        }

        return new self($lat, $lng);
    }

    /**
     * Terima "lat, lng" atau tautan Google Maps / OpenStreetMap.
     */
    public static function fromInput(string $input): self
    {
        $value = trim($input);

        if ($value === '') {
            throw new InvalidArgumentException('Koordinat belum diisi.');
        }

        if (preg_match('/^(-?\d{1,3}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)$/', $value, $matches) === 1) {
            return self::fromCoordinates($matches[1], $matches[2]);
        }

        // OpenStreetMap: #map=17/-7.123456/112.123456 atau ?mlat=-7.12&mlon=112.12
        if (preg_match('/#map=[\d.]+\/(-?\d+(?:\.\d+)?)\/(-?\d+(?:\.\d+)?)/', $value, $matches) === 1) {
            return self::fromCoordinates($matches[1], $matches[2]);
        }

        if (preg_match('/[?&]mlat=(-?\d+(?:\.\d+)?).*?[?&]mlon=(-?\d+(?:\.\d+)?)/', $value, $matches) === 1) {
            return self::fromCoordinates($matches[1], $matches[2]);
        }

        // Google Maps: /@-7.123456,112.123456,17z  atau  ?q=-7.123456,112.123456
        if (preg_match('/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/', $value, $matches) === 1) {
            return self::fromCoordinates($matches[1], $matches[2]);
        }

        if (preg_match('/[?&](?:q|query|ll|destination)=(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)/', $value, $matches) === 1) {
            return self::fromCoordinates($matches[1], $matches[2]);
        }

        throw new InvalidArgumentException('Koordinat tidak dikenali. Tempel "lat, lng" atau tautan peta.');
    }

    public static function maybe(string|float|null $latitude, string|float|null $longitude): ?self
    {
        if ($latitude === null || $longitude === null || $latitude === '' || $longitude === '') {
            return null;
        }

        return self::fromCoordinates($latitude, $longitude);
    }

    /**
     * Jarak permukaan (haversine) dalam kilometer, dua angka di belakang koma.
     */
    public function distanceKmTo(self $other): float
    {
        $earthRadiusKm = 6371.0;
        $latFrom = deg2rad($this->latitude);
        $latTo = deg2rad($other->latitude);
        $latDelta = $latTo - $latFrom;
        $lngDelta = deg2rad($other->longitude - $this->longitude);

        $a = sin($latDelta / 2) ** 2
            + cos($latFrom) * cos($latTo) * sin($lngDelta / 2) ** 2;

        return round(2 * $earthRadiusKm * asin(min(1.0, sqrt($a))), 2);
    }

    public function formatted(): string
    {
        return number_format($this->latitude, 7, '.', '').', '.number_format($this->longitude, 7, '.', '');
    }

    /**
     * Tautan peta untuk dibuka owner/pelanggan saat memilih titik.
     */
    public function openStreetMapUrl(int $zoom = 17): string
    {
        return 'https://www.openstreetmap.org/?mlat='.$this->latitude.'&mlon='.$this->longitude
            .'#map='.$zoom.'/'.$this->latitude.'/'.$this->longitude;
    }
}
