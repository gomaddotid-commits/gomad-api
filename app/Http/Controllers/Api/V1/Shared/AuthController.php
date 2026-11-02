<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Core\Identity\Application\Actions\AuthenticateUser;
use App\Core\Identity\Application\Actions\ProvisionBusinessForOwner;
use App\Core\Identity\Application\Actions\RegisterUser;
use App\Core\Identity\Application\Mfa\StartTwoFactorChallenge;
use App\Core\Identity\Application\Mfa\VerifyTwoFactorChallenge;
use App\Core\Identity\Domain\AccountType;
use App\Core\Notification\Application\TwoFactorCodeNotification;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\TwoFactorChallenge;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function register(
        Request $request,
        RegisterUser $register,
        ProvisionBusinessForOwner $provisionBusiness,
    ): JsonResponse {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'account_type' => ['nullable', Rule::in(AccountType::selfRegistrableValues())],
        ]);

        $accountType = AccountType::tryFrom((string) ($data['account_type'] ?? '')) ?? AccountType::Customer;

        $user = $register->execute($data['name'], $data['phone'], $data['email'] ?? null, $data['password'], $accountType);
        $provisionBusiness->execute($user);

        return response()->json(['data' => ['user' => new UserResource($user)]], 201);
    }

    public function login(
        Request $request,
        AuthenticateUser $authenticate,
        StartTwoFactorChallenge $startChallenge,
    ): JsonResponse {
        $data = $request->validate([
            'identity' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);
        $user = $authenticate->execute($data['identity'], $data['password']);

        if ($user === null) {
            return response()->json([
                'message' => 'Nomor telepon/email atau kata sandi tidak sesuai.',
                'code' => 'INVALID_CREDENTIALS',
                'errors' => (object) [],
            ], 401);
        }

        if ($user->two_factor_enabled_at !== null) {
            $started = $startChallenge->execute($user, TwoFactorChallenge::PURPOSE_LOGIN, $request->ip());
            $user->notify(new TwoFactorCodeNotification($started['code'], 'login'));

            return response()->json([
                'data' => [
                    'mfa_required' => true,
                    'mfa_token' => $started['token'],
                    'expires_at' => $started['challenge']->expires_at?->toIso8601String(),
                ],
            ]);
        }

        $token = $user->createToken(
            $data['device_name'] ?? 'mobile',
            ['*'],
            now()->addDays(30),
        );

        return response()->json([
            'data' => [
                'mfa_required' => false,
                'token' => $token->plainTextToken,
                'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
                'user' => new UserResource($user),
            ],
        ]);
    }

    /**
     * Menukar kode verifikasi menjadi token akses. Tantangan dicari lewat token
     * sementara, bukan lewat identitas, supaya tidak bisa dipakai menebak akun lain.
     */
    public function verifyMfa(Request $request, VerifyTwoFactorChallenge $verify): JsonResponse
    {
        $data = $request->validate([
            'mfa_token' => ['required', 'string', 'max:128'],
            'code' => ['required', 'digits:6'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $challenge = $verify->byToken($data['mfa_token'], $data['code']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['code' => $exception->getMessage()]);
        }

        $user = User::query()->findOrFail($challenge->user_id);

        if ($user->two_factor_enabled_at === null) {
            return response()->json([
                'message' => 'Verifikasi dua langkah tidak aktif untuk akun ini.',
                'code' => 'MFA_DISABLED',
                'errors' => (object) [],
            ], 409);
        }

        $token = $user->createToken(
            $data['device_name'] ?? 'mobile',
            ['*'],
            now()->addDays(30),
        );

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
                'user' => new UserResource($user),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['data' => ['status' => 'logged_out']]);
    }

    /**
     * Memperpanjang sesi aplikasi mobile tanpa meminta kata sandi lagi.
     *
     * Token baru memakai nama perangkat yang sama, dan token lama untuk perangkat
     * itu dihapus agar daftar token tidak menumpuk. Token perangkat lain tidak
     * tersentuh — keluar dari satu ponsel tidak mengeluarkan ponsel lain.
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $user->currentAccessToken();
        $deviceName = $this->deviceNameOf($current instanceof PersonalAccessToken ? $current : null);

        $token = $user->createToken($deviceName, ['*'], now()->addDays(30));

        if ($current instanceof PersonalAccessToken) {
            // Semua token lama dengan nama perangkat sama (id lebih kecil) dihapus.
            PersonalAccessToken::query()
                ->where('tokenable_type', $user->getMorphClass())
                ->where('tokenable_id', $user->getKey())
                ->where('name', $deviceName)
                ->where('id', '<', $token->accessToken->getKey())
                ->delete();
        }

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
                'device_name' => $deviceName,
                'user' => new UserResource($user),
            ],
        ]);
    }

    /**
     * Nama perangkat dipakai sebagai penanda token per perangkat. Ditulis sebagai
     * method bertipe agar Scramble tidak menghasilkan tipe gabungan (string + enum)
     * yang membuat klien Dart gagal dikompilasi.
     */
    private function deviceNameOf(?PersonalAccessToken $token): string
    {
        return $token?->name ?? 'mobile';
    }
}
