<?php

namespace App\Http\Controllers\Api\V1\MadTrans\Rental;

use App\Http\Controllers\Controller;
use App\Models\RentalDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Dokumen sewa lepas kunci untuk klien mobile. Verifikasi tetap hanya lewat admin platform.
 */
class RentalDocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $documents = RentalDocument::query()
            ->where('user_id', $request->user()->getKey())
            ->get(['type', 'status', 'original_name', 'verified_at', 'rejection_reason', 'updated_at']);

        $byType = $documents->keyBy('type');

        return response()->json([
            'data' => [
                'types' => RentalDocument::TYPES,
                'verified_all' => count(array_intersect(
                    RentalDocument::TYPES,
                    $documents->where('status', 'verified')->pluck('type')->all(),
                )) === count(RentalDocument::TYPES),
                'documents' => collect(RentalDocument::TYPES)->map(fn (string $type): array => [
                    'type' => $type,
                    'label' => (new RentalDocument(['type' => $type]))->label(),
                    'status' => $this->statusOf($byType->get($type)),
                    'original_name' => $this->originalNameOf($byType->get($type)),
                    'verified_at' => $this->verifiedAtOf($byType->get($type)),
                    'rejection_reason' => $this->rejectionReasonOf($byType->get($type)),
                    'updated_at' => $byType->get($type)?->updated_at?->toIso8601String(),
                ])->all(),
            ],
        ]);
    }

    private function statusOf(?RentalDocument $document): string
    {
        return $document?->status ?? 'missing';
    }

    private function originalNameOf(?RentalDocument $document): ?string
    {
        $name = $document?->original_name;

        return $name === null ? null : (string) $name;
    }

    private function verifiedAtOf(?RentalDocument $document): ?string
    {
        return $document?->verified_at?->toIso8601String();
    }

    private function rejectionReasonOf(?RentalDocument $document): ?string
    {
        $reason = $document?->rejection_reason;

        return $reason === null ? null : (string) $reason;
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(RentalDocument::TYPES)],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],
        ]);

        $file = $request->file('file');
        $path = $file->storeAs(
            'rental-documents/'.$request->user()->getKey(),
            $data['type'].'-'.Str::lower((string) Str::ulid()).'.'.$file->getClientOriginalExtension(),
            'local',
        );

        $existing = RentalDocument::query()
            ->where('user_id', $request->user()->getKey())
            ->where('type', $data['type'])
            ->first();

        if ($existing !== null) {
            Storage::disk('local')->delete($existing->file_path);
            $existing->forceFill([
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'status' => 'uploaded',
                'verified_by' => null,
                'verified_at' => null,
                'rejection_reason' => null,
            ])->save();
            $document = $existing;
        } else {
            $document = RentalDocument::query()->create([
                'user_id' => $request->user()->getKey(),
                'type' => $data['type'],
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'status' => 'uploaded',
            ]);
        }

        return response()->json([
            'data' => [
                'type' => $document->type,
                'status' => $document->status,
                'original_name' => $document->original_name,
            ],
        ], 201);
    }
}
