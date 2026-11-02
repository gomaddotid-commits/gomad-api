<?php

namespace App\Http\Controllers\Api\V1\Money;

use App\Core\Payment\Application\InitiateCollateralTopup;
use App\Core\Payment\Domain\PaymentConflict;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\PaymentIntent;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PaymentController extends Controller
{
    #[HeaderParameter('Idempotency-Key', description: 'Kunci unik untuk mencegah top-up ganda.', required: true, type: 'string')]
    public function store(Request $request, InitiateCollateralTopup $initiate): JsonResponse
    {
        $data = $request->validate([
            'business_id' => ['required', 'integer', 'exists:businesses,id'],
            'amount' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,14})(?:\.00)?$/'],
        ]);
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '' || strlen($idempotencyKey) > 128) {
            return response()->json([
                'message' => 'Header Idempotency-Key wajib diisi dengan panjang maksimal 128 karakter.',
                'code' => 'IDEMPOTENCY_KEY_REQUIRED',
                'errors' => (object) [],
            ], 400);
        }

        $payment = $initiate->execute(
            $request->user(),
            Business::query()->findOrFail($data['business_id']),
            $data['amount'],
            trim($idempotencyKey),
        );
        if ($payment->status !== 'pending' && $payment->status !== 'paid') {
            throw new PaymentConflict('Pembayaran belum dapat dibuka; gunakan kunci idempotensi baru untuk mencoba kembali.');
        }

        return response()->json([
            'data' => [
                'code' => $payment->code,
                'business_id' => $payment->business_id,
                'purpose' => $payment->purpose,
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

    public function show(PaymentIntent $payment): JsonResponse
    {
        $user = Auth::guard('sanctum')->user();
        abort_unless(
            $payment->user_id === $user?->getKey()
                || $payment->business()->where('owner_user_id', $user?->getKey())->exists(),
            404,
        );

        return response()->json([
            'data' => [
                'code' => $payment->code,
                'business_id' => $payment->business_id,
                'purpose' => $payment->purpose,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->status,
                'provider' => 'midtrans_snap',
                'expires_at' => $payment->expires_at?->toIso8601String(),
                'paid_at' => $payment->paid_at?->toIso8601String(),
            ],
        ]);
    }
}
