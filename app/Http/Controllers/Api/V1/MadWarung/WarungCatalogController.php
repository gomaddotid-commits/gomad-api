<?php

namespace App\Http\Controllers\Api\V1\MadWarung;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Warung\Application\PublishCatalogProduct;
use App\Core\Warung\Application\SetWholesalerProductPrice;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WarungCatalogController extends Controller
{
    public function wholesalers(Request $request, ActionAuthorizer $authorizer): JsonResponse
    {
        $data = $request->validate([
            'business_id' => ['required', 'integer', 'exists:businesses,id'],
        ]);
        $warung = Business::query()->findOrFail($data['business_id']);
        $authorizer->authorize($request->user(), 'catalog.read', $warung);
        abort_unless($warung->type === 'warung' && $warung->status === 'active', 404);

        $wholesalers = Business::query()
            ->where('type', 'grosir')
            ->where('status', 'active')
            ->whereHas('productPrices', fn ($query) => $query
                ->where('is_active', true)
                ->whereHas('product', fn ($products) => $products->where('is_active', true)))
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn (Business $business): array => [
                'id' => $business->getKey(),
                'name' => $business->name,
                'code' => $business->code,
            ]);

        return response()->json(['data' => $wholesalers]);
    }

    public function index(Request $request, ActionAuthorizer $authorizer): JsonResponse
    {
        $data = $request->validate([
            'business_id' => ['required', 'integer', 'exists:businesses,id'],
            'wholesaler_id' => ['required', 'integer', 'exists:businesses,id'],
        ]);
        $warung = Business::query()->findOrFail($data['business_id']);
        $wholesaler = Business::query()->findOrFail($data['wholesaler_id']);
        $authorizer->authorize($request->user(), 'catalog.read', $warung);
        abort_unless(
            $warung->type === 'warung' && $warung->status === 'active'
                && $wholesaler->type === 'grosir' && $wholesaler->status === 'active',
            404,
        );

        $products = DB::table('product_prices')
            ->join('products', 'products.id', '=', 'product_prices.product_id')
            ->where('product_prices.business_id', $wholesaler->getKey())
            ->where('product_prices.is_active', true)
            ->where('products.is_active', true)
            ->orderBy('products.category')
            ->orderBy('products.name')
            ->get([
                'products.id',
                'products.sku',
                'products.name',
                'products.category',
                'products.unit',
                'product_prices.price',
                'product_prices.min_order_quantity',
                'product_prices.stock_available',
                'product_prices.stock_reserved',
            ])
            ->map(fn (object $product): array => [
                'id' => (int) $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'category' => $product->category,
                'unit' => $product->unit,
                'price' => $product->price,
                'min_order_quantity' => (int) $product->min_order_quantity,
                'stock_available' => (int) $product->stock_available - (int) $product->stock_reserved,
            ]);

        return response()->json(['data' => $products]);
    }

    public function storeProduct(Request $request, PublishCatalogProduct $publish): JsonResponse
    {
        $data = $request->validate([
            'sku' => ['nullable', 'string', 'max:40', 'unique:products,sku'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'category' => ['nullable', 'string', 'max:80'],
            'unit' => ['required', 'string', 'max:30'],
        ]);
        $product = $publish->execute(
            $request->user(),
            $data['name'],
            $data['unit'],
            $data['category'] ?? null,
            $data['sku'] ?? null,
        );

        return response()->json(['data' => $this->productData($product)], 201);
    }

    public function setPrice(
        Request $request,
        Business $wholesaler,
        Product $product,
        SetWholesalerProductPrice $setPrice,
    ): JsonResponse {
        $data = $request->validate([
            'price' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(?:\.00)?$/'],
            'min_order_quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'stock_available' => ['required', 'integer', 'min:0', 'max:10000000'],
        ]);
        $price = $setPrice->execute(
            $request->user(),
            $wholesaler,
            $product,
            $data['price'],
            $data['min_order_quantity'],
            $data['stock_available'],
        );

        return response()->json([
            'data' => [
                'product_id' => $price->product_id,
                'business_id' => $price->business_id,
                'price' => $price->price,
                'min_order_quantity' => $price->min_order_quantity,
                'stock_available' => $price->stock_available,
                'stock_reserved' => $price->stock_reserved,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function productData(Product $product): array
    {
        return [
            'id' => $product->getKey(),
            'sku' => $product->sku,
            'name' => $product->name,
            'category' => $product->category,
            'unit' => $product->unit,
            'is_active' => $product->is_active,
        ];
    }
}
