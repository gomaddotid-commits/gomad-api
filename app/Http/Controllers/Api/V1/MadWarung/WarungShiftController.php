<?php

namespace App\Http\Controllers\Api\V1\MadWarung;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Warung\Application\ManageGuardShift;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class WarungShiftController extends Controller
{
    public function index(Request $request, Business $business, ActionAuthorizer $authorizer): JsonResponse
    {
        abort_unless($business->type === 'warung', 404);
        $authorizer->authorize($request->user(), (int) $business->owner_user_id === (int) $request->user()->getKey()
            ? 'shift.manage'
            : 'shift.self', $business);

        $shifts = Shift::query()
            ->with('user:id,name')
            ->where('business_id', $business->getKey())
            ->when(
                (int) $business->owner_user_id !== (int) $request->user()->getKey(),
                fn ($query) => $query->where('user_id', $request->user()->getKey()),
            )
            ->orderByDesc('scheduled_start_at')
            ->paginate(30);

        return response()->json([
            'data' => $shifts->getCollection()->map(fn (Shift $shift): array => $this->shiftData($shift))->values(),
            'meta' => ['page' => $shifts->currentPage(), 'total' => $shifts->total()],
        ]);
    }

    public function schedule(Request $request, Business $business, ManageGuardShift $manage): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $shift = $manage->schedule(
            $request->user(),
            $business,
            User::query()->findOrFail($data['user_id']),
            Carbon::parse($data['starts_at']),
            Carbon::parse($data['ends_at']),
            $data['notes'] ?? null,
        );

        return response()->json(['data' => $this->shiftData($shift->load('user:id,name'))], 201);
    }

    public function clockIn(Request $request, Shift $shift, ManageGuardShift $manage): JsonResponse
    {
        return response()->json(['data' => $this->shiftData($manage->clockIn($request->user(), $shift->load('business', 'user')))]);
    }

    public function clockOut(Request $request, Shift $shift, ManageGuardShift $manage): JsonResponse
    {
        return response()->json(['data' => $this->shiftData($manage->clockOut($request->user(), $shift->load('business', 'user')))]);
    }

    private function shiftData(Shift $shift): array
    {
        return [
            'id' => $shift->getKey(),
            'user_id' => $shift->user_id,
            'user_name' => $shift->user?->name,
            'scheduled_start_at' => $shift->scheduled_start_at?->toIso8601String(),
            'scheduled_end_at' => $shift->scheduled_end_at?->toIso8601String(),
            'started_at' => $shift->started_at?->toIso8601String(),
            'ended_at' => $shift->ended_at?->toIso8601String(),
            'status' => $shift->status,
            'notes' => $shift->notes,
        ];
    }
}
