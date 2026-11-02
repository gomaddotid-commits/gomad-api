<?php

namespace Tests\Feature;

use App\Core\Access\Infrastructure\AccessControlSeeder;
use App\Core\Identity\Domain\AccountType;
use App\Core\Ledger\Application\ReconcileLedger;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Core\Notification\Application\OrderApprovalRequestedNotification;
use App\Core\Notification\Application\OrderStatusNotification;
use App\Models\Business;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\MemberLimit;
use App\Models\Order;
use App\Models\PaymentIntent;
use App\Models\PlatformUserRole;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WarungOrderTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER_KEY = 'sandbox-server-key-for-warung-tests';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.midtrans.server_key' => self::SERVER_KEY,
            'services.midtrans.client_key' => 'sandbox-client-key-for-tests',
            'services.midtrans.snap_url' => 'https://snap.sandbox.test/v1/transactions',
            'services.midtrans.payment_timeout' => 30,
            'services.mad_warung.platform_fee_basis_points' => 300,
            'services.mad_warung.ppn_basis_points' => 1100,
        ]);
        $this->seed(AccessControlSeeder::class);
        app(LedgerAccountSeeder::class)->run();
    }

    public function test_guard_order_requires_owner_approval_over_threshold_and_uses_owner_checkout(): void
    {
        Notification::fake();
        Http::fake(['https://snap.sandbox.test/*' => Http::response([
            'token' => 'warung-snap-token',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/redirect',
        ])]);
        [$owner, $guard, $warung, $wholesaler, $product, $price] = $this->marketplace();
        $this->setLimit($warung, $guard, '100000', '50000', '15000');

        $createResponse = $this->apiAs($guard)
            ->withHeader('Idempotency-Key', 'order-one')
            ->postJson('/api/v1/warung/orders', [
                'business_id' => $warung->getKey(),
                'wholesaler_id' => $wholesaler->getKey(),
                'items' => [['product_id' => $product->getKey(), 'quantity' => 2]],
                'delivery_method' => 'ambil_sendiri',
            ])->assertCreated()
            ->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.subtotal', '20000.00')
            ->assertJsonPath('data.platform_fee', '600.00')
            ->assertJsonPath('data.ppn_amount', '66.00')
            ->assertJsonPath('data.total_amount', '20666.00');
        $order = Order::query()->findOrFail($createResponse->json('data.id'));
        $this->assertSame(2, $price->fresh()->stock_reserved);
        Notification::assertSentTo($owner, OrderApprovalRequestedNotification::class);

        $this->apiAs($owner)
            ->withHeader('Idempotency-Key', 'owner-too-early')
            ->postJson('/api/v1/warung/orders/'.$order->getKey().'/pay')
            ->assertConflict();
        $this->apiAs($owner)
            ->postJson('/api/v1/warung/orders/'.$order->getKey().'/decide', ['decision' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_payment');

        $this->apiAs($guard)
            ->withHeader('Idempotency-Key', 'guard-cannot-pay')
            ->postJson('/api/v1/warung/orders/'.$order->getKey().'/pay')
            ->assertForbidden();

        $paymentResponse = $this->apiAs($owner)
            ->withHeader('Idempotency-Key', 'owner-pays-order')
            ->postJson('/api/v1/warung/orders/'.$order->getKey().'/pay')
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.snap_token', 'warung-snap-token');
        $payment = PaymentIntent::query()->findOrFail($order->fresh()->payment_intent_id);
        $this->assertSame($owner->getKey(), $payment->user_id);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();
            $items = $data['item_details'];

            return $data['transaction_details']['gross_amount'] === 20666
                && array_sum(array_map(fn (array $item): int => $item['price'] * $item['quantity'], $items)) === 20666
                && collect($items)->contains(fn (array $item): bool => $item['id'] === 'gomad-platform-fee')
                && collect($items)->contains(fn (array $item): bool => $item['id'] === 'gomad-ppn')
                && ! array_key_exists('card_number', $data);
        });

        $payload = $this->webhookPayload($payment, 'settlement');
        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();
        $this->postJson('/api/v1/webhooks/midtrans', $payload)->assertOk();

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, LedgerTransaction::query()->where('category', 'ORDER_PAID_TO_GROSIR')->count());
        $this->assertSame('20000.00', $this->account('BUSINESS.PAYABLE:'.$wholesaler->getKey())->balance_cached);
        $this->assertSame('600.00', $this->account('PLATFORM.REVENUE.PLATFORM_FEE')->balance_cached);
        $this->assertSame('66.00', $this->account('PLATFORM.TAX_PAYABLE_PPN')->balance_cached);
        $this->assertSame(18, $price->fresh()->stock_available);
        $this->assertSame(0, $price->fresh()->stock_reserved);
        Notification::assertSentToTimes($guard, OrderStatusNotification::class, 2);

        $report = app(ReconcileLedger::class)->execute();
        $this->assertSame([], $report['unbalanced_transactions']);
        $this->assertTrue(collect($report['accounts'])->every(fn (array $account): bool => $account['matches']));
    }

    public function test_below_threshold_order_can_be_placed_idempotently_and_stock_is_reserved_once(): void
    {
        [$owner, $guard, $warung, $wholesaler, $product, $price] = $this->marketplace();
        $this->setLimit($warung, $guard, '100000', '50000', '50000');
        $payload = [
            'business_id' => $warung->getKey(),
            'wholesaler_id' => $wholesaler->getKey(),
            'items' => [['product_id' => $product->getKey(), 'quantity' => 2]],
            'delivery_method' => 'antar_grosir',
            'delivery_address' => 'Jl. Contoh No. 1',
        ];

        $first = $this->apiAs($guard)
            ->withHeader('Idempotency-Key', 'idempotent-order')
            ->postJson('/api/v1/warung/orders', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_payment');
        $this->apiAs($guard)
            ->withHeader('Idempotency-Key', 'idempotent-order')
            ->postJson('/api/v1/warung/orders', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(2, $price->fresh()->stock_reserved);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_daily_limit_rejects_order_and_rolls_back_stock_reservation(): void
    {
        [$owner, $guard, $warung, $wholesaler, $product, $price] = $this->marketplace();
        $this->setLimit($warung, $guard, '20000', '50000', '50000');
        $this->apiAs($guard)
            ->withHeader('Idempotency-Key', 'over-daily-limit')
            ->postJson('/api/v1/warung/orders', [
                'business_id' => $warung->getKey(),
                'wholesaler_id' => $wholesaler->getKey(),
                'items' => [['product_id' => $product->getKey(), 'quantity' => 2]],
                'delivery_method' => 'ambil_sendiri',
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'PAYMENT_CONFLICT');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $price->fresh()->stock_reserved);
    }

    public function test_unconfigured_ppn_blocks_checkout_before_order_or_stock_reservation(): void
    {
        config(['services.mad_warung.ppn_basis_points' => null]);
        [$owner, $guard, $warung, $wholesaler, $product, $price] = $this->marketplace();
        $this->setLimit($warung, $guard, '100000', '50000', '50000');

        $this->apiAs($guard)
            ->withHeader('Idempotency-Key', 'no-tax-config')
            ->postJson('/api/v1/warung/orders', [
                'business_id' => $warung->getKey(),
                'wholesaler_id' => $wholesaler->getKey(),
                'items' => [['product_id' => $product->getKey(), 'quantity' => 2]],
                'delivery_method' => 'ambil_sendiri',
            ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'CHECKOUT_NOT_CONFIGURED');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $price->fresh()->stock_reserved);
    }

    public function test_rejecting_approval_releases_reserved_stock_and_records_decision(): void
    {
        [$owner, $guard, $warung, $wholesaler, $product, $price] = $this->marketplace();
        $order = $this->createOrder($guard, $warung, $wholesaler, $product, 'reject-order', 2);
        $this->assertSame('pending_approval', $order->status);
        $this->assertSame(2, $price->fresh()->stock_reserved);

        $this->apiAs($owner)
            ->postJson('/api/v1/warung/orders/'.$order->getKey().'/decide', [
                'decision' => 'rejected',
                'reason' => 'Belum tersedia anggaran hari ini.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertSame(0, $price->fresh()->stock_reserved);
        $this->assertDatabaseHas('order_approvals', [
            'order_id' => $order->getKey(),
            'decision' => 'rejected',
            'decided_by' => $owner->getKey(),
            'reason' => 'Belum tersedia anggaran hari ini.',
        ]);
    }

    public function test_wholesaler_fulfills_order_and_guard_confirms_receipt_with_owner_notification(): void
    {
        Notification::fake();
        Http::fake(['https://snap.sandbox.test/*' => Http::response([
            'token' => 'ready-token',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/redirect',
        ])]);
        [$owner, $guard, $warung, $wholesaler, $product, $price, $wholesalerOwner] = $this->marketplace();
        $this->setLimit($warung, $guard, '100000', '50000', '50000');
        $order = $this->createOrder($guard, $warung, $wholesaler, $product, 'full-order', 2, 'ambil_sendiri');
        $payment = $this->payAndSettle($owner, $order);

        $this->apiAs($wholesalerOwner)
            ->postJson('/api/v1/warung/orders/'.$order->getKey().'/fulfill', ['action' => 'prepare'])
            ->assertOk()
            ->assertJsonPath('data.status', 'preparing');
        $this->apiAs($wholesalerOwner)
            ->postJson('/api/v1/warung/orders/'.$order->getKey().'/fulfill', ['action' => 'ready'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ready_for_pickup');
        $this->apiAs($guard)
            ->postJson('/api/v1/warung/orders/'.$order->getKey().'/receive')
            ->assertOk()
            ->assertJsonPath('data.status', 'received')
            ->assertJsonPath('data.received_confirmed_by', $guard->getKey());
        $this->apiAs($owner)
            ->postJson('/api/v1/warung/orders/'.$order->getKey().'/complete')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertNotNull($order->fresh()->received_at);
        $this->assertSame($guard->getKey(), $order->fresh()->received_confirmed_by);
        Notification::assertSentTo($owner, OrderStatusNotification::class);
        Notification::assertSentTo($wholesalerOwner, OrderStatusNotification::class);
        $this->assertNotNull($payment->fresh()->paid_at);
    }

    public function test_cross_tenant_order_reads_and_wholesaler_price_changes_are_forbidden(): void
    {
        [$owner, $guard, $warung, $wholesaler, $product] = $this->marketplace();
        $order = $this->createOrder($guard, $warung, $wholesaler, $product, 'private-order', 1);
        [$otherOwner, $otherGuard, $otherWarung, $otherWholesaler, $otherProduct] = $this->marketplace();

        $this->apiAs($otherOwner)
            ->getJson('/api/v1/warung/orders/'.$order->getKey())
            ->assertNotFound();
        $this->apiAs($otherOwner)
            ->putJson('/api/v1/warung/grosir/'.$wholesaler->getKey().'/products/'.$product->getKey().'/price', [
                'price' => '1',
                'min_order_quantity' => 1,
                'stock_available' => 100,
            ])
            ->assertForbidden();
        $this->apiAs($otherGuard)
            ->getJson('/api/v1/warung/catalog?business_id='.$otherWarung->getKey().'&wholesaler_id='.$otherWholesaler->getKey())
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_stale_order_expiry_releases_inventory_reservations(): void
    {
        [$owner, $guard, $warung, $wholesaler, $product, $price] = $this->marketplace();
        $order = $this->createOrder($guard, $warung, $wholesaler, $product, 'will-expire', 2);
        $order->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->artisan('mad-warung:expire-stale-orders')
            ->expectsOutput('Expired 1 Mad Warung order(s).')
            ->assertExitCode(0);

        $this->assertSame('expired', $order->fresh()->status);
        $this->assertSame(0, $price->fresh()->stock_reserved);
    }

    public function test_admin_can_publish_product_and_grosir_owner_can_publish_price(): void
    {
        [$admin] = $this->admin();
        [$owner, $guard, $warung, $wholesaler, $wholesalerOwner] = $this->marketplace(withoutProduct: true);
        $product = $this->apiAs($admin)
            ->postJson('/api/v1/admin/warung/products', [
                'sku' => 'SKU-RICE-01',
                'name' => 'Beras medium',
                'category' => 'Bahan pokok',
                'unit' => 'karung',
            ])
            ->assertCreated()
            ->assertJsonPath('data.sku', 'SKU-RICE-01');
        $productId = $product->json('data.id');

        $this->apiAs($wholesalerOwner)
            ->putJson('/api/v1/warung/grosir/'.$wholesaler->getKey().'/products/'.$productId.'/price', [
                'price' => '150000',
                'min_order_quantity' => 2,
                'stock_available' => 50,
            ])
            ->assertOk()
            ->assertJsonPath('data.stock_available', 50);
        $this->apiAs($guard)
            ->getJson('/api/v1/warung/catalog?business_id='.$warung->getKey().'&wholesaler_id='.$wholesaler->getKey())
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Beras medium')
            ->assertJsonPath('data.0.stock_available', 50);
    }

    public function test_owner_can_schedule_non_overlapping_guard_shifts_and_guard_can_clock_in_and_out(): void
    {
        [$owner, $guard, $warung] = $this->marketplace(withoutProduct: true);
        $startsAt = now()->addHour()->startOfMinute();
        $endsAt = $startsAt->copy()->addHours(8);

        $scheduled = $this->apiAs($owner)
            ->postJson('/api/v1/warung/businesses/'.$warung->getKey().'/shifts', [
                'user_id' => $guard->getKey(),
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $endsAt->toIso8601String(),
                'notes' => 'Shift pagi',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'scheduled');
        $shiftId = $scheduled->json('data.id');

        $this->apiAs($owner)
            ->postJson('/api/v1/warung/businesses/'.$warung->getKey().'/shifts', [
                'user_id' => $guard->getKey(),
                'starts_at' => $startsAt->copy()->addHours(2)->toIso8601String(),
                'ends_at' => $endsAt->copy()->addHours(2)->toIso8601String(),
            ])
            ->assertUnprocessable();

        $this->travelTo($startsAt->copy()->addMinute());
        $this->apiAs($guard)
            ->postJson('/api/v1/warung/shifts/'.$shiftId.'/clock-in')
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
        $this->travel(8)->hours();
        $this->apiAs($guard)
            ->postJson('/api/v1/warung/shifts/'.$shiftId.'/clock-out')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->travelBack();
    }

    public function test_guard_cannot_read_or_clock_another_guard_shift(): void
    {
        [$owner, $guard, $warung] = $this->marketplace(withoutProduct: true);
        $otherGuard = User::factory()->accountType(AccountType::WarungGuard)->create();
        $warung->members()->attach($otherGuard, [
            'role' => 'penjaga',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $shift = Shift::query()->create([
            'business_id' => $warung->getKey(),
            'user_id' => $otherGuard->getKey(),
            'scheduled_start_at' => now()->subMinute(),
            'scheduled_end_at' => now()->addHours(8),
            'status' => 'scheduled',
        ]);

        $this->apiAs($guard)
            ->postJson('/api/v1/warung/shifts/'.$shift->getKey().'/clock-in')
            ->assertForbidden();
        $this->apiAs($guard)
            ->getJson('/api/v1/warung/businesses/'.$warung->getKey().'/shifts')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_order_report_is_scoped_to_the_authorized_business(): void
    {
        [$owner, $guard, $warung, $wholesaler, $product] = $this->marketplace();
        $this->createOrder($guard, $warung, $wholesaler, $product, 'report-order', 2);
        [$otherOwner, $otherGuard, $otherWarung] = $this->marketplace(withoutProduct: true);

        $this->apiAs($owner)
            ->getJson('/api/v1/warung/reports?business_id='.$warung->getKey())
            ->assertOk()
            ->assertJsonPath('data.order_count', 1)
            ->assertJsonPath('data.ordered_amount_total', '20666.00')
            ->assertJsonPath('data.by_counterparty.0.business_name', $wholesaler->name)
            ->assertJsonPath('data.top_products.0.quantity', 2);

        $this->apiAs($otherOwner)
            ->getJson('/api/v1/warung/reports?business_id='.$warung->getKey())
            ->assertForbidden();
        $this->apiAs($guard)
            ->getJson('/api/v1/warung/reports?business_id='.$warung->getKey())
            ->assertForbidden();
    }

    public function test_web_guard_can_place_order_and_owner_can_approve_and_open_checkout(): void
    {
        Notification::fake();
        Http::fake(['https://snap.sandbox.test/*' => Http::response([
            'token' => 'web-snap-token',
            'redirect_url' => 'https://snap.sandbox.test/checkout/web-snap-token',
        ])]);
        [$owner, $guard, $warung, $wholesaler, $product] = $this->marketplace();
        $owner->refresh();
        $guard->refresh();

        $this->actingAs($guard)
            ->get('/mad-warung/'.$warung->getKey())
            ->assertOk()
            ->assertSee('Pesan stok');
        $this->actingAs($guard)
            ->get('/mad-warung/'.$warung->getKey().'/katalog')
            ->assertOk()
            ->assertSee('Minyak goreng 1L')
            ->assertSee('Ajukan pesanan ke');
        $this->actingAs($guard)
            ->post('/mad-warung/'.$warung->getKey().'/orders', [
                'wholesaler_id' => $wholesaler->getKey(),
                'items' => [['product_id' => $product->getKey(), 'quantity' => 2]],
                'delivery_method' => 'ambil_sendiri',
            ])
            ->assertRedirect('/mad-warung/'.$warung->getKey());
        $order = Order::query()->firstOrFail();
        $this->assertSame('pending_approval', $order->status);

        $this->actingAs($owner)
            ->post('/mad-warung/orders/'.$order->getKey().'/decide', ['decision' => 'approved'])
            ->assertRedirect('/mad-warung/'.$warung->getKey());
        $this->actingAs($owner)
            ->post('/mad-warung/orders/'.$order->getKey().'/pay')
            ->assertRedirect('https://snap.sandbox.test/checkout/web-snap-token');
        $this->actingAs($guard)
            ->post('/mad-warung/orders/'.$order->getKey().'/pay')
            ->assertForbidden();

        $this->assertSame('pending', $order->fresh()->paymentIntent->status);
    }

    /**
     * Plafon penjaga terlihat sebelum ia mengetik jumlah (blueprint/04 §3).
     *
     * Angkanya dibaca dari `member_limits` + `orders` hari ini, tabel yang sama
     * dengan yang dipakai CreateWarungOrder. Pemilik tidak dibatasi plafon, jadi
     * kartu ini tidak boleh muncul untuknya — kalau muncul, artinya angka yang
     * ditampilkan bukan plafon siapa pun.
     */
    public function test_catalog_shows_guard_spending_limit_only_for_guard(): void
    {
        [$owner, $guard, $warung] = $this->marketplace();

        // Keanggotaan baru dipasang di dalam fixture; instance user perlu disegarkan
        // agar izin yang dibaca ActionAuthorizer tidak memakai keadaan lama.
        $owner->refresh();
        $guard->refresh();

        MemberLimit::query()->create([
            'business_id' => $warung->getKey(),
            'user_id' => $guard->getKey(),
            'daily_amount_limit' => '300000.00',
            'per_order_limit' => '150000.00',
            'requires_approval_above' => '100000.00',
            'updated_by' => $owner->getKey(),
        ]);

        $this->actingAs($guard)
            ->get('/mad-warung/'.$warung->getKey().'/katalog')
            ->assertOk()
            ->assertSee('Sisa plafon hari ini')
            ->assertSee('Rp 300.000')
            ->assertSee('Rp 150.000')
            ->assertSee('perlu persetujuan pemilik');

        $this->actingAs($owner)
            ->get('/mad-warung/'.$warung->getKey().'/katalog')
            ->assertOk()
            ->assertDontSee('Sisa plafon hari ini');
    }

    private function marketplace(bool $withoutProduct = false): array
    {
        $owner = User::factory()->accountType(AccountType::WarungOwner)->create();
        $guard = User::factory()->accountType(AccountType::WarungGuard)->create();
        $wholesalerOwner = User::factory()->accountType(AccountType::GrosirOwner)->create();
        $warung = $this->business($owner, 'warung', 'Warung Test');
        $wholesaler = $this->business($wholesalerOwner, 'grosir', 'Grosir Test');
        $warung->members()->attach($guard, [
            'role' => 'penjaga',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        if ($withoutProduct) {
            return [$owner, $guard, $warung, $wholesaler, $wholesalerOwner];
        }
        $product = Product::query()->create([
            'sku' => 'SKU-'.strtoupper(substr(md5((string) $guard->getKey()), 0, 10)),
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
            'stock_available' => 20,
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

    private function setLimit(
        Business $warung,
        User $guard,
        string $daily,
        string $perOrder,
        string $approval,
    ): void {
        MemberLimit::query()->create([
            'business_id' => $warung->getKey(),
            'user_id' => $guard->getKey(),
            'daily_amount_limit' => $daily,
            'per_order_limit' => $perOrder,
            'requires_approval_above' => $approval,
        ]);
    }

    private function createOrder(
        User $guard,
        Business $warung,
        Business $wholesaler,
        Product $product,
        string $key,
        int $quantity,
        string $deliveryMethod = 'ambil_sendiri',
    ): Order {
        $response = $this->apiAs($guard)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/warung/orders', [
                'business_id' => $warung->getKey(),
                'wholesaler_id' => $wholesaler->getKey(),
                'items' => [['product_id' => $product->getKey(), 'quantity' => $quantity]],
                'delivery_method' => $deliveryMethod,
                'delivery_address' => $deliveryMethod === 'antar_grosir' ? 'Jl. Contoh No. 1' : null,
            ])
            ->assertCreated();

        return Order::query()->findOrFail($response->json('data.id'));
    }

    private function payAndSettle(User $owner, Order $order): PaymentIntent
    {
        Http::fake(['https://snap.sandbox.test/*' => Http::response([
            'token' => 'flow-token-'.$order->getKey(),
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/redirect',
        ])]);
        $this->apiAs($owner)
            ->withHeader('Idempotency-Key', 'owner-pays-'.$order->getKey())
            ->postJson('/api/v1/warung/orders/'.$order->getKey().'/pay')
            ->assertCreated();
        $payment = PaymentIntent::query()->where('order_id', $order->getKey())->firstOrFail();
        $this->postJson('/api/v1/webhooks/midtrans', $this->webhookPayload($payment, 'settlement'))
            ->assertOk();

        return $payment;
    }

    private function webhookPayload(PaymentIntent $payment, string $status): array
    {
        $payload = [
            'order_id' => $payment->provider_order_id,
            'status_code' => '200',
            'gross_amount' => (string) $payment->amount,
            'transaction_status' => $status,
            'transaction_id' => 'midtrans-order-'.$payment->getKey(),
            'fraud_status' => 'accept',
        ];
        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].self::SERVER_KEY,
        );

        return $payload;
    }

    private function apiAs(User $user): static
    {
        Auth::forgetGuards();

        return $this->withToken($user->createToken('warung-feature-test')->plainTextToken);
    }

    private function admin(): array
    {
        $admin = User::factory()->accountType(AccountType::Admin)->create();
        PlatformUserRole::query()->create([
            'user_id' => $admin->getKey(),
            'role' => 'admin',
            'assigned_by' => $admin->getKey(),
        ]);

        return [$admin];
    }

    private function account(string $code): LedgerAccount
    {
        return LedgerAccount::query()->where('code', $code)->firstOrFail();
    }
}
