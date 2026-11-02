<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Identity\Domain\AccountType;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Models\Business;
use App\Models\MemberLimit;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ruang kerja Mad Warung: satu halaman per pekerjaan, dipakai warung dan grosir.
 * Tes ini mengunci pembagian halaman, hak akses per peran, dan bentuk data yang
 * tampil — bukan aturan uang, yang sudah dikunci di WarungOrderTest.
 */
class WarungPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.mad_warung.platform_fee_basis_points' => 300,
            'services.mad_warung.ppn_basis_points' => 1100,
        ]);
        $this->seed(AccessControlSeeder::class);
        app(LedgerAccountSeeder::class)->run();
    }

    public function test_warung_owner_can_open_every_workspace_page(): void
    {
        [$owner, $guard, $warung, $wholesaler, $product] = $this->marketplace();
        $this->setLimit($warung, $guard, '500000', '100000', '20000');
        $order = $this->placeOrder($guard, $warung, $wholesaler, $product, 3);

        $pages = [
            '' => 'Pesanan hari ini',
            '/pesanan' => 'Pesanan warung',
            '/katalog' => 'Pesan stok',
            '/penjaga' => 'Penjaga & plafon',
            '/shift' => 'Jadwal shift penjaga',
            '/laporan' => 'Laporan pesanan',
        ];

        foreach ($pages as $suffix => $marker) {
            $this->actingAs($owner)
                ->get('/mad-warung/'.$warung->getKey().$suffix)
                ->assertOk()
                ->assertSee($marker);
        }

        $this->actingAs($owner)
            ->get('/mad-warung/'.$warung->getKey().'/pesanan')
            ->assertOk()
            ->assertSee($order->code)
            ->assertSee('Menunggu persetujuan');
    }

    public function test_guard_sees_own_workspace_but_not_owner_pages(): void
    {
        [$owner, $guard, $warung, $wholesaler, $product] = $this->marketplace();
        $this->placeOrder($guard, $warung, $wholesaler, $product, 1);

        $this->actingAs($guard)->get('/mad-warung/'.$warung->getKey())->assertOk();
        $this->actingAs($guard)->get('/mad-warung/'.$warung->getKey().'/katalog')->assertOk();
        $this->actingAs($guard)->get('/mad-warung/'.$warung->getKey().'/shift')->assertOk();
        $this->actingAs($guard)->get('/mad-warung/'.$warung->getKey().'/pesanan')->assertOk();

        $this->actingAs($guard)->get('/mad-warung/'.$warung->getKey().'/penjaga')->assertForbidden();
        $this->actingAs($guard)->get('/mad-warung/'.$warung->getKey().'/laporan')->assertForbidden();
    }

    public function test_outsider_cannot_open_the_warung_workspace(): void
    {
        [, , $warung] = $this->marketplace();
        $outsider = User::factory()->accountType(AccountType::Customer)->create(['status' => 'active']);

        foreach (['', '/pesanan', '/katalog', '/penjaga', '/shift', '/laporan'] as $suffix) {
            $this->actingAs($outsider)
                ->get('/mad-warung/'.$warung->getKey().$suffix)
                ->assertForbidden();
        }
    }

    public function test_grosir_owner_manages_catalog_and_sees_incoming_orders(): void
    {
        [$warungOwner, , $warung, $wholesaler, $product, $price, $wholesalerOwner] = $this->marketplace();

        // Harga baru tampil di katalog grosir setelah disimpan lewat halaman katalog.
        $this->actingAs($wholesalerOwner)
            ->put('/mad-warung/'.$wholesaler->getKey().'/products/'.$product->getKey().'/price', [
                'price' => '12500',
                'min_order_quantity' => 2,
                'stock_available' => 40,
            ])
            ->assertRedirect();

        $this->assertSame('12500.00', $price->fresh()->price);

        $this->actingAs($wholesalerOwner)
            ->get('/mad-warung/'.$wholesaler->getKey())
            ->assertOk()
            ->assertSee('Perlu disiapkan');

        $this->actingAs($wholesalerOwner)
            ->get('/mad-warung/'.$wholesaler->getKey().'/katalog')
            ->assertOk()
            ->assertSee('Katalog & harga')
            ->assertSee('Minyak goreng 1L');

        // Halaman khusus warung tidak berlaku untuk grosir.
        $this->actingAs($wholesalerOwner)
            ->get('/mad-warung/'.$wholesaler->getKey().'/penjaga')
            ->assertNotFound();

        // Warung tidak bisa membuka katalog grosir milik orang lain sebagai pengelola.
        $this->actingAs($warungOwner)
            ->get('/mad-warung/'.$wholesaler->getKey().'/katalog')
            ->assertForbidden();
    }

    public function test_penjaga_page_shows_today_spend_against_the_limit(): void
    {
        [$owner, $guard, $warung, $wholesaler, $product] = $this->marketplace();
        $this->setLimit($warung, $guard, '100000', '100000', '0');
        $this->placeOrder($guard, $warung, $wholesaler, $product, 2);

        $limit = MemberLimit::query()
            ->where('business_id', $warung->getKey())
            ->where('user_id', $guard->getKey())
            ->firstOrFail();
        $this->assertSame('100000.00', $limit->daily_amount_limit);

        $this->actingAs($owner)
            ->get('/mad-warung/'.$warung->getKey().'/penjaga')
            ->assertOk()
            ->assertSee($guard->name)
            ->assertSee('Hari ini');
    }

    private function placeOrder(
        User $guard,
        Business $warung,
        Business $wholesaler,
        Product $product,
        int $quantity,
    ): Order {
        Notification::fake();
        Sanctum::actingAs($guard);

        $response = $this->withHeader('Idempotency-Key', 'portal-'.uniqid())
            ->postJson('/api/v1/warung/orders', [
                'business_id' => $warung->getKey(),
                'wholesaler_id' => $wholesaler->getKey(),
                'items' => [['product_id' => $product->getKey(), 'quantity' => $quantity]],
                'delivery_method' => 'ambil_sendiri',
            ])
            ->assertCreated();

        $order = Order::query()->findOrFail($response->json('data.id'));

        // Kembali ke guard web: token Sanctum dari permintaan API tidak dipakai di sini.
        $this->app['auth']->forgetGuards();

        return $order;
    }

    private function marketplace(): array
    {
        $owner = User::factory()->accountType(AccountType::WarungOwner)->create(['status' => 'active']);
        $guard = User::factory()->accountType(AccountType::WarungGuard)->create(['status' => 'active']);
        $wholesalerOwner = User::factory()->accountType(AccountType::GrosirOwner)->create(['status' => 'active']);

        $warung = $this->business($owner, 'warung', 'Warung Portal');
        $wholesaler = $this->business($wholesalerOwner, 'grosir', 'Grosir Portal');

        $warung->members()->attach($guard, [
            'role' => 'penjaga',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-PORTAL-1',
            'name' => 'Minyak goreng 1L',
            'category' => 'Sembako',
            'unit' => 'botol',
            'is_active' => true,
        ]);
        $price = ProductPrice::query()->create([
            'product_id' => $product->getKey(),
            'business_id' => $wholesaler->getKey(),
            'price' => '10000.00',
            'min_order_quantity' => 1,
            'stock_available' => 100,
            'stock_reserved' => 0,
            'is_active' => true,
        ]);

        return [$owner, $guard, $warung, $wholesaler, $product, $price, $wholesalerOwner];
    }

    private function business(User $owner, string $type, string $name): Business
    {
        $business = Business::query()->create([
            'type' => $type,
            'name' => $name,
            'code' => 'GM-'.strtoupper($type).'-'.str_pad((string) (Business::query()->count() + 1), 8, '0', STR_PAD_LEFT),
            'owner_user_id' => $owner->getKey(),
            'status' => 'active',
        ]);
        $business->members()->attach($owner, [
            'role' => 'pemilik',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $business;
    }

    private function setLimit(Business $warung, User $guard, string $daily, string $perOrder, string $approval): void
    {
        MemberLimit::query()->create([
            'business_id' => $warung->getKey(),
            'user_id' => $guard->getKey(),
            'daily_amount_limit' => $daily,
            'per_order_limit' => $perOrder,
            'requires_approval_above' => $approval,
        ]);
    }
}
