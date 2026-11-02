<?php

namespace App\Http\Controllers\Web\Platform\Admin;

use App\Core\Rental\Application\RentalDocumentAccess;
use App\Http\Controllers\Controller;
use App\Models\RentalDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminDocumentController extends Controller
{
    public function index(Request $request, RentalDocumentAccess $access): View
    {
        abort_unless($access->isPlatformAdmin($request->user()), 403);

        return view('platform.admin.rental-documents', [
            'documents' => RentalDocument::query()
                ->with(['user:id,name,email'])
                ->orderByRaw("CASE WHEN status = 'uploaded' THEN 0 ELSE 1 END")
                ->latest()
                ->limit(100)
                ->get(),
        ]);
    }

    public function verify(Request $request, RentalDocument $document, RentalDocumentAccess $access): RedirectResponse
    {
        abort_unless($access->isPlatformAdmin($request->user()), 403);

        $document->forceFill([
            'status' => 'verified',
            'verified_by' => $request->user()->getKey(),
            'verified_at' => now(),
            'rejection_reason' => null,
        ])->save();

        return back()->with('status', 'Dokumen '.$document->label().' pengguna '.$document->user?->name.' diverifikasi.');
    }

    public function reject(Request $request, RentalDocument $document, RentalDocumentAccess $access): RedirectResponse
    {
        abort_unless($access->isPlatformAdmin($request->user()), 403);

        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $document->forceFill([
            'status' => 'rejected',
            'verified_by' => $request->user()->getKey(),
            'verified_at' => now(),
            'rejection_reason' => $data['rejection_reason'],
        ])->save();

        return back()->with('status', 'Dokumen '.$document->label().' ditolak.');
    }
}
