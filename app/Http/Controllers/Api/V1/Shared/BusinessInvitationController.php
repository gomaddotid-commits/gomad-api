<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Core\Identity\Application\Actions\AcceptBusinessInvitation;
use App\Core\Identity\Application\Actions\IssueBusinessInvitation;
use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessResource;
use App\Http\Resources\UserResource;
use App\Models\Business;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class BusinessInvitationController extends Controller
{
    public function store(
        Business $business,
        Request $request,
        IssueBusinessInvitation $issueInvitation,
    ): JsonResponse {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'role' => ['required', 'string', 'in:penjaga,driver,staf'],
        ]);
        $issued = $issueInvitation->execute(
            $request->user(),
            $business,
            $data['phone'],
            $data['role'],
        );

        return response()->json([
            'data' => [
                'invitation' => [
                    'id' => $issued['invitation']->id,
                    'phone' => $issued['invitation']->phone,
                    'role' => $issued['invitation']->role,
                    'expires_at' => $issued['invitation']->expires_at->toIso8601String(),
                    'url' => route('invitations.accept-page').'#'.$issued['token'],
                ],
            ],
        ], 201);
    }

    public function accept(
        Request $request,
        AcceptBusinessInvitation $acceptInvitation,
    ): JsonResponse {
        $user = Auth::guard('sanctum')->user();
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'name' => [Rule::requiredIf($user === null), 'nullable', 'string', 'min:2', 'max:120'],
            'password' => [Rule::requiredIf($user === null), 'nullable', 'string', 'min:12', 'confirmed'],
            'email' => ['nullable', 'email:rfc', 'max:255', 'unique:users,email'],
        ]);
        $result = $acceptInvitation->execute(
            $data['token'],
            $user,
            $data['name'] ?? null,
            $data['password'] ?? null,
            $data['email'] ?? null,
        );

        return response()->json([
            'data' => [
                'business' => new BusinessResource($result['business']),
                'role' => $result['role'],
                'user' => new UserResource($result['user']),
            ],
        ]);
    }
}
