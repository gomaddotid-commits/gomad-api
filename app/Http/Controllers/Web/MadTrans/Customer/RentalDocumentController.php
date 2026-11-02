<?php

namespace App\Http\Controllers\Web\MadTrans\Customer;

use App\Core\Rental\Application\RentalDocumentAccess;
use App\Http\Controllers\Controller;
use App\Models\RentalDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RentalDocumentController extends Controller
{
    public function index(Request $request): View
    {
        $documents = RentalDocument::query()
            ->where('user_id', $request->user()->getKey())
            ->get()
            ->keyBy('type');

        return view('madtrans.rental.documents', [
            'documents' => $documents,
            'types' => RentalDocument::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
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
        } else {
            RentalDocument::query()->create([
                'user_id' => $request->user()->getKey(),
                'type' => $data['type'],
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'status' => 'uploaded',
            ]);
        }

        return redirect()
            ->route('rental-documents.index')
            ->with('status', 'Dokumen '.strtoupper($data['type']).' diunggah dan menunggu verifikasi admin.');
    }

    public function show(Request $request, RentalDocument $document, RentalDocumentAccess $access): StreamedResponse
    {
        abort_unless($access->canView($request->user(), $document), 403);
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->response($document->file_path, $document->original_name);
    }
}
