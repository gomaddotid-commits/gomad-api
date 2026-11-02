<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Core\MadTrans\Application\CreateTravelBooking;
use App\Models\BookingPassenger;
use App\Models\Business;
use App\Models\RoutePricing;
use App\Models\RouteStop;
use App\Models\TravelBooking;
use App\Models\TravelRoute;
use App\Models\TravelSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/**
 * F2 dari `docs/04-checklist-jangan-diulang.md`: dua permintaan untuk kursi yang
 * sama secara bersamaan harus menghasilkan tepat satu pemesanan.
 */
class SeatConcurrencyTest extends TestCase
{
    public function test_two_simultaneous_requests_for_the_same_seat_produce_one_booking(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrent seat test.');
        }

        $databasePath = tempnam(sys_get_temp_dir(), 'gomad-seat-race-');
        $this->assertIsString($databasePath);
        unlink($databasePath);
        $barrierPath = $databasePath.'-barrier';
        mkdir($barrierPath);
        $defaultConnection = DB::getDefaultConnection();
        $children = [];

        config(['database.connections.seat_race' => [
            'driver' => 'sqlite',
            'database' => $databasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 10000,
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
        ]]);
        DB::purge('seat_race');
        DB::setDefaultConnection('seat_race');

        try {
            Artisan::call('migrate:fresh', ['--database' => 'seat_race', '--force' => true]);
            $this->seed(AccessControlSeeder::class);
            app(LedgerAccountSeeder::class)->run();

            [$schedule, $origin, $destination] = $this->schedule();
            $customerIds = [
                User::factory()->create(['status' => 'active'])->getKey(),
                User::factory()->create(['status' => 'active'])->getKey(),
            ];

            for ($worker = 0; $worker < 2; $worker++) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid, 'Could not fork a booking worker process.');
                if ($pid === 0) {
                    DB::purge('seat_race');
                    DB::setDefaultConnection('seat_race');
                    file_put_contents($barrierPath.'/ready-'.$worker, 'ready');

                    $deadline = microtime(true) + 10;
                    while (! is_file($barrierPath.'/start') && microtime(true) < $deadline) {
                        usleep(1000);
                    }

                    try {
                        $booking = app(CreateTravelBooking::class)->execute(
                            User::query()->findOrFail($customerIds[$worker]),
                            TravelSchedule::query()->findOrFail($schedule->getKey()),
                            $origin->getKey(),
                            $destination->getKey(),
                            [['name' => 'Penumpang '.$worker, 'seat_number' => 'A1']],
                            'midtrans',
                            'race-'.$worker,
                        );
                        file_put_contents($barrierPath.'/result-'.$worker, 'ok:'.$booking->getKey());
                        exit(0);
                    } catch (Throwable $exception) {
                        file_put_contents($barrierPath.'/result-'.$worker, 'rejected:'.$exception::class);
                        exit(0);
                    }
                }

                $children[] = $pid;
            }

            $deadline = microtime(true) + 10;
            while ((! is_file($barrierPath.'/ready-0') || ! is_file($barrierPath.'/ready-1'))
                && microtime(true) < $deadline) {
                usleep(1000);
            }
            $this->assertFileExists($barrierPath.'/ready-0');
            $this->assertFileExists($barrierPath.'/ready-1');
            file_put_contents($barrierPath.'/start', 'go');

            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
            }

            $results = [
                (string) file_get_contents($barrierPath.'/result-0'),
                (string) file_get_contents($barrierPath.'/result-1'),
            ];
            $succeeded = array_values(array_filter($results, fn (string $r): bool => str_starts_with($r, 'ok:')));

            $this->assertCount(1, $succeeded, 'Hanya satu permintaan yang boleh berhasil: '.implode(' | ', $results));
            $this->assertSame(1, TravelBooking::query()->count());
            $this->assertSame(
                1,
                BookingPassenger::query()->where('schedule_id', $schedule->getKey())->where('seat_number', 'A1')->count(),
            );
        } finally {
            foreach ($children as $pid) {
                if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                    posix_kill($pid, SIGTERM);
                    pcntl_waitpid($pid, $status);
                }
            }
            DB::purge('seat_race');
            DB::setDefaultConnection($defaultConnection);
            foreach (glob($barrierPath.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($barrierPath);
            foreach ([$databasePath, $databasePath.'-wal', $databasePath.'-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    /**
     * @return array{0: TravelSchedule, 1: RouteStop, 2: RouteStop}
     */
    private function schedule(): array
    {
        $owner = User::factory()->create(['status' => 'active']);
        $agency = Business::query()->create([
            'type' => 'agency',
            'name' => 'Travel Seat Race',
            'code' => 'GM-TRAVEL-SRC1',
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
        $origin = RouteStop::query()->create([
            'route_id' => $route->getKey(),
            'stop_order' => 1,
            'city_name' => 'Sumenep',
            'name' => 'Terminal Sumenep',
        ]);
        $destination = RouteStop::query()->create([
            'route_id' => $route->getKey(),
            'stop_order' => 2,
            'city_name' => 'Surabaya',
            'name' => 'Terminal Bungurasih',
        ]);
        RoutePricing::query()->create([
            'route_id' => $route->getKey(),
            'origin_stop_id' => $origin->getKey(),
            'destination_stop_id' => $destination->getKey(),
            'price' => '100000.00',
            'is_active' => true,
        ]);
        $vehicle = Vehicle::query()->create([
            'business_id' => $agency->getKey(),
            'name' => 'Hiace Race',
            'plate_number' => 'M 2222 RCE',
            'seat_layout' => ['A1', 'A2'],
            'capacity' => 2,
            'status' => 'active',
        ]);
        $schedule = TravelSchedule::query()->create([
            'business_id' => $agency->getKey(),
            'vehicle_id' => $vehicle->getKey(),
            'route_id' => $route->getKey(),
            'departure_date' => now()->addDays(2)->toDateString(),
            'departure_time' => '06:00:00',
            'travel_class' => 'regular',
            'price_per_seat' => '100000.00',
            'status' => 'published',
            'proposed_by' => $owner->getKey(),
            'allow_cod' => false,
            'payment_methods' => ['midtrans'],
        ]);
        $schedule->stops()->createMany([
            ['route_stop_id' => $origin->getKey(), 'stop_order' => 1],
            ['route_stop_id' => $destination->getKey(), 'stop_order' => 2],
        ]);

        return [$schedule, $origin, $destination];
    }
}
