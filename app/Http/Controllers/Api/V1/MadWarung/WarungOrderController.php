<?php

namespace App\Http\Controllers\Api\V1\MadWarung;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Payment\Application\InitiateWarungOrderPayment;
use App\Core\Warung\Application\CompleteWarungOrder;
use App\Core\Warung\Application\ConfirmOrderReceipt;
use App\Core\Warung\Application\CreateWarungOrder;
use App\Core\Warung\Application\DecideWarungOrder;
use App\Core\Warung\Application\UpdateOrderFulfillment;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class WarungOrderController extends Controller
{
    public function index(Request $request, ActionAuthorizer $authorizer): JsonResponse
    {
        $data = $request->validate([
            'business_id' => ['nullable', 'integer', 'exists:businesses,id'],
            'wholesaler_id' => ['nullable', 'integer', 'exists:businesses,id'],
        ]);
        if (! isset($data['business_id']) && ! isset($data['wholesaler_id'])) {
            throw new \InvalidArgumentException('business_id atau wholesaler_id wajib diisi.');
        }
        $orders = Order::query()->with(['business:id,name', 'wholesaler:id,name', 'creator:id,name']);
        if (isset($data['business_id'])) {
            $warung = Business::query()->findOrFail($data['business_id']);
            abort_unless($warung->type === 'warung', 404);
            $isOwner = (int) $warung->owner_user_id === (int) $request->user()->getKey();
            if ($isOwner) {
                $authorizer->authorize($request->user(), 'order.report', $warung);
                $orders->where('business_id', $warung->getKey());
            } else {
                $authorizer->authorize($request->user(), 'order.create', $warung);
                $orders->where('business_id', $warung->getKey())
                    ->where('created_by', $request->user()->getKey());
            }
        }
        if (isset($data['wholesaler_id'])) {
            $wholesaler = Business::query()->findOrFail($data['wholesaler_id']);
            abort_unless($wholesaler->type === 'grosir', 404);
            $authorizer->authorize($request->user(), 'order.report', $wholesaler);
            $orders->where('wholesaler_id', $wholesaler->getKey());
        }
        $page = $orders->latest()->paginate(20);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Order $order): array => $this->orderData($order))->values(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function store(Request $request, CreateWarungOrder $create): JsonResponse
    {
        $data = $request->validate([
            'business_id' => ['required', 'integer', 'exists:businesses,id'],
            'wholesaler_id' => ['required', 'integer', 'exists:businesses,id'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'delivery_method' => ['required', Rule::in(['antar_grosir', 'ambil_sendiri'])],
            'delivery_address' => ['nullable', 'string', 'max:1000'],
        ]);
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '' || strlen($idempotencyKey) > 128) {
            return response()->json([
                'message' => 'Header Idempotency-Key wajib diisi dengan panjang maksimal 128 karakter.',
                'code' => 'IDEMPOTENCY_KEY_REQUIRED',
                'errors' => (object) [],
            ], 400);
        }
        $order = $create->execute(
            $request->user(),
            Business::query()->findOrFail($data['business_id']),
            Business::query()->findOrFail($data['wholesaler_id']),
            $data['items'],
            $data['delivery_method'],
            $data['delivery_address'] ?? null,
            trim($idempotencyKey),
        );

        return response()->json(['data' => $this->orderData($order->load(['items', 'business', 'wholesaler']))], $order->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Order $order, ActionAuthorizer $authorizer): JsonResponse
    {
        $this->authorizeRead(Auth::guard('sanctum')->user(), $order, $authorizer);

        return response()->json(['data' => $this->orderData($order->load(['items', 'business', 'wholesaler', 'creator', 'approvals']))]);
    }

    public function decide(
        Request $request,
        Order $order,
        DecideWarungOrder $decide,
    ): JsonResponse {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json([
            'data' => $this->orderData($decide->execute(
                $request->user(),
                $order,
                $data['decision'],
                $data['reason'] ?? null,
            )),
        ]);
    }

    public function pay(
        Request $request,
        Order $order,
        InitiateWarungOrderPayment $initiate,
    ): JsonResponse {
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '' || strlen($idempotencyKey) > 128) {
            return response()->json([
                'message' => 'Header Idempotency-Key wajib diisi dengan panjang maksimal 128 karakter.',
                'code' => 'IDEMPOTENCY_KEY_REQUIRED',
                'errors' => (object) [],
            ], 400);
        }
        $payment = $initiate->execute($request->user(), $order, trim($idempotencyKey));

        return response()->json([
            'data' => [
                'payment_code' => $payment->code,
                'order_code' => $order->code,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->status,
                'provider' => 'midtrans_snap',
                'snap_token' => $payment->snap_token,
                'client_key' => config('services.midtrans.client_key'),
                'redirect_url' => $payment->redirect_url,
                'expires_at' => $payment->expires_at?->toIso8601String(),
            ],
        ], $payment->wasRecentlyCreated ? 201 : 200);
    }

    public function fulfill(
        Request $request,
        Order $order,
        UpdateOrderFulfillment $fulfill,
    ): JsonResponse {
        $data = $request->validate(['action' => ['required', Rule::in(['prepare', 'ready', 'dispatch'])]]);

        return response()->json(['data' => $this->orderData(
            $fulfill->execute($request->user(), $order, $data['action']),
        )]);
    }

    public function receive(Request $request, Order $order, ConfirmOrderReceipt $confirm): JsonResponse
    {
        return response()->json(['data' => $this->orderData($confirm->execute($request->user(), $order))]);
    }

    public function complete(Request $request, Order $order, CompleteWarungOrder $complete): JsonResponse
    {
        return response()->json(['data' => $this->orderData($complete->execute($request->user(), $order))]);
    }

    private function authorizeRead(User $user, Order $order, ActionAuthorizer $authorizer): void
    {
        if ((int) $order->business->owner_user_id === (int) $user->getKey()
            || (int) $order->created_by === (int) $user->getKey()) {
            return;
        }
        if ($authorizer->allows($user, 'order.report', $order->business)) {
            return;
        }
        if ($authorizer->allows($user, 'order.report', $order->wholesaler)) {
            return;
        }
        abort(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function orderData(Order $order): array
    {
        return [
            'id' => $order->getKey(),
            'code' => $order->code,
            'business' => $order->business ? [
                'id' => $order->business->getKey(),
                'name' => $order->business->name,
            ] : null,
            'wholesaler' => $order->wholesaler ? [
                'id' => $order->wholesaler->getKey(),
                'name' => $order->wholesaler->name,
            ] : null,
            'created_by' => $order->created_by,
            'creator_name' => $order->creator?->name,
            'status' => $order->status,
            'subtotal' => $order->subtotal,
            'platform_fee' => $order->platform_fee,
            'ppn_amount' => $order->ppn_amount,
            'total_amount' => $order->total_amount,
            'delivery_method' => $order->delivery_method,
            'delivery_address' => $order->delivery_address,
            'items' => $order->items->map(fn ($item): array => [
                'product_id' => $item->product_id,
                'sku' => $item->sku,
                'name' => $item->product_name,
                'unit' => $item->unit,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'subtotal' => $item->subtotal,
            ])->values(),
            'approval' => $order->relationLoaded('approvals')
                ? $order->approvals->map(fn ($approval): array => [
                    'decision' => $approval->decision,
                    'reason' => $approval->reason,
                    'decided_at' => $approval->decided_at?->toIso8601String(),
                ])->values()->all()
                : [],
            'approved_at' => $order->approved_at?->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'ready_at' => $order->ready_at?->toIso8601String(),
            'received_confirmed_by' => $order->received_confirmed_by,
            'received_at' => $order->received_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }
}
