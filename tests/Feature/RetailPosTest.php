<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Identity\Domain\AccountType;
use App\Core\Payment\Domain\PaymentConflict;
use App\Core\Warung\Application\ConfirmOrderReceipt;
use App\Core\Warung\Application\RecordRetailSale;
use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Models\RetailListing;
use App\Models\RetailSale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Kasir warung (POS).
 *
 * Yang dijaga di sini bukan tampilan, melainkan tiga janji yang dibuat di
 * `RecordRetailSale`: harga selalu dari server, tanpa fee/PPN, dan tidak
 * menyentuh stok grosir.
 */
class RetailPosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_received_order_registers_sellable_listings_with_purchase_price(): void
    {
        [$owner, $guard, $warung, $wholesaler, $product] = $this->warung();

        $order = $this->orderInDelivery($warung, $wholesaler, $owner, $product, '12000.00');

        app(ConfirmOrderReceipt::class)->execute($guard, $order);

        $listing = RetailListing::query()
            ->where('business_id', $warung->getKey())
            ->where('product_id', $product->getKey())
            ->firstOrFail();

        $this->assertSame('12000.00', $listing->price);
        $this->assertTrue($listing->is_active);
    }

    public function test_receiving_again_does_not_overwrite_price_adjusted_by_owner(): void
    {
        [$owner, $guard, $warung, $wholesaler, $product] = $this->warung();

        $first = $this->orderInDelivery($warung, $wholesaler, $owner, $product, '12000.00');
        app(ConfirmOrderReceipt::class)->execute($guard, $first);

        // Pemilik menaikkan harga jual.
        RetailListing::query()
            ->where('business_id', $warung->getKey())
            ->where('product_id', $product->getKey())
            ->update(['price' => '15000.00']);

        $second = $this->orderInDelivery($warung, $wholesaler, $owner, $product, '12500.00', 'ORD-2');
        app(ConfirmOrderReceipt::class)->execute($guard, $second);

        $this->assertSame('15000.00', RetailListing::query()
            ->where('business_id', $warung->getKey())
            ->where('product_id', $product->getKey())
            ->value('price'));
    }

    public function test_guard_records_sale_and_total_comes_from_server_prices(): void
    {
        [$owner, $guard, $warung, $wholesaler, $product] = $this->warung();
        $this->listing($warung, $product, '15000.00');

        $this->actingAs($guard)
            ->post('/mad-warung/'.$warung->getKey().'/kasir', [
                'items' => [$product->getKey() => 3],
                'paid_amount' => '50000',
            ])
            ->assertRedirect('/mad-warung/'.$warung->getKey().'/kasir');

        $sale = RetailSale::query()->with('items')->firstOrFail();

        $this->assertSame('45000.00', $sale->total_amount);
        $this->assertSame('50000.00', $sale->paid_amount);
        $this->assertSame('5000.00', $sale->change_amount);
        $this->assertSame($guard->getKey(), $sale->sold_by);
        $this->assertSame('Minyak goreng 1L', $sale->items->first()->product_name);
        $this->assertSame('15000.00', $sale->items->first()->unit_price);
    }

    public function test_sale_ignores_price_sent_by_client(): void
    {
        [$owner, $guard, $warung, $wholesaler, $product] = $this->warung();
        $this->listing($warung, $product, '15000.00');

        // Klien mengirim harga sendiri; server harus mengabaikannya.
        $this->actingAs($guard)
            ->post('/mad-warung/'.$warung->getKey().'/kasir', [
                'items' => [$product->getKey() => 1],
                'items_price' => [$product->getKey() => '1.00'],
                'total_amount' => '1.00',
            ]);

        $this->assertSame('15000.00', RetailSale::query()->firstOrFail()->total_amount);
    }

    public function test_sale_does_not_touch_wholesaler_stock(): void
    {
        [$owner, $guard, $warung, $wholesaler, $product] = $this->warung();
        $this->listing($warung, $product, '15000.00');

        $this->actingAs($guard)
            ->post('/mad-warung/'.$warung->getKey().'/kasir', [
                'items' => [$product->getKey() => 2],
            ]);

        // Stok di `product_prices` milik grosir, bukan rak warung.
        $this->assertSame(20, (int) $product->prices()->first()->stock_available);
        $this->assertSame(0, (int) $product->prices()->first()->stock_reserved);
    }

    public function test_sale_rejects_underpayment(): void
    {
        [$owner, $guard, $warung, $wholesaler, $product] = $this->warung();
        $this->listing($warung, $product, '15000.00');

        $this->actingAs($guard)
            ->post('/mad-warung/'.$warung->getKey().'/kasir', [
                'items' => [$product->getKey() => 2],
                'paid_amount' => '20000',
            ])
            ->assertSessionHasErrors('items');

        $this->assertSame(0, RetailSale::query()->count());
    }

    public function test_owner_cannot_open_cashier_but_can_see_sales(): void
    {
        [$owner, $guard, $warung, $wholesaler, $product] = $this->warung();
        $this->listing($warung, $product, '15000.00');

        // POS milik penjaga: pemilik hanya memantau.
        $this->actingAs($owner)
            ->get('/mad-warung/'.$warung->getKey().'/kasir')
            ->assertForbidden();

        $this->actingAs($owner)
            ->get('/mad-warung/'.$warung->getKey().'/penjualan')
            ->assertOk()
            ->assertSee('Penjualan kasir');

        $this->actingAs($guard)
            ->get('/mad-warung/'.$warung->getKey().'/penjualan')
            ->assertForbidden();
    }

    public function test_cashier_lists_only_products_the_warung_sells(): void
    {
        [$owner, $guard, $warung, $wholesaler, $product] = $this->warung();
        $this->listing($warung, $product, '15000.00');

        $this->actingAs($guard)
            ->get('/mad-warung/'.$warung->getKey().'/kasir')
            ->assertOk()
            ->assertSee('Minyak goreng 1L')
            ->assertSee('Simpan penjualan');
    }

    public function test_empty_cart_is_rejected(): void
    {
        [$owner, $guard, $warung, $wholesaler, $product] = $this->warung();
        $this->listing($warung, $product, '15000.00');

        $this->expectException(InvalidArgumentException::class);

        app(RecordRetailSale::class)->execute($guard, $warung, [$product->getKey() => 0]);
    }

    public function test_inactive_listing_cannot_be_sold(): void
    {
        [$owner, $guard, $warung, $wholesaler, $product] = $this->warung();
        $listing = $this->listing($warung, $product, '15000.00');
        $listing->update(['is_active' => false]);

        $this->expectException(PaymentConflict::class);

        app(RecordRetailSale::class)->execute($guard, $warung, [$product->getKey() => 1]);
    }

    /**
     * @return array{0: User, 1: User, 2: Business, 3: Business, 4: Product}
     */
    private function warung(): array
    {
        $owner = User::factory()->accountType(AccountType::WarungOwner)->create();
        $guard = User::factory()->accountType(AccountType::WarungGuard)->create();
        $wholesalerOwner = User::factory()->accountType(AccountType::GrosirOwner)->create();

        $warung = $this->business($owner, 'warung', 'Warung Kasir');
        $wholesaler = $this->business($wholesalerOwner, 'grosir', 'Grosir Kasir');

        $warung->members()->attach($guard, [
            'role' => 'penjaga',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-'.strtoupper(substr(md5((string) $guard->getKey()), 0, 10)),
            'name' => 'Minyak goreng 1L',
            'category' => 'Sembako',
            'unit' => 'botol',
            'is_active' => true,
        ]);

        $product->prices()->create([
            'business_id' => $wholesaler->getKey(),
            'price' => '10000.00',
            'min_order_quantity' => 1,
            'stock_available' => 20,
            'stock_reserved' => 0,
            'is_active' => true,
        ]);

        $owner->refresh();
        $guard->refresh();

        return [$owner, $guard, $warung, $wholesaler, $product];
    }

    private function listing(Business $warung, Product $product, string $price): RetailListing
    {
        return RetailListing::query()->create([
            'business_id' => $warung->getKey(),
            'product_id' => $product->getKey(),
            'price' => $price,
            'is_active' => true,
        ]);
    }

    private function orderInDelivery(
        Business $warung,
        Business $wholesaler,
        User $owner,
        Product $product,
        string $unitPrice,
        string $code = 'ORD-1',
    ): Order {
        $order = Order::query()->create([
            'code' => $code,
            'business_id' => $warung->getKey(),
            'wholesaler_id' => $wholesaler->getKey(),
            'created_by' => $owner->getKey(),
            'status' => 'in_delivery',
            'subtotal' => $unitPrice,
            'platform_fee' => '0.00',
            'ppn_amount' => '0.00',
            'total_amount' => $unitPrice,
            'delivery_method' => 'antar_grosir',
            'idempotency_key' => 'test-'.Str::uuid(),
            'request_hash' => hash('sha256', $code),
            'expires_at' => now()->addDay(),
        ]);

        $order->items()->create([
            'product_id' => $product->getKey(),
            'product_price_id' => $product->prices()->first()->getKey(),
            'sku' => $product->sku,
            'product_name' => $product->name,
            'unit' => $product->unit,
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'subtotal' => $unitPrice,
        ]);

        return $order->fresh();
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
}
