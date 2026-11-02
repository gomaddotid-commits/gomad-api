<?php

namespace App\Http\Controllers\Web\Platform\Admin;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Identity\Application\Actions\VerifyBusiness;
use App\Core\Ledger\Application\ReconcileLedger;
use App\Core\Payment\Application\ApproveWithdrawal;
use App\Core\Payment\Application\CompleteWithdrawal;
use App\Core\Payment\Application\FailWithdrawal;
use App\Core\Payment\Domain\PaymentConflict;
use App\Core\Settlement\Application\SettleBusinessFunds;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\LedgerAccount;
use App\Models\Order;
use App\Models\PaymentIntent;
use App\Models\PlatformUserRole;
use App\Models\RentalDocument;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Konsol operasional platform (blueprint/04 §8).
 *
 * Halaman di sini adalah tempat kerja staf platform: melihat antrean yang menunggu
 * keputusan manusia (pencairan, dokumen, verifikasi mitra), memeriksa kesehatan
 * ledger, dan menjalankan settlement. Setiap aksi uang tetap memakai kelas Core
 * yang sama dengan API — layar ini tidak boleh punya aturan sendiri.
 */
class AdminConsoleController extends Controller
{
    public function dashboard(
        Request $request,
        ActionAuthorizer $authorizer,
        ReconcileLedger $reconcile,
    ): View {
        $authorizer->authorize($request->user(), 'ops.view');

        $ledger = $reconcile->execute();
        $mismatched = collect($ledger['accounts'])->reject(fn (array $row): bool => $row['matches']);

        return view('platform.admin.dashboard', [
            'queues' => [
                'withdrawals' => Withdrawal::query()->where('status', 'pending')->count(),
                'withdrawalApproved' => Withdrawal::query()->where('status', 'approved')->count(),
                'documents' => RentalDocument::query()->where('status', 'uploaded')->count(),
                'unverifiedPartners' => Business::query()->whereNull('verified_at')->count(),
                'pendingApproval' => Order::query()->where('status', 'pending_approval')->count(),
                'supportOpen' => SupportTicket::query()->whereIn('status', ['open', 'answered'])->count(),
                'stalePayments' => PaymentIntent::query()
                    ->where('status', 'pending')
                    ->where('created_at', '<', now()->subHours(2))
                    ->count(),
            ],
            'recentWithdrawals' => Withdrawal::query()
                ->with('business:id,name,type')
                ->latest()
                ->limit(6)
                ->get(),
            'recentDocuments' => RentalDocument::query()
                ->with('user:id,name')
                ->where('status', 'uploaded')
                ->latest()
                ->limit(6)
                ->get(),
            'ledgerMismatches' => $mismatched->take(5)->values(),
            'ledgerMismatchCount' => $mismatched->count(),
            'unbalancedCount' => count($ledger['unbalanced_transactions']),
            'warnings' => $this->configurationWarnings(),
        ]);
    }

    public function withdrawals(Request $request, ActionAuthorizer $authorizer): View
    {
        $authorizer->authorize($request->user(), 'withdrawal.review');

        $status = $request->string('status')->toString();
        $allowed = ['pending', 'approved', 'paid', 'failed', 'rejected'];

        return view('platform.admin.withdrawals', [
            'withdrawals' => Withdrawal::query()
                ->with('business:id,name,type')
                ->when(in_array($status, $allowed, true), fn ($query) => $query->where('status', $status))
                ->latest()
                ->paginate(25)
                ->withQueryString(),
            'status' => in_array($status, $allowed, true) ? $status : null,
            'counts' => Withdrawal::query()
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all(),
        ]);
    }

    public function approveWithdrawal(
        Request $request,
        Withdrawal $withdrawal,
        ApproveWithdrawal $approve,
    ): RedirectResponse {
        $this->run(fn () => $approve->execute($request->user(), $withdrawal));

        return back()->with('status', 'Pencairan '.$withdrawal->code.' disetujui.');
    }

    public function completeWithdrawal(
        Request $request,
        Withdrawal $withdrawal,
        CompleteWithdrawal $complete,
    ): RedirectResponse {
        $data = $request->validate([
            'transfer_reference' => ['required', 'string', 'max:100'],
        ]);

        $this->run(fn () => $complete->execute($request->user(), $withdrawal, $data['transfer_reference']));

        return back()->with('status', 'Pencairan '.$withdrawal->code.' ditandai sudah dibayar.');
    }

    public function failWithdrawal(
        Request $request,
        Withdrawal $withdrawal,
        FailWithdrawal $fail,
    ): RedirectResponse {
        $data = $request->validate([
            'failure_code' => ['required', 'string', 'max:80'],
            'failure_message' => ['required', 'string', 'max:500'],
        ]);

        $this->run(fn () => $fail->execute(
            $request->user(),
            $withdrawal,
            $data['failure_code'],
            $data['failure_message'],
        ));

        return back()->with('status', 'Pencairan '.$withdrawal->code.' ditandai gagal.');
    }

    public function settlement(Request $request, ActionAuthorizer $authorizer): View
    {
        $authorizer->authorize($request->user(), 'settlement.execute');

        $payables = LedgerAccount::query()
            ->where('code', 'like', 'BUSINESS.PAYABLE:%')
            ->where('balance_cached', '>', 0)
            ->orderByDesc('balance_cached')
            ->get()
            ->map(function (LedgerAccount $account): ?array {
                $businessId = (int) Str::afterLast($account->code, ':');
                $business = Business::query()->find($businessId);

                if ($business === null) {
                    return null;
                }

                return [
                    'business' => $business,
                    'payable' => (string) $account->balance_cached,
                    'available' => (string) (LedgerAccount::query()
                        ->where('code', 'BUSINESS.AVAILABLE:'.$businessId)
                        ->value('balance_cached') ?? '0.00'),
                ];
            })
            ->filter()
            ->values();

        return view('platform.admin.settlement', [
            'payables' => $payables,
            'recent' => Withdrawal::query()
                ->with('business:id,name')
                ->where('status', 'paid')
                ->latest('paid_at')
                ->limit(10)
                ->get(),
        ]);
    }

    public function settle(
        Request $request,
        Business $business,
        SettleBusinessFunds $settle,
    ): RedirectResponse {
        $data = $request->validate([
            'amount' => ['required', 'string', 'regex:/^[1-9][0-9]{0,15}(?:\.00)?$/'],
        ]);

        $this->run(fn () => $settle->execute(
            $request->user(),
            $business,
            $data['amount'],
            'web-settle-'.$business->getKey().'-'.Str::uuid(),
        ));

        return back()->with('status', 'Settlement untuk '.$business->name.' diposting.');
    }

    public function reconciliation(Request $request, ActionAuthorizer $authorizer, ReconcileLedger $reconcile): View
    {
        $authorizer->authorize($request->user(), 'ops.view');

        $result = $reconcile->execute();
        $accounts = collect($result['accounts']);

        return view('platform.admin.reconciliation', [
            'accounts' => $accounts->values(),
            'mismatchCount' => $accounts->reject(fn (array $row): bool => $row['matches'])->count(),
            'unbalanced' => $result['unbalanced_transactions'],
            'checkedAt' => now(),
        ]);
    }

    public function partners(Request $request, ActionAuthorizer $authorizer): View
    {
        $authorizer->authorize($request->user(), 'business.verify');

        $type = $request->string('type')->toString();
        $allowed = ['warung', 'grosir', 'agency'];

        return view('platform.admin.partners', [
            'businesses' => Business::query()
                ->with('owner:id,name,email')
                ->when(in_array($type, $allowed, true), fn ($query) => $query->where('type', $type))
                ->orderBy('type')
                ->orderBy('name')
                ->paginate(25)
                ->withQueryString(),
            'type' => in_array($type, $allowed, true) ? $type : null,
        ]);
    }

    public function verifyPartner(
        Request $request,
        Business $business,
        VerifyBusiness $verify,
    ): RedirectResponse {
        $data = $request->validate([
            'verified' => ['required', 'boolean'],
        ]);

        $business = $verify->execute($request->user(), $business, (bool) $data['verified']);

        return back()->with(
            'status',
            $business->verified_at !== null
                ? $business->name.' sudah diverifikasi.'
                : 'Verifikasi '.$business->name.' dicabut.',
        );
    }

    public function users(Request $request, ActionAuthorizer $authorizer): View
    {
        $authorizer->authorize($request->user(), 'permissions.read');

        $search = $request->string('q')->toString();

        return view('platform.admin.users', [
            'users' => User::query()
                ->withCount('platformRoles')
                ->with(['platformRoles'])
                ->withCount('businesses')
                ->when($search !== '', fn ($query) => $query->where(
                    fn ($inner) => $inner
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%'),
                ))
                ->latest()
                ->paginate(25)
                ->withQueryString(),
            'search' => $search,
            'roleCounts' => PlatformUserRole::query()
                ->select('role', DB::raw('count(*) as total'))
                ->groupBy('role')
                ->pluck('total', 'role')
                ->all(),
        ]);
    }

    public function parameters(Request $request, ActionAuthorizer $authorizer): View
    {
        $authorizer->authorize($request->user(), 'permissions.read');

        return view('platform.admin.parameters', [
            'groups' => [
                'Mad Trans' => [
                    'Biaya layanan per tiket' => (string) config('services.mad_trans.service_fee', ''),
                    'Komisi platform (basis poin)' => (string) config('services.mad_trans.platform_fee_basis_points', ''),
                    'PPN (basis poin)' => (string) config('services.mad_trans.ppn_basis_points', ''),
                ],
                'Mad Warung' => [
                    'Komisi platform (basis poin)' => (string) config('services.mad_warung.platform_fee_basis_points', ''),
                    'PPN (basis poin)' => (string) config('services.mad_warung.ppn_basis_points', ''),
                ],
                'Rental' => [
                    'Biaya layanan' => (string) config('services.rental.service_fee', ''),
                    'Komisi platform (basis poin)' => (string) config('services.rental.platform_fee_basis_points', ''),
                    'PPN (basis poin)' => (string) config('services.rental.ppn_basis_points', ''),
                ],
                'Pencairan' => [
                    'Minimum pencairan' => (string) config('services.withdrawal.minimum_amount', ''),
                    'Biaya admin' => (string) config('services.withdrawal.admin_fee', ''),
                ],
                'Pembayaran' => [
                    'Batas waktu pembayaran (menit)' => (string) config('services.midtrans.payment_timeout', ''),
                    'Mode produksi' => config('services.midtrans.is_production') ? 'ya' : 'tidak',
                ],
            ],
            'warnings' => $this->configurationWarnings(),
        ]);
    }

    /**
     * Nilai konfigurasi yang kosong membuat alur berhenti di tengah jalan.
     *
     * @return list<string>
     */
    private function configurationWarnings(): array
    {
        $warnings = [];

        foreach ([
            'services.mad_trans.ppn_basis_points' => 'PPN Mad Trans',
            'services.mad_warung.ppn_basis_points' => 'PPN Mad Warung',
            'services.rental.ppn_basis_points' => 'PPN Rental',
            'services.withdrawal.minimum_amount' => 'minimum pencairan',
        ] as $key => $label) {
            if (config($key) === null || config($key) === '') {
                $warnings[] = $label.' belum diset — alur terkait akan ditolak sampai diisi.';
            }
        }

        return $warnings;
    }

    private function run(callable $action): void
    {
        try {
            $action();
        } catch (InvalidArgumentException|PaymentConflict $exception) {
            throw ValidationException::withMessages(['admin' => $exception->getMessage()]);
        }
    }
}
