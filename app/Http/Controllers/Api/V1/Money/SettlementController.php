<?php

namespace App\Http\Controllers\Api\V1\Money;

use App\Core\Settlement\Application\SettleBusinessFunds;
use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettlementController extends Controller
{
    public function store(
        Business $business,
        Request $request,
        SettleBusinessFunds $settleBusiness,
    ): JsonResponse {
        $data = $request->validate([
            'amount' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(?:\.[0-9]{1,2})?$/'],
        ]);
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '' || strlen($idempotencyKey) > 128) {
            return response()->json([
                'message' => 'Header Idempotency-Key wajib diisi dengan panjang maksimal 128 karakter.',
                'code' => 'IDEMPOTENCY_KEY_REQUIRED',
                'errors' => (object) [],
            ], 400);
        }

        $transaction = $settleBusiness->execute(
            $request->user(),
            $business,
            $data['amount'],
            trim($idempotencyKey),
        );

        return response()->json([
            'data' => [
                'transaction_id' => $transaction->getKey(),
                'category' => $transaction->category,
                'amount' => $transaction->amount,
                'status' => $transaction->status,
                'business_id' => $business->getKey(),
            ],
        ], 201);
    }
}
