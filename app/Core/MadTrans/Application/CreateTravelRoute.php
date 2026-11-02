<?php

namespace App\Core\MadTrans\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Ledger\Domain\LedgerMoney;
use App\Models\Business;
use App\Models\RoutePricing;
use App\Models\RouteStop;
use App\Models\TravelRoute;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateTravelRoute
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    /**
     * @param  list<array{city_name: string, name: string, pickup_zone?: string|null}>  $stops
     * @param  list<array{origin_order: int, destination_order: int, price: string}>  $pricing
     */
    public function execute(
        User $owner,
        Business $agency,
        array $stops,
        array $pricing,
    ): TravelRoute {
        $this->authorizer->authorize($owner, 'schedule.manage', $agency);
        if ($agency->type !== 'agency'
            || $agency->status !== 'active'
            || (int) $agency->owner_user_id !== (int) $owner->getKey()) {
            throw new AuthorizationException('Hanya pemilik agency aktif dapat mengelola rute.');
        }
        if (count($stops) < 2 || count($stops) > 30 || $pricing === []) {
            throw new InvalidArgumentException('Rute harus memiliki 2 sampai 30 titik dan setidaknya satu tarif segmen.');
        }

        return DB::transaction(function () use ($agency, $stops, $pricing): TravelRoute {
            $normalizedStops = [];
            foreach ($stops as $index => $stop) {
                $city = trim($stop['city_name']);
                $name = trim($stop['name']);
                if ($city === '' || $name === '') {
                    throw new InvalidArgumentException('Kota dan nama setiap titik rute wajib diisi.');
                }
                $normalizedStops[] = [
                    'stop_order' => $index + 1,
                    'city_name' => $city,
                    'name' => $name,
                    'pickup_zone' => isset($stop['pickup_zone']) ? trim($stop['pickup_zone']) : null,
                ];
            }

            $route = TravelRoute::query()->create([
                'business_id' => $agency->getKey(),
                'origin_city' => $normalizedStops[0]['city_name'],
                'destination_city' => $normalizedStops[array_key_last($normalizedStops)]['city_name'],
                'status' => 'active',
            ]);
            $createdStops = collect($normalizedStops)
                ->map(fn (array $stop): RouteStop => $route->stops()->create($stop))
                ->keyBy('stop_order');
            $seenSegments = [];
            foreach ($pricing as $segment) {
                $originOrder = (int) $segment['origin_order'];
                $destinationOrder = (int) $segment['destination_order'];
                if ($originOrder < 1
                    || $destinationOrder > count($createdStops)
                    || $originOrder >= $destinationOrder
                    || ! $createdStops->has($originOrder)
                    || ! $createdStops->has($destinationOrder)) {
                    throw new InvalidArgumentException('Tarif segmen harus menghubungkan titik rute dengan urutan yang benar.');
                }
                $segmentKey = $originOrder.':'.$destinationOrder;
                if (isset($seenSegments[$segmentKey])) {
                    throw new InvalidArgumentException('Satu segmen rute tidak boleh memiliki tarif duplikat.');
                }
                $seenSegments[$segmentKey] = true;
                $price = LedgerMoney::from($segment['price']);
                if (! $price->isPositive() || $price->toCents() % 100 !== 0) {
                    throw new InvalidArgumentException('Tarif segmen harus berupa rupiah bulat yang lebih besar dari nol.');
                }
                RoutePricing::query()->create([
                    'route_id' => $route->getKey(),
                    'origin_stop_id' => $createdStops->get($originOrder)->getKey(),
                    'destination_stop_id' => $createdStops->get($destinationOrder)->getKey(),
                    'price' => $price->toDecimal(),
                    'is_active' => true,
                ]);
            }

            return $route->load(['stops', 'pricing']);
        }, attempts: 3);
    }
}
