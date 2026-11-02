<?php

namespace App\Http\Controllers\Web\Shared;

use App\Core\Identity\Application\Actions\UpdateProfile;
use App\Http\Controllers\Controller;
use App\Models\BusinessMember;
use App\Models\Rental;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Halaman akun pelanggan: profil, keamanan kata sandi, dan riwayat sewa.
 *
 * Yang bisa diubah hanya nama, email, dan kata sandi. Nomor telepon tidak dapat
 * diubah sendiri karena dipakai sebagai identitas masuk dan bukti kepemilikan
 * nomor — perubahan nomor adalah urusan verifikasi, bukan formulir profil.
 */
class AccountController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();

        $memberships = BusinessMember::query()
            ->with('business:id,type,name,status')
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        return view('shared.account.show', [
            'user' => $user,
            'memberships' => $memberships,
            'rentalCount' => Rental::query()->where('customer_id', $user->getKey())->count(),
            'unreadNotifications' => $user->unreadNotifications()->count(),
        ]);
    }

    public function update(Request $request, UpdateProfile $updateProfile): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => [
                'nullable',
                'email:rfc',
                'max:255',
                Rule::unique('users', 'email')->ignore($request->user()->getKey()),
            ],
        ]);

        $updateProfile->execute($request->user(), $data['name'], $data['email'] ?? null);

        return back()->with('status', 'Profil diperbarui.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Kata sandi saat ini tidak sesuai.',
            ]);
        }

        $user->forceFill(['password' => Hash::make($data['password'])])->save();

        // Sesi lain (mis. ponsel yang lupa dikeluarkan) diputus: ganti sandi berarti
        // orang lain yang memegang sesi lama tidak boleh tetap masuk.
        auth()->logoutOtherDevices($data['password']);

        return back()->with('status', 'Kata sandi diperbarui. Perangkat lain sudah dikeluarkan.');
    }

    /**
     * Riwayat sewa: satu daftar untuk semua agency, karena pelanggan tidak
     * mengingat nama agensinya — yang diingat adalah tanggal dan mobilnya.
     */
    public function rentals(Request $request): View
    {
        $rentals = Rental::query()
            ->with(['business:id,name', 'vehicle:id,name,plate_number'])
            ->where('customer_id', $request->user()->getKey())
            ->latest()
            ->paginate(15);

        return view('shared.account.rentals', [
            'rentals' => $rentals,
        ]);
    }

    /**
     * Kelengkapan dokumen lepas kunci — ditampilkan di profil supaya penyewa tahu
     * apa yang masih menahan mereka sebelum mencoba memesan mobil.
     */
    public function documents(Request $request): RedirectResponse
    {
        return redirect()->route('rental-documents.index');
    }
}
