<?php

namespace App\Http\Controllers\Api\V1\MadWarung;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Payment\Domain\PaymentConflict;
use App\Core\Warung\Application\RecordRetailSale;
use App\Http\Controllers\Controller;
use App\Http\Resources\PosCatalogResource;
use App\Http\Resources\PosSalesResource;
use App\Http\Resources\RetailSaleResource;
use App\Models\Business;
use App\Models\RetailListing;
use App\Models\RetailSale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Kasir warung (POS) — pasangan API dari layar `/mad-warung/{business}/kasir`.
 *
 * Tiga endpoint, dua izin:
 * - `pos.sell`  → lihat barang yang dijual + catat penjualan (penjaga)
 * - `pos.report`→ riwayat nota seluruh warung (pemilik)
 *
 * Semua aturan uang tetap milik `RecordRetailSale`. Controller ini tidak
 * menghitung total, tidak mempercayai harga dari klien, dan tidak menyentuh
 * stok grosir.
 */
class WarungPosController extends Controller
{
    public function listings(Request $request, Business $business, ActionAuthorizer $authorizer): JsonResponse
    {
        $this->guard($request, $business, $authorizer, 'pos.sell');

        $listings = RetailListing::query()
            ->where('business_id', $business->getKey())
            ->where('is_active', true)
            ->with('product:id,name,unit,sku,category')
            ->whereHas('product', fn ($query) => $query->where('is_active', true))
            ->get()
            ->sortBy(fn (RetailListing $listing): string => (string) $listing->product?->name)
            ->values();

        $today = RetailSale::query()
            ->where('business_id', $business->getKey())
            ->where('sold_by', $request->user()->getKey())
            ->whereDate('created_at', today());

        return response()->json([
            'data' => new PosCatalogResource(
                business: $business,
                today: [
                    'count' => (clone $today)->count(),
                    'amount' => (string) (clone $today)->sum('total_amount'),
                ],
                listings: $listings,
            ),
        ]);
    }

    public function store(Request $request, Business $business, ActionAuthorizer $authorizer, RecordRetailSale $record): JsonResponse
    {
        $this->guard($request, $business, $authorizer, 'pos.sell');

        $data = $request->validate([
            /*
            | Baris penjualan dikirim sebagai daftar objek, bukan peta
            | "id => jumlah". Peta tidak bisa dijelaskan dengan baik di OpenAPI,
            | sehingga klien hasil generate mengirim daftar angka tanpa id barang
            | — pernah terjadi dan membuat kasir mustahil dipakai dari aplikasi.
            */
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'paid_amount' => ['nullable', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(?:\.([0-9]{1,2}))?$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $quantities = collect($data['items'])
            ->mapWithKeys(fn (array $line): array => [(int) $line['product_id'] => (int) $line['quantity']])
            ->all();

        try {
            $sale = $record->execute(
                $request->user(),
                $business,
                $quantities,
                $data['paid_amount'] ?? null,
                $data['note'] ?? null,
            );
        } catch (PaymentConflict|InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['items' => [$exception->getMessage()]],
            ], 422);
        }

        return response()->json(['data' => new RetailSaleResource($sale)], 201);
    }

    public function sales(Request $request, Business $business, ActionAuthorizer $authorizer): JsonResponse
    {
        $this->guard($request, $business, $authorizer, 'pos.report');

        $sales = RetailSale::query()
            ->where('business_id', $business->getKey())
            ->with(['items', 'seller:id,name'])
            ->latest()
            ->paginate(25);

        return response()->json(new PosSalesResource($sales));
    }

    /**
     * Penjaga terakhir sebelum aturan bisnis: usaha ini memang warung, dan
     * pengguna berhak atas aksinya. Dipanggil di awal setiap endpoint supaya
     * klien tidak pernah menghitung izin sendiri.
     */
    private function guard(Request $request, Business $business, ActionAuthorizer $authorizer, string $action): void
    {
        abort_unless($business->type === 'warung', 404);
        $authorizer->authorize($request->user(), $action, $business);
    }
}
