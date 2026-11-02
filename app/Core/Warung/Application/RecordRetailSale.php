<?php

namespace App\Core\Warung\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Ledger\Domain\LedgerMoney;
use App\Core\Payment\Domain\PaymentConflict;
use App\Models\Business;
use App\Models\RetailListing;
use App\Models\RetailSale;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Mencatat satu transaksi kasir warung (POS).
 *
 * **Tiga keputusan yang dipegang di sini, semuanya disengaja:**
 *
 * 1. **Tidak memposting ke ledger.** Kasir menerima uang tunai di meja warung;
 *    platform tidak pernah memegang uang itu. `BUSINESS.AVAILABLE:<id>` berarti
 *    "uang yang dipegang platform dan bisa dicairkan" — memasukkannya ke sana
 *    akan membuat pemilik bisa mencairkan uang yang tidak pernah diterima
 *    platform. Penjualan tetap terekam (bisa diaudit) di `retail_sales`.
 *
 * 2. **Tanpa biaya platform dan tanpa PPN.** Platform bukan penjual pada
 *    transaksi ini dan tidak memproses pembayarannya, jadi tidak ada dasar untuk
 *    memungut fee; PPN adalah kewajiban penjual (warung) yang bukan PKP pada
 *    model ini. `total_amount` sama dengan jumlah baris — tidak ada potongan
 *    tersembunyi yang harus direkonstruksi pemilik dari pembukuan platform.
 *
 * 3. **Tidak menyentuh stok.** `product_prices.stock_available` adalah stok
 *    **grosir**, bukan stok rak warung; mengurangi angka itu akan merusak barang
 *    yang boleh dijual grosir. Persediaan warung belum dimodelkan, dan kasir
 *    tidak boleh berpura-pura memilikinya.
 *
 * Harga **selalu** diambil dari `retail_listings` di server. Harga yang dikirim
 * klien diabaikan — kalau tidak, siapa pun yang membuka devtools bisa menjual
 * seharga Rp 0.
 */
final class RecordRetailSale
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    /**
     * @param  array<int, int>  $quantities  product_id => jumlah
     */
    public function execute(
        User $seller,
        Business $business,
        array $quantities,
        ?string $paidAmount = null,
        ?string $note = null,
    ): RetailSale {
        $this->authorizer->authorize($seller, 'pos.sell', $business);

        if ($business->type !== 'warung') {
            throw new InvalidArgumentException('Kasir hanya tersedia untuk warung retail.');
        }

        $requested = [];

        foreach ($quantities as $productId => $quantity) {
            $quantity = (int) $quantity;

            if ($quantity > 0) {
                $requested[(int) $productId] = $quantity;
            }
        }

        if ($requested === []) {
            throw new InvalidArgumentException('Pilih minimal satu barang untuk dijual.');
        }

        return DB::transaction(function () use ($seller, $business, $requested, $paidAmount, $note): RetailSale {
            $listings = RetailListing::query()
                ->where('business_id', $business->getKey())
                ->whereIn('product_id', array_keys($requested))
                ->where('is_active', true)
                ->with('product:id,name,unit')
                ->lockForUpdate()
                ->get();

            if ($listings->count() !== count($requested)) {
                throw new PaymentConflict('Ada barang yang tidak dijual di warung ini atau sudah dinonaktifkan.');
            }

            $total = LedgerMoney::zero();
            $lines = [];

            foreach ($listings as $listing) {
                $quantity = $requested[$listing->product_id];
                $unitPrice = LedgerMoney::from((string) $listing->price);

                $lineTotal = self::moneyFromCents($unitPrice->toCents() * $quantity);
                $total = $total->plus($lineTotal);

                $lines[] = [
                    'product_id' => $listing->product_id,
                    'product_name' => (string) $listing->product?->name,
                    'unit' => $listing->product?->unit,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice->toDecimal(),
                    'subtotal' => $lineTotal->toDecimal(),
                ];
            }

            $paid = $total;

            if ($paidAmount !== null && $paidAmount !== '') {
                try {
                    $paid = LedgerMoney::from(trim($paidAmount));
                } catch (InvalidArgumentException) {
                    throw new PaymentConflict('Nominal uang diterima tidak sah.');
                }
            }

            if ($paid->compare($total) < 0) {
                throw new PaymentConflict('Uang diterima kurang dari total belanja.');
            }

            $sale = RetailSale::query()->create([
                'code' => 'MW-PS-'.now()->format('ym').'-'.Str::upper(Str::random(8)),
                'business_id' => $business->getKey(),
                'sold_by' => $seller->getKey(),
                'shift_id' => $this->activeShiftId($seller, $business),
                'payment_method' => 'cash',
                'total_amount' => $total->toDecimal(),
                'paid_amount' => $paid->toDecimal(),
                'change_amount' => $paid->minus($total)->toDecimal(),
                'note' => $note !== null && $note !== '' ? mb_substr($note, 0, 500) : null,
            ]);

            foreach ($lines as $line) {
                $sale->items()->create($line);
            }

            return $sale->load('items');
        }, attempts: 3);
    }

    private function activeShiftId(User $seller, Business $business): ?int
    {
        return Shift::query()
            ->where('business_id', $business->getKey())
            ->where('user_id', $seller->getKey())
            ->where('status', 'active')
            ->latest('started_at')
            ->value('id');
    }

    /**
     * `LedgerMoney::from()` menerima rupiah, bukan sen. Perkalian harga × jumlah
     * menghasilkan sen, jadi harus dikembalikan ke bentuk desimal lebih dulu.
     */
    private static function moneyFromCents(int $cents): LedgerMoney
    {
        return LedgerMoney::from(
            intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT),
        );
    }
}
