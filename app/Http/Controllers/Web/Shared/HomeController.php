<?php

namespace App\Http\Controllers\Web\Shared;

use App\Http\Controllers\Controller;
use App\Models\Rental;
use App\Models\TravelBooking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Beranda tunggal yang tahu siapa yang sedang masuk.
 *
 * Satu URL (`/dashboard`) dipakai semua akun setelah login, tetapi isinya tidak
 * pernah bocor antar fungsi: akun mitra diteruskan ke ruang kerjanya sendiri,
 * akun platform ke konsol, supir ke daftar tugasnya, dan pelanggan melihat
 * beranda belanja. Tidak ada akun yang melihat menu fungsi lain.
 */
class HomeController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $type = $user->accountType();
        $home = $type->homeRoute();

        if ($home !== 'dashboard') {
            return redirect()->route($home);
        }

        return view('shared.home.customer', [
            'accountType' => $type,
            'bookings' => TravelBooking::query()
                ->with(['schedule:id,route_id,departure_date,departure_time', 'schedule.route:id,origin_city,destination_city'])
                ->where('customer_id', $user->getKey())
                ->latest()
                ->limit(3)
                ->get(),
            'rentals' => Rental::query()
                ->with('vehicle:id,name,plate_number')
                ->where('customer_id', $user->getKey())
                ->latest()
                ->limit(3)
                ->get(),
        ]);
    }
}
