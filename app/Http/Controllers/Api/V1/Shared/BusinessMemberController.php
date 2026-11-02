<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Core\Identity\Application\Actions\ListBusinessMembers;
use App\Core\Identity\Application\Actions\RemoveBusinessMember;
use App\Core\Identity\Application\Actions\UpdateBusinessMember;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BusinessMemberController extends Controller
{
    public function index(Business $business, Request $request, ListBusinessMembers $listMembers): JsonResponse
    {
        return response()->json([
            'data' => UserResource::collection($listMembers->execute($request->user(), $business)),
        ]);
    }

    public function update(
        Business $business,
        int $membership,
        Request $request,
        UpdateBusinessMember $updateMember,
    ): JsonResponse {
        $data = $request->validate([
            'role' => ['required', 'string', Rule::in(['penjaga', 'driver', 'staf'])],
        ]);
        $updateMember->execute($request->user(), $business, $membership, $data['role']);

        return response()->json(['data' => ['status' => 'updated']]);
    }

    public function destroy(
        Business $business,
        User $user,
        Request $request,
        RemoveBusinessMember $removeMember,
    ): JsonResponse {
        $removeMember->execute($request->user(), $business, $user);

        return response()->json(['data' => ['status' => 'removed']]);
    }
}
