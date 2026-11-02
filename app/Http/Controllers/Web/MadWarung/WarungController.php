<?php

namespace App\Http\Controllers\Web\MadWarung;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Payment\Application\InitiateWarungOrderPayment;
use App\Core\Payment\Domain\PaymentConflict;
use App\Core\Payment\Domain\PaymentGatewayException;
use App\Core\Warung\Application\CompleteWarungOrder;
use App\Core\Warung\Application\ConfirmOrderReceipt;
use App\Core\Warung\Application\CreateWarungOrder;
use App\Core\Warung\Application\DecideWarungOrder;
use App\Core\Warung\Application\ManageGuardShift;
use App\Core\Warung\Application\SetMemberLimit;
use App\Core\Warung\Application\SetWholesalerProductPrice;
use App\Core\Warung\Application\UpdateOrderFulfillment;
use App\Core\Warung\Domain\CheckoutNotConfigured;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class WarungController extends Controller
{
    public function index(Request $request): View
    {
        $businesses = Business::query()
            ->where(fn (Builder $query) => $query
                ->where('owner_user_id', $request->user()->getKey())
                ->orWhereHas('members', fn (Builder $members) => $members
                    ->whereKey($request->user()->getKey())
                    ->where('business_members.status', 'active')))
            ->whereIn('type', ['warung', 'grosir'])
            ->orderBy('name')
            ->get();

        return view('madwarung.index', compact('businesses'));
    }

    /**
     * Halaman ruang kerja warung/grosir dipindahkan ke WarungPortalController
     * (satu halaman per pekerjaan). Controller ini tinggal menangani aksi POST.
     */
    public function storeOrder(
        Request $request,
        Business $business,
        CreateWarungOrder $create,
    ): RedirectResponse {
        $data = $request->validate([
            'wholesaler_id' => ['required', 'integer', 'exists:businesses,id'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:0', 'max:10000'],
            'delivery_method' => ['required', Rule::in(['antar_grosir', 'ambil_sendiri'])],
            'delivery_address' => ['nullable', 'string', 'max:1000'],
        ]);
        $items = collect($data['items'])
            ->filter(fn (array $item): bool => (int) $item['quantity'] > 0)
            ->values()
            ->all();
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Masukkan jumlah minimal satu produk.']);
        }

        $wholesaler = Business::query()->findOrFail($data['wholesaler_id']);
        $this->runAction(fn () => $create->execute(
            $request->user(),
            $business,
            $wholesaler,
            $items,
            $data['delivery_method'],
            $data['delivery_address'] ?? null,
            'web:'.Str::uuid(),
        ));

        return redirect()->route('warung.show', $business)->with('status', 'Pesanan berhasil diajukan.');
    }

    public function decideOrder(
        Request $request,
        Order $order,
        DecideWarungOrder $decide,
    ): RedirectResponse {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'reason' => ['nullable', 'string', 'max:500', Rule::requiredIf(fn () => $request->input('decision') === 'rejected')],
        ]);
        $this->runAction(fn () => $decide->execute(
            $request->user(),
            $order,
            $data['decision'],
            $data['reason'] ?? null,
        ));

        return $this->backToBusiness($order->business_id, 'Keputusan pesanan berhasil disimpan.');
    }

    public function payOrder(
        Request $request,
        Order $order,
        ActionAuthorizer $authorizer,
        InitiateWarungOrderPayment $initiate,
    ): RedirectResponse {
        $authorizer->authorize($request->user(), 'order.pay', $order->business);
        abort_unless((int) $order->business->owner_user_id === (int) $request->user()->getKey(), 403);
        $payment = $order->paymentIntent;
        if ($payment !== null) {
            if ($payment->status !== 'pending' || $payment->redirect_url === null) {
                throw ValidationException::withMessages([
                    'payment' => 'Pembayaran tidak dapat dilanjutkan otomatis. Hubungi pemilik usaha untuk rekonsiliasi.',
                ]);
            }
        } else {
            $payment = $this->runAction(fn () => $initiate->execute(
                $request->user(),
                $order,
                'web:'.Str::uuid(),
            ));
        }

        $snapHost = parse_url((string) config('services.midtrans.snap_url'), PHP_URL_HOST);
        $redirectHost = parse_url((string) $payment->redirect_url, PHP_URL_HOST);
        if (parse_url((string) $payment->redirect_url, PHP_URL_SCHEME) !== 'https'
            || ! is_string($snapHost)
            || $redirectHost !== $snapHost) {
            throw new \UnexpectedValueException('URL checkout Midtrans tidak cocok dengan host gateway yang dikonfigurasi.');
        }

        return redirect()->away($payment->redirect_url);
    }

    public function fulfillOrder(
        Request $request,
        Order $order,
        UpdateOrderFulfillment $fulfill,
    ): RedirectResponse {
        $data = $request->validate(['action' => ['required', Rule::in(['prepare', 'ready', 'dispatch'])]]);
        $this->runAction(fn () => $fulfill->execute($request->user(), $order, $data['action']));

        return $this->backToBusiness($order->wholesaler_id, 'Status pemenuhan pesanan diperbarui.');
    }

    public function receiveOrder(
        Request $request,
        Order $order,
        ConfirmOrderReceipt $confirm,
    ): RedirectResponse {
        $this->runAction(fn () => $confirm->execute($request->user(), $order));

        return $this->backToBusiness($order->business_id, 'Penerimaan pesanan dikonfirmasi.');
    }

    public function completeOrder(
        Request $request,
        Order $order,
        CompleteWarungOrder $complete,
    ): RedirectResponse {
        $this->runAction(fn () => $complete->execute($request->user(), $order));

        return $this->backToBusiness($order->business_id, 'Pesanan ditandai selesai.');
    }

    public function setLimit(
        Request $request,
        Business $business,
        User $member,
        SetMemberLimit $setLimit,
    ): RedirectResponse {
        $data = $request->validate([
            'daily_amount_limit' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(?:\.00)?$/'],
            'per_order_limit' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(?:\.00)?$/'],
            'requires_approval_above' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(?:\.00)?$/'],
        ]);
        $this->runAction(fn () => $setLimit->execute(
            $request->user(),
            $business,
            $member,
            $data['daily_amount_limit'],
            $data['per_order_limit'],
            $data['requires_approval_above'],
        ));

        return redirect()->route('warung.show', $business)->with('status', 'Plafon penjaga diperbarui.');
    }

    public function setPrice(
        Request $request,
        Business $business,
        Product $product,
        SetWholesalerProductPrice $setPrice,
    ): RedirectResponse {
        $data = $request->validate([
            'price' => ['required', 'string', 'regex:/^[1-9][0-9]{0,15}(?:\.00)?$/'],
            'min_order_quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'stock_available' => ['required', 'integer', 'min:0', 'max:10000000'],
        ]);
        $this->runAction(fn () => $setPrice->execute(
            $request->user(),
            $business,
            $product,
            $data['price'],
            (int) $data['min_order_quantity'],
            (int) $data['stock_available'],
        ));

        return redirect()->route('warung.show', $business)->with('status', 'Harga, minimum order, dan stok diperbarui.');
    }

    public function scheduleShift(
        Request $request,
        Business $business,
        ManageGuardShift $manage,
    ): RedirectResponse {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->runAction(fn () => $manage->schedule(
            $request->user(),
            $business,
            User::query()->findOrFail($data['user_id']),
            Carbon::parse($data['starts_at']),
            Carbon::parse($data['ends_at']),
            $data['notes'] ?? null,
        ));

        return redirect()->route('warung.show', $business)->with('status', 'Shift penjaga dijadwalkan.');
    }

    public function clockIn(Request $request, Shift $shift, ManageGuardShift $manage): RedirectResponse
    {
        $this->runAction(fn () => $manage->clockIn($request->user(), $shift->load('business', 'user')));

        return $this->backToBusiness($shift->business_id, 'Shift dimulai.');
    }

    public function clockOut(Request $request, Shift $shift, ManageGuardShift $manage): RedirectResponse
    {
        $this->runAction(fn () => $manage->clockOut($request->user(), $shift->load('business', 'user')));

        return $this->backToBusiness($shift->business_id, 'Shift diakhiri.');
    }

    private function backToBusiness(int $businessId, string $message): RedirectResponse
    {
        return redirect()->route('warung.show', $businessId)->with('status', $message);
    }

    private function runAction(callable $action): mixed
    {
        try {
            return $action();
        } catch (InvalidArgumentException|PaymentConflict|CheckoutNotConfigured|PaymentGatewayException $exception) {
            throw ValidationException::withMessages(['warung' => $exception->getMessage()]);
        } catch (AuthorizationException $exception) {
            throw $exception;
        }
    }
}
