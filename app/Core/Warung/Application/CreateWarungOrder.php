<?php

namespace App\Core\Warung\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Ledger\Domain\LedgerMoney;
use App\Core\Notification\Application\OrderApprovalRequestedNotification;
use App\Core\Notification\Application\OrderStatusNotification;
use App\Core\Payment\Domain\PaymentConflict;
use App\Core\Warung\Domain\CheckoutNotConfigured;
use App\Core\Warung\Domain\OrderStatus;
use App\Models\Business;
use App\Models\MemberLimit;
use App\Models\Order;
use App\Models\OrderApproval;
use App\Models\OrderItem;
use App\Models\ProductPrice;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CreateWarungOrder
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    /**
     * @param  list<array{product_id: int, quantity: int}>  $requestedItems
     */
    public function execute(
        User $user,
        Business $warung,
        Business $wholesaler,
        array $requestedItems,
        string $deliveryMethod,
        ?string $deliveryAddress,
        string $idempotencyKey,
    ): Order {
        $this->authorizer->authorize($user, 'order.create', $warung);
        if ($warung->type !== 'warung' || $warung->status !== 'active'
            || $warung->owner_user_id === null
            || $wholesaler->type !== 'grosir' || $wholesaler->status !== 'active') {
            throw new InvalidArgumentException('Pesanan hanya dapat dibuat untuk warung dan grosir yang aktif.');
        }
        if (! in_array($deliveryMethod, ['antar_grosir', 'ambil_sendiri'], true)) {
            throw new InvalidArgumentException('Metode pengambilan pesanan tidak valid.');
        }
        if ($deliveryMethod === 'antar_grosir' && trim((string) $deliveryAddress) === '') {
            throw new InvalidArgumentException('Alamat pengantaran wajib diisi.');
        }
        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 128) {
            throw new InvalidArgumentException('Header Idempotency-Key wajib diisi dan maksimal 128 karakter.');
        }
        if ($requestedItems === [] || count($requestedItems) > 50) {
            throw new InvalidArgumentException('Pesanan harus berisi 1 sampai 50 produk.');
        }

        $normalizedItems = collect($requestedItems)
            ->map(fn (array $item): array => [
                'product_id' => (int) $item['product_id'],
                'quantity' => (int) $item['quantity'],
            ])
            ->sortBy('product_id')
            ->values()
            ->all();
        if (count(array_unique(array_column($normalizedItems, 'product_id'))) !== count($normalizedItems)
            || collect($normalizedItems)->contains(fn (array $item): bool => $item['product_id'] < 1 || $item['quantity'] < 1 || $item['quantity'] > 10000)) {
            throw new InvalidArgumentException('Produk tidak boleh duplikat dan kuantitas harus antara 1 hingga 10.000.');
        }
        $requestHash = hash('sha256', json_encode([
            'user_id' => $user->getKey(),
            'business_id' => $warung->getKey(),
            'wholesaler_id' => $wholesaler->getKey(),
            'items' => $normalizedItems,
            'delivery_method' => $deliveryMethod,
            'delivery_address' => trim((string) $deliveryAddress),
        ], JSON_THROW_ON_ERROR));
        $storedIdempotencyKey = 'mw:'.hash('sha256', $warung->getKey().':'.$user->getKey().':'.$idempotencyKey);

        try {
            $order = DB::transaction(function () use (
                $user,
                $warung,
                $wholesaler,
                $normalizedItems,
                $deliveryMethod,
                $deliveryAddress,
                $requestHash,
                $storedIdempotencyKey,
            ): Order {
                $existing = Order::query()
                    ->where('idempotency_key', $storedIdempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    $this->assertSameRequest($existing, $requestHash);

                    return $existing;
                }

                $membership = DB::table('business_members')
                    ->where('business_id', $warung->getKey())
                    ->where('user_id', $user->getKey())
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();
                if ($membership === null) {
                    throw new AuthorizationException('Keanggotaan warung tidak aktif.');
                }

                $priceRows = ProductPrice::query()
                    ->where('business_id', $wholesaler->getKey())
                    ->whereIn('product_id', array_column($normalizedItems, 'product_id'))
                    ->with('product')
                    ->orderBy('product_id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('product_id');
                if ($priceRows->count() !== count($normalizedItems)) {
                    throw new InvalidArgumentException('Satu atau beberapa produk tidak tersedia dari grosir ini.');
                }

                $subtotal = LedgerMoney::zero();
                $items = [];
                foreach ($normalizedItems as $requestedItem) {
                    /** @var ProductPrice $price */
                    $price = $priceRows->get($requestedItem['product_id']);
                    if (! $price->is_active || ! $price->product->is_active
                        || $requestedItem['quantity'] < $price->min_order_quantity) {
                        throw new InvalidArgumentException('Produk tidak aktif atau jumlahnya di bawah minimum pemesanan.');
                    }

                    $unitPrice = LedgerMoney::from((string) $price->price);
                    if ($unitPrice->toCents() % 100 !== 0) {
                        throw new InvalidArgumentException('Harga produk harus berupa rupiah bulat.');
                    }
                    $unitRupiah = intdiv($unitPrice->toCents(), 100);
                    if ($unitRupiah > intdiv(PHP_INT_MAX, $requestedItem['quantity'])) {
                        throw new InvalidArgumentException('Nilai pesanan berada di luar rentang yang didukung.');
                    }
                    $lineTotal = LedgerMoney::from((string) ($unitRupiah * $requestedItem['quantity']));
                    $subtotal = $subtotal->plus($lineTotal);
                    $items[] = [
                        'price' => $price,
                        'product' => $price->product,
                        'quantity' => $requestedItem['quantity'],
                        'unit_price' => $unitPrice,
                        'subtotal' => $lineTotal,
                    ];
                    if ($price->stock_available - $price->stock_reserved < $requestedItem['quantity']) {
                        throw new PaymentConflict('Stok produk tidak mencukupi.');
                    }
                    $price->forceFill([
                        'stock_reserved' => $price->stock_reserved + $requestedItem['quantity'],
                    ])->save();
                }

                [$platformFee, $ppnAmount, $total] = $this->calculateTotal($subtotal);
                $limit = MemberLimit::query()
                    ->where('business_id', $warung->getKey())
                    ->where('user_id', $user->getKey())
                    ->lockForUpdate()
                    ->first();
                $requiresApproval = $membership->role !== 'pemilik' && (
                    $limit === null
                    || ! LedgerMoney::from((string) $limit->per_order_limit)->isPositive()
                    || ! LedgerMoney::from((string) $limit->daily_amount_limit)->isPositive()
                    || $total->compare(LedgerMoney::from((string) $limit->per_order_limit)) > 0
                    || $total->compare(LedgerMoney::from((string) $limit->requires_approval_above)) > 0
                );

                if ($limit !== null && $membership->role !== 'pemilik') {
                    $dailySpentValue = Order::query()
                        ->where('business_id', $warung->getKey())
                        ->where('created_by', $user->getKey())
                        ->whereDate('created_at', now()->toDateString())
                        ->whereNotIn('status', [
                            OrderStatus::Rejected->value,
                            OrderStatus::Cancelled->value,
                            OrderStatus::Expired->value,
                        ])
                        ->sum('total_amount');
                    $dailySpent = LedgerMoney::from((string) $dailySpentValue);
                    if ($dailySpent->plus($total)->compare(LedgerMoney::from((string) $limit->daily_amount_limit)) > 0
                        && LedgerMoney::from((string) $limit->daily_amount_limit)->isPositive()) {
                        throw new PaymentConflict('Pesanan melewati plafon harian penjaga.');
                    }
                }

                $order = Order::query()->create([
                    'code' => 'MW-OR-'.now()->format('ym').'-'.Str::upper(Str::random(8)),
                    'business_id' => $warung->getKey(),
                    'wholesaler_id' => $wholesaler->getKey(),
                    'created_by' => $user->getKey(),
                    'status' => $requiresApproval
                        ? OrderStatus::PendingApproval->value
                        : OrderStatus::PendingPayment->value,
                    'subtotal' => $subtotal->toDecimal(),
                    'platform_fee' => $platformFee->toDecimal(),
                    'ppn_amount' => $ppnAmount->toDecimal(),
                    'total_amount' => $total->toDecimal(),
                    'delivery_method' => $deliveryMethod,
                    'delivery_address' => $deliveryMethod === 'antar_grosir' ? trim((string) $deliveryAddress) : null,
                    'idempotency_key' => $storedIdempotencyKey,
                    'request_hash' => $requestHash,
                    'expires_at' => now()->addDay(),
                ]);
                foreach ($items as $item) {
                    OrderItem::query()->create([
                        'order_id' => $order->getKey(),
                        'product_id' => $item['product']->getKey(),
                        'product_price_id' => $item['price']->getKey(),
                        'sku' => $item['product']->sku,
                        'product_name' => $item['product']->name,
                        'unit' => $item['product']->unit,
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price']->toDecimal(),
                        'subtotal' => $item['subtotal']->toDecimal(),
                    ]);
                }
                if ($requiresApproval) {
                    OrderApproval::query()->create([
                        'order_id' => $order->getKey(),
                        'requested_by' => $user->getKey(),
                        'decision' => 'pending',
                        'reason' => 'Pesanan memerlukan persetujuan pemilik sebelum pembayaran.',
                    ]);
                }

                return $order;
            }, attempts: 3);
        } catch (QueryException $exception) {
            $existing = Order::query()->where('idempotency_key', $storedIdempotencyKey)->first();
            if ($existing === null) {
                throw $exception;
            }
            $this->assertSameRequest($existing, $requestHash);
            $order = $existing;
        }

        if ($order->wasRecentlyCreated) {
            if ($order->status === OrderStatus::PendingApproval->value) {
                Notification::send(
                    $warung->owner,
                    new OrderApprovalRequestedNotification(
                        $order->code,
                        $user->name,
                        (string) $order->total_amount,
                    ),
                );
            } elseif ((int) $warung->owner_user_id !== (int) $user->getKey()) {
                Notification::send(
                    $warung->owner,
                    new OrderStatusNotification($order->code, 'pending_payment'),
                );
            }
        }

        return $order;
    }

    /**
     * @return array{LedgerMoney, LedgerMoney, LedgerMoney}
     */
    private function calculateTotal(LedgerMoney $subtotal): array
    {
        $platformFeeBasisPoints = (int) config('services.mad_warung.platform_fee_basis_points', 300);
        $ppnBasisPoints = config('services.mad_warung.ppn_basis_points');
        if ($platformFeeBasisPoints < 0 || $platformFeeBasisPoints > 10000) {
            throw new \LogicException('MAD_WARUNG_PLATFORM_FEE_BASIS_POINTS must be between 0 and 10000.');
        }
        if (! is_int($ppnBasisPoints) || $ppnBasisPoints < 0 || $ppnBasisPoints > 10000) {
            throw new CheckoutNotConfigured(
                'Tarif PPN Mad Warung harus dikonfigurasi sebelum checkout diaktifkan.',
            );
        }

        $platformFee = LedgerMoney::from((string) $this->calculateBasisPoints(
            intdiv($subtotal->toCents(), 100),
            $platformFeeBasisPoints,
        ));
        $ppnAmount = LedgerMoney::from((string) $this->calculateBasisPoints(
            intdiv($platformFee->toCents(), 100),
            $ppnBasisPoints,
        ));

        return [$platformFee, $ppnAmount, $subtotal->plus($platformFee)->plus($ppnAmount)];
    }

    private function calculateBasisPoints(int $amountRupiah, int $basisPoints): int
    {
        $whole = intdiv($amountRupiah, 10000) * $basisPoints;
        $fraction = intdiv(($amountRupiah % 10000) * $basisPoints + 5000, 10000);

        return $whole + $fraction;
    }

    private function assertSameRequest(Order $order, string $requestHash): void
    {
        if (! hash_equals($order->request_hash, $requestHash)) {
            throw new PaymentConflict('Idempotency-Key sudah digunakan untuk pesanan berbeda.');
        }
    }
}
