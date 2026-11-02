<?php

namespace App\Http\Controllers\Web\MadWarung;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Payment\Domain\PaymentConflict;
use App\Core\Warung\Application\RecordRetailSale;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\RetailListing;
use App\Models\RetailSale;
use App\Models\User;
use App\Support\Rupiah;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Kasir warung (POS) — dipakai penjaga, dipantau pemilik.
 *
 * UDS §7.4 memberi penjaga syarat khusus untuk kasir: target sentuh besar,
 * pemilihan produk cepat, langkah sedikit, ringkasan keranjang jelas, dan
 * tindakan checkout yang menonjol. Karena itu layarnya tablet-first, bukan
 * tabel data.
 *
 * Aturan uangnya **tidak** ada di sini: semuanya milik `RecordRetailSale`,
 * supaya web dan API tidak pernah menghitung total dengan cara berbeda.
 */
class RetailPosController extends Controller
{
    public function index(Request $request, Business $business, ActionAuthorizer $authorizer): View
    {
        $this->guard($request, $business, $authorizer, 'pos.sell');

        return view('madwarung.pos', [
            'business' => $business,
            'listings' => RetailListing::query()
                ->where('business_id', $business->getKey())
                ->where('is_active', true)
                ->with('product:id,name,unit,sku,category')
                ->whereHas('product', fn ($query) => $query->where('is_active', true))
                ->get()
                ->sortBy(fn (RetailListing $listing): string => (string) $listing->product?->name)
                ->values(),
            'todayTotals' => $this->todayTotals($business, $request->user()),
            'recentSales' => RetailSale::query()
                ->where('business_id', $business->getKey())
                ->where('sold_by', $request->user()->getKey())
                ->withCount('items')
                ->latest()
                ->limit(5)
                ->get(),
        ]);
    }

    public function store(
        Request $request,
        Business $business,
        ActionAuthorizer $authorizer,
        RecordRetailSale $record,
    ): RedirectResponse {
        $this->guard($request, $business, $authorizer, 'pos.sell');

        $data = $request->validate([
            'items' => ['required', 'array', 'max:200'],
            'items.*' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'paid_amount' => ['nullable', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(?:\.([0-9]{1,2}))?$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $sale = $record->execute(
                $request->user(),
                $business,
                $data['items'],
                $data['paid_amount'] ?? null,
                $data['note'] ?? null,
            );
        } catch (PaymentConflict|InvalidArgumentException $exception) {
            return back()
                ->withErrors(['items' => $exception->getMessage()])
                ->withInput();
        }

        return redirect()
            ->route('warung.pos', $business)
            ->with('status', 'Penjualan '.$sale->code.' tercatat: '
                .Rupiah::format($sale->total_amount).'.');
    }

    /**
     * Pemantauan penjualan untuk pemilik. Pemilik tidak menjalankan kasir
     * (`pos.sell` tidak dipegangnya), jadi halaman ini hanya membaca.
     */
    public function report(Request $request, Business $business, ActionAuthorizer $authorizer): View
    {
        $this->guard($request, $business, $authorizer, 'pos.report');

        $sales = RetailSale::query()
            ->where('business_id', $business->getKey())
            ->with(['items', 'seller:id,name'])
            ->latest()
            ->paginate(25);

        return view('madwarung.pos-report', [
            'business' => $business,
            'sales' => $sales,
            'todayTotals' => $this->todayTotals($business, null),
            'listingCount' => RetailListing::query()
                ->where('business_id', $business->getKey())
                ->where('is_active', true)
                ->count(),
        ]);
    }

    /**
     * @return array{count: int, amount: string}
     */
    private function todayTotals(Business $business, ?User $seller): array
    {
        $query = RetailSale::query()
            ->where('business_id', $business->getKey())
            ->whereDate('created_at', today());

        if ($seller !== null) {
            $query->where('sold_by', $seller->getKey());
        }

        return [
            'count' => (clone $query)->count(),
            'amount' => (string) (clone $query)->sum('total_amount'),
        ];
    }

    private function guard(Request $request, Business $business, ActionAuthorizer $authorizer, string $action): void
    {
        abort_unless($business->type === 'warung', 404);
        $authorizer->authorize($request->user(), $action, $business);
    }
}
