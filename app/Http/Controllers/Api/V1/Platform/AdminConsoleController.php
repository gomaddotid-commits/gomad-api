<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Identity\Application\Actions\VerifyBusiness;
use App\Core\Ledger\Application\ReconcileLedger;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Order;
use App\Models\PaymentIntent;
use App\Models\RentalDocument;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Paritas API untuk konsol operasional web: antrean, verifikasi mitra,
 * rekonsiliasi, pengguna, dan parameter. Semua angka dihitung dari model yang
 * sama dengan layar web agar staf tidak melihat dua versi kebenaran.
 */
class AdminConsoleController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    public function overview(Request $request, ActionAuthorizer $authorizer, ReconcileLedger $reconcile): JsonResponse
    {
        $authorizer->authorize($request->user(), 'ops.view');

        $ledger = $reconcile->execute();
        $mismatched = collect($ledger['accounts'])->reject(fn (array $row): bool => $row['matches']);

        return response()->json([
            'data' => [
                'queues' => [
                    'withdrawals_pending' => Withdrawal::query()->where('status', 'pending')->count(),
                    'withdrawals_approved' => Withdrawal::query()->where('status', 'approved')->count(),
                    'documents_pending' => RentalDocument::query()->where('status', 'uploaded')->count(),
                    'partners_unverified' => Business::query()->whereNull('verified_at')->count(),
                    'orders_pending_approval' => Order::query()->where('status', 'pending_approval')->count(),
                    'stale_payments' => PaymentIntent::query()
                        ->where('status', 'pending')
                        ->where('created_at', '<', now()->subHours(2))
                        ->count(),
                ],
                'ledger' => [
                    'accounts_checked' => count($ledger['accounts']),
                    'accounts_mismatched' => $mismatched->count(),
                    'unbalanced_transactions' => count($ledger['unbalanced_transactions']),
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function withdrawals(Request $request, ActionAuthorizer $authorizer): JsonResponse
    {
        $authorizer->authorize($request->user(), 'withdrawal.review');

        $status = $request->string('status')->toString();
        $allowed = ['pending', 'approved', 'paid', 'failed', 'rejected'];

        $page = Withdrawal::query()
            ->with('business:id,name,type')
            ->when(in_array($status, $allowed, true), fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(25);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Withdrawal $withdrawal): array => [
                'id' => $withdrawal->getKey(),
                'code' => $withdrawal->code,
                'status' => $withdrawal->status,
                'business' => [
                    'id' => $withdrawal->business?->getKey(),
                    'name' => $withdrawal->business?->name,
                    'type' => $withdrawal->business?->type,
                ],
                'amount' => (string) $withdrawal->amount,
                'fee_amount' => (string) $withdrawal->fee_amount,
                'net_amount' => (string) $withdrawal->net_amount,
                'bank_code' => $withdrawal->bank_code,
                'bank_account_masked' => '····'.substr((string) $withdrawal->bank_account_number, -4),
                'requested_at' => $withdrawal->created_at?->toIso8601String(),
                'paid_at' => $withdrawal->paid_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function partners(Request $request, ActionAuthorizer $authorizer): JsonResponse
    {
        $authorizer->authorize($request->user(), 'business.verify');

        $type = $request->string('type')->toString();
        $allowed = ['warung', 'grosir', 'agency'];

        $page = Business::query()
            ->with('owner:id,name,email')
            ->when(in_array($type, $allowed, true), fn ($query) => $query->where('type', $type))
            ->orderBy('name')
            ->paginate(25);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Business $business): array => [
                'id' => $business->getKey(),
                'code' => $business->code,
                'name' => $business->name,
                'type' => $business->type,
                'status' => $business->status,
                'verified_at' => $business->verified_at?->toIso8601String(),
                'owner' => [
                    'id' => $business->owner?->getKey(),
                    'name' => $business->owner?->name,
                    'email' => $business->owner?->email,
                ],
            ])->values(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyPartner(Request $request, Business $business, VerifyBusiness $verify): JsonResponse
    {
        $data = $request->validate([
            'verified' => ['required', 'boolean'],
        ]);

        $business = $verify->execute($request->user(), $business, (bool) $data['verified']);

        return response()->json([
            'data' => [
                'id' => $business->getKey(),
                'name' => $business->name,
                'status' => $business->status,
                'verified_at' => $business->verified_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function reconciliation(Request $request, ActionAuthorizer $authorizer, ReconcileLedger $reconcile): JsonResponse
    {
        $authorizer->authorize($request->user(), 'settlement.execute');

        $result = $reconcile->execute();

        return response()->json([
            'data' => [
                'accounts' => $result['accounts'],
                'unbalanced_transactions' => $result['unbalanced_transactions'],
                'checked_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function users(Request $request, ActionAuthorizer $authorizer): JsonResponse
    {
        $authorizer->authorize($request->user(), 'permissions.read');

        $search = $request->string('q')->toString();

        $page = User::query()
            ->with('platformRoles:id,user_id,role')
            ->withCount('businesses')
            ->when($search !== '', fn ($query) => $query->where(
                fn ($inner) => $inner
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%'),
            ))
            ->latest()
            ->paginate(25);

        return response()->json([
            'data' => $page->getCollection()->map(fn (User $user): array => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'status' => $user->status,
                'platform_roles' => $user->platformRoles->pluck('role')->values(),
                'businesses_count' => $user->businesses_count,
            ])->values(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(Request $request, ActionAuthorizer $authorizer): JsonResponse
    {
        $authorizer->authorize($request->user(), 'ops.view');

        return response()->json([
            'data' => [
                'mad_trans' => [
                    'service_fee' => (string) config('services.mad_trans.service_fee', ''),
                    'platform_fee_basis_points' => (string) config('services.mad_trans.platform_fee_basis_points', ''),
                    'ppn_basis_points' => (string) config('services.mad_trans.ppn_basis_points', ''),
                ],
                'mad_warung' => [
                    'platform_fee_basis_points' => (string) config('services.mad_warung.platform_fee_basis_points', ''),
                    'ppn_basis_points' => (string) config('services.mad_warung.ppn_basis_points', ''),
                ],
                'rental' => [
                    'service_fee' => (string) config('services.rental.service_fee', ''),
                    'platform_fee_basis_points' => (string) config('services.rental.platform_fee_basis_points', ''),
                    'ppn_basis_points' => (string) config('services.rental.ppn_basis_points', ''),
                ],
                'withdrawal' => [
                    'minimum_amount' => (string) config('services.withdrawal.minimum_amount', ''),
                    'admin_fee' => (string) config('services.withdrawal.admin_fee', ''),
                ],
                'payment' => [
                    'timeout_minutes' => (string) config('services.midtrans.payment_timeout', ''),
                    'is_production' => (bool) config('services.midtrans.is_production'),
                ],
            ],
        ]);
    }
}
