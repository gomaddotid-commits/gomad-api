<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menjaga area kerja berdasarkan tipe akun.
 *
 * Dipasang pada grup rute, mis. `area:retail` atau `area:agency`. Akun pelanggan
 * yang mencoba membuka ruang kerja mitra akan ditolak di sini — bukan sekadar
 * disembunyikan dari menu.
 */
class EnsureAccountArea
{
    public function handle(Request $request, Closure $next, string ...$areas): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        $type = $user->accountType();

        foreach ($areas as $area) {
            if ($type->canEnter($area)) {
                return $next($request);
            }
        }

        abort(403, 'Akun '.$type->label().' tidak memiliki akses ke area ini.');
    }
}
