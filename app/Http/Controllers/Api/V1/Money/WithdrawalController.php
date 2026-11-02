<?php

namespace App\Http\Controllers\Api\V1\Money;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Payment\Application\ApproveWithdrawal;
use App\Core\Payment\Application\CompleteWithdrawal;
use App\Core\Payment\Application\FailWithdrawal;
use App\Core\Payment\Application\RequestWithdrawal;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Withdrawal;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WithdrawalController extends Controller
{
    #[HeaderParameter('Idempotency-Key', description: 'Kunci unik untuk mencegah pengajuan pencairan ganda.', required: true, type: 'string')]
    public function store(Request $request, RequestWithdrawal $requestWithdrawal): JsonResponse
    {
        $data = $request->validate([
            'business_id' => ['required', 'integer', 'exists:businesses,id'],
            'bank_code' => ['required', 'string', 'max:30'],
            'bank_account_number' => ['required', 'string', 'max:34'],
            'bank_account_name' => ['required', 'string', 'min:2', 'max:120'],
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
        $withdrawal = $requestWithdrawal->execute(
            $request->user(),
            Business::query()->findOrFail($data['business_id']),
            $data['bank_code'],
            $data['bank_account_number'],
            $data['bank_account_name'],
            $data['amount'],
            trim($idempotencyKey),
        );

        return response()->json(['data' => $this->ownerData($withdrawal)], $withdrawal->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Withdrawal $withdrawal): JsonResponse
    {
        $user = Auth::guard('sanctum')->user();
        abort_unless((int) $withdrawal->user_id === (int) $user?->getKey(), 404);

        return response()->json(['data' => $this->ownerData($withdrawal)]);
    }

    public function adminShow(
        Withdrawal $withdrawal,
        Request $request,
        ActionAuthorizer $authorizer,
    ): JsonResponse {
        $authorizer->authorize($request->user(), 'withdrawal.review');

        return response()->json(['data' => $this->ownerData($withdrawal) + [
            'bank_account_number' => $withdrawal->bank_account_number,
            'bank_account_name' => $withdrawal->bank_account_name,
        ]]);
    }

    public function approve(
        Withdrawal $withdrawal,
        Request $request,
        ApproveWithdrawal $approve,
    ): JsonResponse {
        return response()->json(['data' => $this->ownerData($approve->execute($request->user(), $withdrawal))]);
    }

    public function complete(
        Withdrawal $withdrawal,
        Request $request,
        CompleteWithdrawal $complete,
    ): JsonResponse {
        $data = $request->validate([
            'transfer_reference' => ['required', 'string', 'max:100'],
        ]);

        return response()->json([
            'data' => $this->ownerData($complete->execute(
                $request->user(),
                $withdrawal,
                $data['transfer_reference'],
            )),
        ]);
    }

    public function fail(
        Withdrawal $withdrawal,
        Request $request,
        FailWithdrawal $fail,
    ): JsonResponse {
        $data = $request->validate([
            'failure_code' => ['required', 'string', 'max:80'],
            'failure_message' => ['required', 'string', 'max:500'],
        ]);

        return response()->json([
            'data' => $this->ownerData($fail->execute(
                $request->user(),
                $withdrawal,
                $data['failure_code'],
                $data['failure_message'],
            )),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ownerData(Withdrawal $withdrawal): array
    {
        return [
            'id' => $withdrawal->getKey(),
            'code' => $withdrawal->code,
            'business_id' => $withdrawal->business_id,
            'amount' => $withdrawal->amount,
            'fee_amount' => $withdrawal->fee_amount,
            'net_amount' => $withdrawal->net_amount,
            'bank_code' => $withdrawal->bank_code,
            'bank_account_number_masked' => '••••'.substr($withdrawal->bank_account_number, -4),
            'status' => $withdrawal->status,
            'failure_code' => $withdrawal->failure_code,
            'failure_message' => $withdrawal->failure_message,
            'created_at' => $withdrawal->created_at?->toIso8601String(),
            'approved_at' => $withdrawal->approved_at?->toIso8601String(),
            'paid_at' => $withdrawal->paid_at?->toIso8601String(),
            'failed_at' => $withdrawal->failed_at?->toIso8601String(),
        ];
    }
}
