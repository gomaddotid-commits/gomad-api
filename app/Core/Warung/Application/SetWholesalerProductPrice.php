<?php

namespace App\Core\Warung\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Ledger\Domain\LedgerMoney;
use App\Core\Payment\Domain\PaymentConflict;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SetWholesalerProductPrice
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    public function execute(
        User $user,
        Business $wholesaler,
        Product $product,
        string|int $amount,
        int $minimumQuantity,
        int $stockAvailable,
    ): ProductPrice {
        $this->authorizer->authorize($user, 'catalog.manage', $wholesaler);
        if ($wholesaler->type !== 'grosir' || $wholesaler->status !== 'active' || ! $product->is_active) {
            throw new InvalidArgumentException('Harga hanya dapat dikelola untuk grosir dan produk yang aktif.');
        }
        if ($minimumQuantity < 1 || $minimumQuantity > 10000 || $stockAvailable < 0) {
            throw new InvalidArgumentException('Minimum pemesanan atau stok tidak valid.');
        }
        $price = LedgerMoney::from($amount);
        if (! $price->isPositive() || $price->toCents() % 100 !== 0) {
            throw new InvalidArgumentException('Harga grosir harus berupa rupiah bulat yang lebih besar dari nol.');
        }

        return DB::transaction(function () use ($wholesaler, $product, $price, $minimumQuantity, $stockAvailable): ProductPrice {
            $listing = ProductPrice::query()
                ->where('business_id', $wholesaler->getKey())
                ->where('product_id', $product->getKey())
                ->lockForUpdate()
                ->first();
            if ($listing === null) {
                return ProductPrice::query()->create([
                    'business_id' => $wholesaler->getKey(),
                    'product_id' => $product->getKey(),
                    'price' => $price->toDecimal(),
                    'min_order_quantity' => $minimumQuantity,
                    'stock_available' => $stockAvailable,
                    'stock_reserved' => 0,
                    'is_active' => true,
                ]);
            }
            if ($stockAvailable < $listing->stock_reserved) {
                throw new PaymentConflict('Stok baru tidak boleh lebih rendah daripada jumlah yang sedang direservasi.');
            }
            $listing->forceFill([
                'price' => $price->toDecimal(),
                'min_order_quantity' => $minimumQuantity,
                'stock_available' => $stockAvailable,
                'is_active' => true,
            ])->save();

            return $listing;
        }, attempts: 3);
    }
}
