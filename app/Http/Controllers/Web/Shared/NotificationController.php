<?php

namespace App\Http\Controllers\Web\Shared;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Notifikasi in-app.
 *
 * Sumbernya tabel `notifications` standar Laravel (uuid + data JSON), jadi isi
 * notifikasi ditentukan oleh pengirim — halaman ini hanya perlu menampilkan
 * judul/isi, menandai dibaca, dan memberi jalan ke objek terkait.
 */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->paginate(20);

        return view('shared.notifications.index', [
            'notifications' => $notifications,
            'unreadCount' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $record = $request->user()->notifications()->whereKey($notification)->firstOrFail();

        if ($record->read_at === null) {
            $record->markAsRead();
        }

        $url = $record->data['url'] ?? null;

        return is_string($url) && $url !== ''
            ? redirect()->to($url)
            : back()->with('status', 'Notifikasi ditandai sudah dibaca.');
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('status', 'Semua notifikasi ditandai sudah dibaca.');
    }
}
