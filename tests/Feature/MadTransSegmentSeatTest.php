<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Ledger\Application\PostLedgerTransaction;
use App\Core\Ledger\Domain\LedgerCategory;
use App\Core\Ledger\Domain\LedgerDirection;
use App\Core\Ledger\Domain\LedgerEntryInput;
use App\Core\Ledger\Domain\PostLedgerTransactionData;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Core\MadTrans\Application\CreateTravelBooking;
use App\Core\MadTrans\Application\SearchTravelSchedules;
use App\Core\Payment\Domain\PaymentConflict;
use App\Models\Business;
use App\Models\RoutePricing;
use App\Models\RouteStop;
use App\Models\TravelRoute;
use App\Models\TravelSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MadTransSegmentSeatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.mad_trans.service_fee' => 5000,
            'services.mad_trans.platform_fee_basis_points' => 300,
            'services.mad_trans.ppn_basis_points' => 1100,
        ]);
        $this->seed(AccessControlSeeder::class);
        app(LedgerAccountSeeder::class)->run();
    }

    public function test_seat_can_be_resold_on_a_later_segment_but_not_on_an_overlapping_one(): void
    {
        [$agency, $schedule, $stops] = $this->scheduleWithThreeStops();
        $this->fundCollateral($agency, 100000);
        $customer = User::factory()->create(['status' => 'active']);
        $create = app(CreateTravelBooking::class);

        $first = $create->execute(
            $customer,
            $schedule,
            $stops[0],
            $stops[1],
            [['name' => 'Penumpang Awal', 'phone' => null, 'seat_number' => 'A1']],
            'cod',
            'segment-booking-1',
        );
        $this->assertSame('confirmed', $first->status);

        // Kursi A1 kosong lagi setelah Sampang, jadi bisa dijual untuk segmen lanjutan.
        $second = $create->execute(
            $customer,
            $schedule,
            $stops[1],
            $stops[2],
            [['name' => 'Penumpang Lanjutan', 'phone' => null, 'seat_number' => 'A1']],
            'cod',
            'segment-booking-2',
        );
        $this->assertSame('confirmed', $second->status);

        // Segmen yang bertumpang tindih tetap ditolak.
        $this->expectException(PaymentConflict::class);
        $create->execute(
            $customer,
            $schedule,
            $stops[0],
            $stops[2],
            [['name' => 'Penumpang Bentrok', 'phone' => null, 'seat_number' => 'A1']],
            'cod',
            'segment-booking-3',
        );
    }

    public function test_search_uses_segment_capacity_instead_of_whole_trip_total(): void
    {
        [$agency, $schedule, $stops] = $this->scheduleWithThreeStops();
        $this->fundCollateral($agency, 100000);
        $customer = User::factory()->create(['status' => 'active']);

        app(CreateTravelBooking::class)->execute(
            $customer,
            $schedule,
            $stops[0],
            $stops[1],
            [['name' => 'Penumpang Awal', 'phone' => null, 'seat_number' => 'A1']],
            'cod',
            'segment-search-1',
        );

        $search = app(SearchTravelSchedules::class);
        $date = Carbon::parse($schedule->departure_date);

        $laterSegment = $search->execute('Sampang', 'Surabaya', $date, 1);
        $this->assertCount(1, $laterSegment);
        $this->assertSame(1, $laterSegment->first()->segment_available);

        $wholeTrip = $search->execute('Sumenep', 'Surabaya', $date, 1);
        $this->assertCount(0, $wholeTrip);
    }

    private function scheduleWithThreeStops(): array
    {
        $owner = User::factory()->create(['status' => 'active']);
        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Travel Segmen',
            'code' => 'GM-AGENCY-SEG1',
            'owner_user_id' => $owner->getKey(),
            'status' => 'active',
        ]);
        $agency->members()->attach($owner, ['role' => 'pemilik', 'status' => 'active', 'joined_at' => now()]);

        $route = TravelRoute::query()->create([
            'business_id' => $agency->getKey(),
            'origin_city' => 'Sumenep',
            'destination_city' => 'Surabaya',
            'status' => 'active',
        ]);

        $definitions = [
            [1, 'Sumenep', 'Terminal Sumenep'],
            [2, 'Sampang', 'Terminal Sampang'],
            [3, 'Surabaya', 'Terminal Bungurasih'],
        ];
        $stopIds = [];

        foreach ($definitions as [$order, $city, $name]) {
            $stop = RouteStop::query()->create([
                'route_id' => $route->getKey(),
                'stop_order' => $order,
                'city_name' => $city,
                'name' => $name,
            ]);
            $stopIds[$order] = $stop->getKey();
        }

        $segments = [[1, 2, '50000.00'], [2, 3, '100000.00'], [1, 3, '150000.00']];

        foreach ($segments as [$originOrder, $destinationOrder, $price]) {
            RoutePricing::query()->create([
                'route_id' => $route->getKey(),
                'origin_stop_id' => $stopIds[$originOrder],
                'destination_stop_id' => $stopIds[$destinationOrder],
                'price' => $price,
                'is_active' => true,
            ]);
        }

        $vehicle = Vehicle::query()->create([
            'business_id' => $agency->getKey(),
            'name' => 'Elf Segmen',
            'plate_number' => 'M 5555 SEG',
            'seat_layout' => ['A1'],
            'capacity' => 1,
            'status' => 'active',
        ]);

        $schedule = TravelSchedule::query()->create([
            'business_id' => $agency->getKey(),
            'vehicle_id' => $vehicle->getKey(),
            'route_id' => $route->getKey(),
            'departure_date' => now()->addDay()->toDateString(),
            'departure_time' => '09:00:00',
            'travel_class' => 'regular',
            'price_per_seat' => '150000.00',
            'status' => 'published',
            'proposed_by' => $owner->getKey(),
            'allow_cod' => true,
            'payment_methods' => ['midtrans', 'cod'],
        ]);

        $schedule->stops()->createMany([
            ['route_stop_id' => $stopIds[1], 'stop_order' => 1],
            ['route_stop_id' => $stopIds[2], 'stop_order' => 2],
            ['route_stop_id' => $stopIds[3], 'stop_order' => 3],
        ]);

        return [$agency, $schedule, [$stopIds[1], $stopIds[2], $stopIds[3]]];
    }

    private function fundCollateral(Business $agency, int $amount): void
    {
        app(LedgerAccountSeeder::class)->ensureBusinessAccounts($agency);
        app(PostLedgerTransaction::class)->execute(new PostLedgerTransactionData(
            category: LedgerCategory::CollateralTopup,
            idempotencyKey: 'test:segment-collateral:'.$agency->getKey(),
            entries: [
                new LedgerEntryInput('PLATFORM.CASH', LedgerDirection::Debit, (string) $amount),
                new LedgerEntryInput(
                    'BUSINESS.COLLATERAL:'.$agency->getKey(),
                    LedgerDirection::Credit,
                    (string) $amount,
                ),
            ],
            referenceType: 'test',
            referenceId: (int) $agency->getKey(),
            description: 'Fixture jaminan COD segmen',
        ));
    }
}
