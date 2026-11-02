<?php

namespace App\Console\Commands;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Identity\Domain\AccountType;
use App\Core\Ledger\Application\PostLedgerTransaction;
use App\Core\Ledger\Domain\LedgerCategory;
use App\Core\Ledger\Domain\LedgerDirection;
use App\Core\Ledger\Domain\LedgerEntryInput;
use App\Core\Ledger\Domain\PostLedgerTransactionData;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Core\MadTrans\Domain\SeatLayout;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\BusinessPickupPolicy;
use App\Models\MemberLimit;
use App\Models\PlatformUserRole;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\RetailListing;
use App\Models\RoutePricing;
use App\Models\RouteStop;
use App\Models\Shift;
use App\Models\TravelRoute;
use App\Models\TravelSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class SeedDemoData extends Command
{
    /**
     * Fixed credentials so the live browser check is repeatable.
     */
    public const DEMO_PASSWORD = 'GomadDemo2026!';

    protected $signature = 'app:seed-demo
        {--confirm : Benar-benar menulis data demo ke database yang sedang aktif}';

    protected $description = 'Seed demo data idempoten (agency travel, penumpang, armada, rute, jadwal)';

    public function handle(): int
    {
        if (! $this->option('confirm')) {
            $this->warn('Tidak ada perubahan. Jalankan ulang dengan --confirm untuk menulis data demo.');
            $this->line('Target database: '.config('database.default').' / '.config('database.connections.'.config('database.default').'.database'));

            return self::SUCCESS;
        }

        $this->call('db:seed', [
            '--class' => AccessControlSeeder::class,
            '--force' => true,
        ]);

        app(LedgerAccountSeeder::class)->run();

        Model::unguarded(function (): void {
            $owner = $this->user('travel.owner@gomad.test', 'Owner Travel Demo', AccountType::AgencyOwner);
            $customer = $this->user('penumpang@gomad.test', 'Penumpang Demo', AccountType::Customer);

            $agency = Business::query()->updateOrCreate(
                ['code' => 'GM-TRAVEL-DEMO'],
                [
                    'type' => 'agency',
                    'name' => 'Mad Trans Demo',
                    'owner_user_id' => $owner->getKey(),
                    'status' => 'active',
                ],
            );

            if (! $agency->members()->whereKey($owner->getKey())->exists()) {
                $agency->members()->attach($owner, [
                    'role' => 'pemilik',
                    'status' => 'active',
                    'joined_at' => now(),
                ]);
            }

            app(LedgerAccountSeeder::class)->ensureBusinessAccounts($agency);

            $driver = $this->user('supir@gomad.test', 'Supir Demo', AccountType::Driver);

            // Platform punya dua akun terpisah: admin memegang administrasi, ops
            // menjalankan antrean harian. Tidak ada akun yang merangkap keduanya.
            $admin = $this->user('admin@gomad.test', 'Admin GoMad', AccountType::Admin);
            PlatformUserRole::query()->firstOrCreate(
                ['user_id' => $admin->getKey(), 'role' => 'admin'],
            );

            $ops = $this->user('ops@gomad.test', 'Ops GoMad', AccountType::Ops);
            PlatformUserRole::query()->firstOrCreate(
                ['user_id' => $ops->getKey(), 'role' => 'ops'],
            );

            if (! $agency->members()->whereKey($driver->getKey())->exists()) {
                $agency->members()->attach($driver, [
                    'role' => 'driver',
                    'status' => 'active',
                    'joined_at' => now(),
                ]);
            }

            $driverMembership = BusinessMember::query()
                ->where('business_id', $agency->getKey())
                ->where('user_id', $driver->getKey())
                ->firstOrFail();

            app(PostLedgerTransaction::class)->execute(new PostLedgerTransactionData(
                category: LedgerCategory::Adjustment,
                idempotencyKey: 'demo-cod-collateral',
                entries: [
                    new LedgerEntryInput('PLATFORM.CASH', LedgerDirection::Debit, '5000000'),
                    new LedgerEntryInput('BUSINESS.COLLATERAL:'.$agency->getKey(), LedgerDirection::Credit, '5000000'),
                ],
                description: 'Saldo jaminan COD untuk data demo',
            ));

            $route = TravelRoute::query()->firstOrCreate(
                [
                    'business_id' => $agency->getKey(),
                    'origin_city' => 'Jakarta',
                    'destination_city' => 'Bandung',
                ],
                ['status' => 'active'],
            );

            // Titik kumpul butuh koordinat: tanpanya penumpang tidak bisa meminta
            // dijemput door to door, dan tautan peta tidak bisa dibuka.
            $origin = RouteStop::query()->updateOrCreate(
                ['route_id' => $route->getKey(), 'stop_order' => 1],
                [
                    'city_name' => 'Jakarta',
                    'name' => 'Terminal Kampung Rambutan',
                    'latitude' => '-6.3069000',
                    'longitude' => '106.8788000',
                ],
            );

            $destination = RouteStop::query()->updateOrCreate(
                ['route_id' => $route->getKey(), 'stop_order' => 2],
                [
                    'city_name' => 'Bandung',
                    'name' => 'Terminal Cicaheum',
                    'latitude' => '-6.9024000',
                    'longitude' => '107.6471000',
                ],
            );

            RoutePricing::query()->firstOrCreate(
                [
                    'route_id' => $route->getKey(),
                    'origin_stop_id' => $origin->getKey(),
                    'destination_stop_id' => $destination->getKey(),
                ],
                ['price' => '120000.00', 'is_active' => true],
            );

            // Door to door: gratis sampai 5 km dari titik kumpul, lalu Rp 4.000/km.
            // Biaya ini masuk ke pendapatan agen, bukan fee platform.
            BusinessPickupPolicy::query()->updateOrCreate(
                ['business_id' => $agency->getKey()],
                ['free_radius_km' => 5, 'fee_per_km' => '4000.00'],
            );

            // Formasi 2-3-3 khas Hiace; kursi supir ditandai terpisah dan tidak dijual.
            $seats = SeatLayout::fromRows([2, 3, 3]);

            $vehicle = Vehicle::query()->updateOrCreate(
                ['business_id' => $agency->getKey(), 'plate_number' => 'B 1234 MDT'],
                [
                    'name' => 'Hiace Premio',
                    'seat_layout' => $seats,
                    'capacity' => count($seats),
                    'has_driver_seat' => true,
                    'status' => 'active',
                ],
            );

            $schedules = 0;

            foreach ([1, 2, 3] as $offset) {
                $schedule = TravelSchedule::query()->updateOrCreate(
                    [
                        'business_id' => $agency->getKey(),
                        'route_id' => $route->getKey(),
                        'vehicle_id' => $vehicle->getKey(),
                        'departure_date' => now()->addDays($offset)->toDateString(),
                        'departure_time' => '07:30:00',
                    ],
                    [
                        'travel_class' => 'regular',
                        'price_per_seat' => '120000.00',
                        'status' => 'published',
                        'proposed_by' => $owner->getKey(),
                        'driver_id' => $driverMembership->getKey(),
                        'allow_cod' => true,
                        'payment_methods' => ['midtrans', 'cod'],
                    ],
                );

                $schedule->stops()->updateOrCreate(
                    ['route_stop_id' => $origin->getKey()],
                    ['stop_order' => 1],
                );

                $schedule->stops()->updateOrCreate(
                    ['route_stop_id' => $destination->getKey()],
                    ['stop_order' => 2],
                );

                $schedules++;
            }

            // ---- Mad Warung: satu warung, satu penjaga, satu grosir ----
            $warungOwner = $this->user('warung.owner@gomad.test', 'Pemilik Warung Demo', AccountType::WarungOwner);
            $guardUser = $this->user('penjaga.warung@gomad.test', 'Penjaga Warung Demo', AccountType::WarungGuard);
            $wholesalerOwner = $this->user('grosir.owner@gomad.test', 'Pemilik Grosir Demo', AccountType::GrosirOwner);

            $warung = Business::query()->updateOrCreate(
                ['code' => 'GM-WARUNG-DEMO'],
                [
                    'type' => 'warung',
                    'name' => 'Warung Bu Sari',
                    'owner_user_id' => $warungOwner->getKey(),
                    'status' => 'active',
                ],
            );

            if (! $warung->members()->whereKey($warungOwner->getKey())->exists()) {
                $warung->members()->attach($warungOwner, [
                    'role' => 'pemilik',
                    'status' => 'active',
                    'joined_at' => now(),
                ]);
            }

            if (! $warung->members()->whereKey($guardUser->getKey())->exists()) {
                $warung->members()->attach($guardUser, [
                    'role' => 'penjaga',
                    'status' => 'active',
                    'joined_at' => now(),
                ]);
            }

            $wholesaler = Business::query()->updateOrCreate(
                ['code' => 'GM-GROSIR-DEMO'],
                [
                    'type' => 'grosir',
                    'name' => 'Grosir Sembako Demo',
                    'owner_user_id' => $wholesalerOwner->getKey(),
                    'status' => 'active',
                ],
            );

            if (! $wholesaler->members()->whereKey($wholesalerOwner->getKey())->exists()) {
                $wholesaler->members()->attach($wholesalerOwner, [
                    'role' => 'pemilik',
                    'status' => 'active',
                    'joined_at' => now(),
                ]);
            }

            $catalog = [
                ['DEMO-SBK-001', 'Minyak Goreng 1L', 'Sembako', 'botol', '15000.00', '18000.00', 120],
                ['DEMO-SBK-002', 'Beras Premium 5kg', 'Sembako', 'karung', '65000.00', '72000.00', 80],
                ['DEMO-SBK-003', 'Gula Pasir 1kg', 'Sembako', 'pack', '16000.00', '19000.00', 150],
            ];

            foreach ($catalog as [$sku, $name, $category, $unit, $price, $retailPrice, $stock]) {
                $product = Product::query()->updateOrCreate(
                    ['sku' => $sku],
                    [
                        'name' => $name,
                        'category' => $category,
                        'unit' => $unit,
                        'is_active' => true,
                    ],
                );

                ProductPrice::query()->updateOrCreate(
                    [
                        'product_id' => $product->getKey(),
                        'business_id' => $wholesaler->getKey(),
                    ],
                    [
                        'price' => $price,
                        'min_order_quantity' => 1,
                        'stock_available' => $stock,
                        'stock_reserved' => 0,
                        'is_active' => true,
                    ],
                );

                // Barang yang sama dijual di meja warung dengan harga eceran.
                // Normalnya baris ini lahir sendiri saat pesanan diterima; di demo
                // dibuat langsung supaya kasir bisa dicoba tanpa menunggu pesanan.
                RetailListing::query()->updateOrCreate(
                    [
                        'business_id' => $warung->getKey(),
                        'product_id' => $product->getKey(),
                    ],
                    [
                        'price' => $retailPrice,
                        'is_active' => true,
                    ],
                );
            }

            MemberLimit::query()->updateOrCreate(
                [
                    'business_id' => $warung->getKey(),
                    'user_id' => $guardUser->getKey(),
                ],
                [
                    'daily_amount_limit' => '300000.00',
                    'per_order_limit' => '150000.00',
                    'requires_approval_above' => '100000.00',
                    'updated_by' => $warungOwner->getKey(),
                ],
            );

            Shift::query()->updateOrCreate(
                [
                    'business_id' => $warung->getKey(),
                    'user_id' => $guardUser->getKey(),
                    'scheduled_start_at' => now()->addDay()->setTime(8, 0),
                ],
                [
                    'scheduled_end_at' => now()->addDay()->setTime(14, 0),
                    'status' => 'scheduled',
                    'notes' => 'Shift pagi demo',
                ],
            );

            $this->newLine();
            $this->info('Data demo siap.');
            $this->table(
                ['Fungsi akun', 'Email', 'Password'],
                [
                    ['Owner agency (travel & rental)', $owner->email, self::DEMO_PASSWORD],
                    ['Supir', $driver->email, self::DEMO_PASSWORD],
                    ['Pelanggan', $customer->email, self::DEMO_PASSWORD],
                    ['Pemilik warung', $warungOwner->email, self::DEMO_PASSWORD],
                    ['Penjaga warung', $guardUser->email, self::DEMO_PASSWORD],
                    ['Pemilik grosir', $wholesalerOwner->email, self::DEMO_PASSWORD],
                    ['Admin platform', $admin->email, self::DEMO_PASSWORD],
                    ['Ops platform', $ops->email, self::DEMO_PASSWORD],
                ],
            );

            $this->line('Agency : '.$agency->name.' ('.$agency->code.')');
            $this->line('Armada : '.$vehicle->name.' — '.count($seats).' kursi, formasi '.SeatLayout::summary($seats).', kursi supir ditandai ('.$vehicle->plate_number.')');
            $this->line('Rute   : Jakarta → Bandung, Rp 120.000/kursi, COD aktif');
            $this->line('Jemput : gratis 5 km dari titik kumpul, lalu Rp 4.000/km (door to door)');
            $this->line('Jaminan: Rp 5.000.000 saldo COD untuk agency');
            $this->line('Jadwal : '.$schedules.' jadwal published (besok s.d. 3 hari ke depan, 07:30)');
            $this->newLine();
            $this->line('Buka http://localhost:8200/mad-trans untuk uji browser.');
            $this->line('Buka http://localhost:8200/mad-warung/'.$warung->getKey().' untuk ruang kerja warung.');
            $this->line('Buka http://localhost:8200/mad-warung/'.$wholesaler->getKey().' untuk ruang kerja grosir.');
        });

        return self::SUCCESS;
    }

    private function user(string $email, string $name, AccountType $type): User
    {
        return User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make(self::DEMO_PASSWORD),
                'status' => 'active',
                'email_verified_at' => now(),
                'account_type' => $type->value,
            ],
        );
    }
}
