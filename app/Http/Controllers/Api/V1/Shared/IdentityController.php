<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Identity\Application\Actions\ListMemberships;
use App\Core\Identity\Application\Actions\UpdateProfile;
use App\Core\Identity\Application\Mfa\ManageTwoFactor;
use App\Core\Identity\Application\Mfa\StartTwoFactorChallenge;
use App\Core\Identity\Application\Mfa\VerifyTwoFactorChallenge;
use App\Core\Notification\Application\TwoFactorCodeNotification;
use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessResource;
use App\Http\Resources\UserResource;
use App\Models\TwoFactorChallenge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class IdentityController extends Controller
{
    public function show(Request $request, ActionAuthorizer $authorizer): JsonResponse
    {
        $authorizer->authorize($request->user(), 'profile.read');

        return response()->json(['data' => ['user' => new UserResource($request->user())]]);
    }

    public function update(Request $request, UpdateProfile $updateProfile): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($request->user())],
        ]);

        $user = $updateProfile->execute($request->user(), $data['name'], $data['email'] ?? null);

        return response()->json(['data' => ['user' => new UserResource($user)]]);
    }

    public function memberships(Request $request, ListMemberships $listMemberships): JsonResponse
    {
        return response()->json([
            'data' => BusinessResource::collection($listMemberships->execute($request->user())),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function security(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'two_factor_enabled' => $user->two_factor_enabled_at !== null,
                'two_factor_enabled_at' => $user->two_factor_enabled_at?->toIso8601String(),
                'channel' => 'email',
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * Memulai pengaktifan: kode dikirim ke email, akun belum berubah sampai
     * kode dikonfirmasi.
     *
     * @return array<string, mixed>
     */
    public function startTwoFactor(Request $request, StartTwoFactorChallenge $start): JsonResponse
    {
        $user = $request->user();

        if ($user->email === null) {
            return response()->json([
                'message' => 'Tambahkan email di profil lebih dulu — kode dikirim ke email.',
                'code' => 'EMAIL_REQUIRED',
                'errors' => (object) [],
            ], 422);
        }

        $started = $start->execute($user, TwoFactorChallenge::PURPOSE_ENABLE, $request->ip());
        $user->notify(new TwoFactorCodeNotification($started['code'], 'enable'));

        return response()->json([
            'data' => [
                'mfa_token' => $started['token'],
                'expires_at' => $started['challenge']->expires_at?->toIso8601String(),
                'channel' => 'email',
            ],
        ], 202);
    }

    /**
     * @return array<string, mixed>
     */
    public function confirmTwoFactor(
        Request $request,
        VerifyTwoFactorChallenge $verify,
        ManageTwoFactor $manage,
    ): JsonResponse {
        $data = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        try {
            $verify->execute($request->user(), $data['code'], TwoFactorChallenge::PURPOSE_ENABLE);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['code' => $exception->getMessage()]);
        }

        $user = $manage->enable($request->user());

        return response()->json([
            'data' => [
                'two_factor_enabled' => true,
                'two_factor_enabled_at' => $user->two_factor_enabled_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function disableTwoFactor(Request $request, ManageTwoFactor $manage): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        try {
            $manage->disable($request->user(), $data['password']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['password' => $exception->getMessage()]);
        }

        return response()->json(['data' => ['two_factor_enabled' => false]]);
    }
}
