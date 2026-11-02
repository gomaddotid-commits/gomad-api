<?php

namespace App\Http\Controllers\Web\MadWarung;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Warung\Application\GetWarungReport;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\MemberLimit;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Ruang kerja Mad Warung: satu halaman per pekerjaan, dipakai warung maupun grosir.
 *
 * Warung dan grosir memakai controller yang sama karena keduanya berada di
 * vertical yang sama dan berbagi pesanan; yang berbeda hanya tab, hak akses,
 * dan sudut pandang datanya (pembeli vs penjual).
 *
 * Layar ini tidak menciptakan aturan uang sendiri: semua aksi tetap memakai
 * endpoint POST yang sudah ada di WarungController.
 */
class WarungPortalController extends Controller
{
    private const OPEN_STATUSES = [
        'pending_approval',
        'pending_payment',
        'paid',
        'preparing',
        'ready',
        'in_delivery',
        'ready_for_pickup',
        'received',
    ];

    public function dashboard(
        Request $request,
        Business $business,
        ActionAuthorizer $authorizer,
        GetWarungReport $report,
    ): View {
        $this->guard($request, $business, $authorizer);
        $isGrosir = $business->type === 'grosir';
        $canReport = $authorizer->allows($request->user(), 'order.report', $business);

        $orders = $this->ordersQuery($request, $business, $authorizer);

        return view('madwarung.dashboard', [
            'business' => $business,
            'isGrosir' => $isGrosir,
            'canReport' => $canReport,
            'canManageShifts' => $authorizer->allows($request->user(), 'shift.manage', $business),
            'ordersToday' => (clone $orders)->whereDate('created_at', today())->count(),
            'amountToday' => (string) (clone $orders)
                ->whereDate('created_at', today())
                ->whereNotIn('status', ['rejected', 'cancelled', 'expired'])
                ->sum('total_amount'),
            'openOrders' => (clone $orders)->whereIn('status', self::OPEN_STATUSES)->count(),
            'pendingApproval' => (clone $orders)->where('status', 'pending_approval')->count(),
            'toFulfill' => (clone $orders)->whereIn('status', ['paid', 'preparing', 'ready'])->count(),
            'recentOrders' => (clone $orders)
                ->with(['business:id,name', 'wholesaler:id,name', 'creator:id,name', 'items'])
                ->latest()
                ->limit(8)
                ->get(),
            'activeShift' => $this->activeShift($request, $business),
            'guardsNearLimit' => $isGrosir ? collect() : $this->guardsNearLimit($business),
            'report' => $canReport ? $report->execute($request->user(), $business) : null,
        ]);
    }

    public function orders(Request $request, Business $business, ActionAuthorizer $authorizer): View
    {
        $this->guard($request, $business, $authorizer);

        $status = $request->string('status')->toString();
        $allowed = array_merge(self::OPEN_STATUSES, ['completed', 'rejected', 'cancelled', 'expired']);

        $orders = $this->ordersQuery($request, $business, $authorizer)
            ->when(
                in_array($status, $allowed, true),
                fn (Builder $query) => $query->where('status', $status),
            )
            ->with([
                'business:id,name',
                'wholesaler:id,name',
                'creator:id,name',
                'items',
                'approvals',
                'paymentIntent:id,status,redirect_url',
            ])
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('madwarung.orders', [
            'business' => $business,
            'isGrosir' => $business->type === 'grosir',
            'orders' => $orders,
            'status' => in_array($status, $allowed, true) ? $status : null,
            'counts' => $this->ordersQuery($request, $business, $authorizer)
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all(),
            'canApprove' => $authorizer->allows($request->user(), 'order.approve', $business),
            'canPay' => $authorizer->allows($request->user(), 'order.pay', $business),
            'canFulfill' => $authorizer->allows($request->user(), 'order.fulfill', $business),
            'canReceive' => $authorizer->allows($request->user(), 'order.receive', $business),
            'canReport' => $authorizer->allows($request->user(), 'order.report', $business),
        ]);
    }

    public function guards(Request $request, Business $business, ActionAuthorizer $authorizer): View
    {
        $this->guard($request, $business, $authorizer, 'member_limit.manage');
        abort_unless($business->type === 'warung', 404);

        $guards = $business->members()
            ->wherePivot('role', 'penjaga')
            ->wherePivot('status', 'active')
            ->orderBy('name')
            ->get();

        $limits = MemberLimit::query()
            ->where('business_id', $business->getKey())
            ->get()
            ->keyBy('user_id');

        $spentToday = Order::query()
            ->where('business_id', $business->getKey())
            ->whereDate('created_at', today())
            ->whereNotIn('status', ['rejected', 'cancelled', 'expired'])
            ->whereIn('created_by', $guards->pluck('id'))
            ->select('created_by', DB::raw('SUM(total_amount) as spent'))
            ->groupBy('created_by')
            ->pluck('spent', 'created_by');

        return view('madwarung.retail.guards', [
            'business' => $business,
            'guards' => $guards,
            'limits' => $limits,
            'spentToday' => $spentToday,
            'pendingApproval' => $this->ordersQuery($request, $business, $authorizer)
                ->where('status', 'pending_approval')
                ->with(['creator:id,name', 'wholesaler:id,name', 'items'])
                ->oldest()
                ->get(),
        ]);
    }

    public function shifts(Request $request, Business $business, ActionAuthorizer $authorizer): View
    {
        $canManage = $authorizer->allows($request->user(), 'shift.manage', $business);
        $this->guard($request, $business, $authorizer, $canManage ? 'shift.manage' : 'shift.self');
        abort_unless($business->type === 'warung', 404);

        $guards = $canManage
            ? $business->members()
                ->wherePivot('role', 'penjaga')
                ->wherePivot('status', 'active')
                ->orderBy('name')
                ->get()
            : collect();

        $shifts = Shift::query()
            ->with('user:id,name')
            ->where('business_id', $business->getKey())
            ->when(! $canManage, fn (Builder $query) => $query->where('user_id', $request->user()->getKey()))
            ->orderByDesc('scheduled_start_at')
            ->limit(50)
            ->get();

        return view('madwarung.retail.shifts', [
            'business' => $business,
            'canManage' => $canManage,
            'guards' => $guards,
            'shifts' => $shifts,
            'activeShiftId' => Shift::query()
                ->where('business_id', $business->getKey())
                ->where('user_id', $request->user()->getKey())
                ->where('status', 'active')
                ->value('id'),
        ]);
    }

    public function catalog(Request $request, Business $business, ActionAuthorizer $authorizer): View
    {
        $this->guard($request, $business, $authorizer);
        $isGrosir = $business->type === 'grosir';

        $canOrder = ! $isGrosir
            && $business->status === 'active'
            && $authorizer->allows($request->user(), 'order.create', $business);
        $canManageCatalog = $isGrosir && $authorizer->allows($request->user(), 'catalog.manage', $business);

        $wholesalers = $canOrder
            ? Business::query()
                ->where('type', 'grosir')
                ->where('status', 'active')
                ->whereHas('productPrices', fn (Builder $prices) => $prices
                    ->where('is_active', true)
                    ->whereHas('product', fn (Builder $products) => $products->where('is_active', true)))
                ->with(['productPrices' => fn ($prices) => $prices
                    ->where('is_active', true)
                    ->whereHas('product', fn (Builder $products) => $products->where('is_active', true))
                    ->with('product')
                    ->orderBy('product_id')])
                ->orderBy('name')
                ->get()
            : collect();

        $products = $canManageCatalog
            ? Product::query()
                ->where('is_active', true)
                ->with(['prices' => fn ($prices) => $prices->where('business_id', $business->getKey())])
                ->orderBy('category')
                ->orderBy('name')
                ->get()
            : collect();

        return view('madwarung.catalog', [
            'business' => $business,
            'isGrosir' => $isGrosir,
            'canOrder' => $canOrder,
            'canManageCatalog' => $canManageCatalog,
            'wholesalers' => $wholesalers,
            'products' => $products,
            'spendingLimit' => $canOrder ? $this->spendingLimit($request->user(), $business) : null,
            'canReport' => $authorizer->allows($request->user(), 'order.report', $business),
        ]);
    }

    /**
     * Sisa plafon belanja penjaga hari ini.
     *
     * Angka ini dibaca dari tabel yang **sama** dengan yang dipakai
     * `CreateWarungOrder` saat memutuskan perlu-tidaknya persetujuan pemilik.
     * Tujuannya hanya satu: penjaga melihat ruang geraknya sebelum mengetik
     * jumlah, bukan setelah pesanannya ditolak atau masuk antrean persetujuan.
     *
     * Perhitungan platform fee dan PPN tidak diulang di sini — nominal akhir
     * tetap milik server saat pesanan dibuat.
     *
     * @return array{role: string, daily_limit: float, spent_today: float, remaining: float, per_order: float, approval_above: float}|null
     */
    private function spendingLimit(User $user, Business $business): ?array
    {
        $membership = BusinessMember::query()
            ->where('business_id', $business->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->first();

        if ($membership === null) {
            return null;
        }

        $limit = MemberLimit::query()
            ->where('business_id', $business->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        $spentToday = (float) Order::query()
            ->where('business_id', $business->getKey())
            ->where('created_by', $user->getKey())
            ->whereDate('created_at', today())
            ->whereNotIn('status', ['rejected', 'cancelled', 'expired'])
            ->sum('total_amount');

        $dailyLimit = (float) ($limit->daily_amount_limit ?? 0);

        return [
            'role' => (string) $membership->role,
            'daily_limit' => $dailyLimit,
            'spent_today' => $spentToday,
            'remaining' => max(0.0, $dailyLimit - $spentToday),
            'per_order' => (float) ($limit->per_order_limit ?? 0),
            'approval_above' => (float) ($limit->requires_approval_above ?? 0),
        ];
    }

    public function report(Request $request, Business $business, ActionAuthorizer $authorizer, GetWarungReport $report): View
    {
        $this->guard($request, $business, $authorizer, 'order.report');

        return view('madwarung.report', [
            'business' => $business,
            'isGrosir' => $business->type === 'grosir',
            'report' => $report->execute($request->user(), $business),
        ]);
    }

    /**
     * Semua aksi tetap memakai endpoint POST lama; halaman ini hanya membaca.
     */
    private function ordersQuery(Request $request, Business $business, ActionAuthorizer $authorizer): Builder
    {
        $isGrosir = $business->type === 'grosir';
        $isOwner = (int) $business->owner_user_id === (int) $request->user()->getKey();

        $query = Order::query();

        if ($isGrosir) {
            return $query->where('wholesaler_id', $business->getKey());
        }

        $query->where('business_id', $business->getKey());

        if (! $isOwner && ! $authorizer->allows($request->user(), 'order.report', $business)) {
            $query->where('created_by', $request->user()->getKey());
        }

        return $query;
    }

    private function activeShift(Request $request, Business $business): ?Shift
    {
        if ($business->type !== 'warung') {
            return null;
        }

        return Shift::query()
            ->with('user:id,name')
            ->where('business_id', $business->getKey())
            ->where('user_id', $request->user()->getKey())
            ->where('status', 'active')
            ->first();
    }

    /**
     * Penjaga yang pemakaiannya hari ini sudah melewati 80% plafon harian.
     *
     * @return Collection<int, array{name: string, spent: string, limit: string}>
     */
    private function guardsNearLimit(Business $business)
    {
        $limits = MemberLimit::query()
            ->where('business_id', $business->getKey())
            ->where('daily_amount_limit', '>', 0)
            ->get();

        if ($limits->isEmpty()) {
            return collect();
        }

        $spent = Order::query()
            ->where('business_id', $business->getKey())
            ->whereDate('created_at', today())
            ->whereNotIn('status', ['rejected', 'cancelled', 'expired'])
            ->whereIn('created_by', $limits->pluck('user_id'))
            ->select('created_by', DB::raw('SUM(total_amount) as spent'))
            ->groupBy('created_by')
            ->pluck('spent', 'created_by');

        $names = $business->members()
            ->whereIn('users.id', $limits->pluck('user_id'))
            ->pluck('name', 'users.id');

        return $limits
            ->map(function (MemberLimit $limit) use ($spent, $names): ?array {
                $used = (float) ($spent[$limit->user_id] ?? 0);
                $cap = (float) $limit->daily_amount_limit;

                if ($cap <= 0 || $used < $cap * 0.8) {
                    return null;
                }

                return [
                    'name' => (string) ($names[$limit->user_id] ?? 'Penjaga'),
                    'spent' => number_format($used, 2, '.', ''),
                    'limit' => number_format($cap, 2, '.', ''),
                ];
            })
            ->filter()
            ->values();
    }

    private function guard(Request $request, Business $business, ActionAuthorizer $authorizer, string $action = 'catalog.read'): void
    {
        abort_unless(in_array($business->type, ['warung', 'grosir'], true), 404);
        $authorizer->authorize($request->user(), $action, $business);
    }
}
