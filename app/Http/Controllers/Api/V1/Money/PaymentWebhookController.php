<?php

namespace App\Http\Controllers\Api\V1\Money;

use App\Core\Payment\Application\ProcessMidtransWebhook;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    public function midtrans(Request $request, ProcessMidtransWebhook $processWebhook): JsonResponse
    {
        $payload = $request->validate([
            'order_id' => ['required', 'string', 'max:64'],
            'status_code' => ['required', 'string', 'max:8'],
            'gross_amount' => ['required', 'string', 'max:24'],
            'signature_key' => ['required', 'string', 'size:128'],
            'transaction_status' => ['required', 'string', 'max:40'],
            'transaction_id' => ['nullable', 'string', 'max:100'],
            'fraud_status' => ['nullable', 'string', 'max:40'],
        ]);
        $processWebhook->execute($payload, $payload['signature_key']);

        return response()->json(['status' => 'ok']);
    }
}
