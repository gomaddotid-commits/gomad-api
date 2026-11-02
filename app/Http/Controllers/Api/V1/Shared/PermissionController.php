<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Core\Access\Application\ListPermissions;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    public function index(Request $request, ListPermissions $listPermissions): JsonResponse
    {
        $permissions = $listPermissions->execute($request->user());

        return response()->json([
            'data' => $permissions->map(fn ($permission): array => [
                'code' => $permission->code,
                'label' => $permission->label,
                'group' => $permission->group,
            ])->values(),
        ]);
    }
}
