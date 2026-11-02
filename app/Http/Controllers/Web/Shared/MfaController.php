<?php

namespace App\Http\Controllers\Web\Shared;

use App\Core\Identity\Application\Mfa\ManageTwoFactor;
use App\Core\Identity\Application\Mfa\StartTwoFactorChallenge;
use App\Core\Identity\Application\Mfa\VerifyTwoFactorChallenge;
use App\Core\Notification\Application\TwoFactorCodeNotification;
use App\Http\Controllers\Controller;
use App\Models\TwoFactorChallenge;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Langkah kedua saat masuk: pengguna memasukkan kode 6 angka yang dikirim ke email.
 *
 * Sesi belum dianggap masuk sampai kode terverifikasi — `pending_user_id` hanya
 * penanda sementara, bukan autentikasi. Kode lama dihanguskan setiap kali pengguna
 * meminta kode baru supaya tidak ada dua kode aktif sekaligus.
 */
class MfaController extends Controller
{
    public function challenge(Request $request): View|RedirectResponse
    {
        if ($request->session()->get('mfa.pending_user_id') === null) {
            return redirect()->route('login');
        }

        return view('shared.auth.mfa', [
            'email' => User::query()
                ->whereKey($request->session()->get('mfa.pending_user_id'))
                ->value('email'),
        ]);
    }

    public function verify(
        Request $request,
        VerifyTwoFactorChallenge $verify,
    ): RedirectResponse {
        $data = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $userId = $request->session()->get('mfa.pending_user_id');
        $user = User::query()->whereKey($userId)->first();

        if ($user === null) {
            return redirect()->route('login')->withErrors(['code' => 'Sesi verifikasi berakhir. Silakan masuk lagi.']);
        }

        try {
            $verify->execute($user, $data['code'], TwoFactorChallenge::PURPOSE_LOGIN);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['code' => $exception->getMessage()]);
        }

        Auth::login($user);
        $intended = $request->session()->pull('mfa.intended', route('dashboard'));
        $request->session()->forget('mfa.pending_user_id');
        $request->session()->regenerate();

        return redirect()->intended($intended);
    }

    public function resend(Request $request, StartTwoFactorChallenge $start): RedirectResponse
    {
        $userId = $request->session()->get('mfa.pending_user_id');
        $user = User::query()->whereKey($userId)->first();

        if ($user === null) {
            return redirect()->route('login');
        }

        $started = $start->execute($user, TwoFactorChallenge::PURPOSE_LOGIN, $request->ip());
        $user->notify(new TwoFactorCodeNotification($started['code'], 'login'));

        return back()->with('status', 'Kode baru dikirim ke email Anda.');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget('mfa.pending_user_id');

        return redirect()->route('login')->with('status', 'Verifikasi dibatalkan. Silakan masuk lagi.');
    }

    public function security(Request $request): View
    {
        return view('shared.account.security', [
            'user' => $request->user(),
            'pendingEnable' => $request->session()->get('mfa.enable_pending', false),
        ]);
    }

    public function startEnable(Request $request, StartTwoFactorChallenge $start): RedirectResponse
    {
        $user = $request->user();

        if ($user->email === null) {
            throw ValidationException::withMessages([
                'two_factor' => 'Tambahkan email di profil lebih dulu — kode dikirim ke email.',
            ]);
        }

        $started = $start->execute($user, TwoFactorChallenge::PURPOSE_ENABLE, $request->ip());
        $user->notify(new TwoFactorCodeNotification($started['code'], 'enable'));

        $request->session()->put('mfa.enable_pending', true);

        return back()->with('status', 'Kode dikirim ke '.$user->email.'. Masukkan kode untuk mengaktifkan.');
    }

    public function confirmEnable(
        Request $request,
        VerifyTwoFactorChallenge $verify,
        ManageTwoFactor $manage,
    ): RedirectResponse {
        $data = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $user = $request->user();

        try {
            $verify->execute($user, $data['code'], TwoFactorChallenge::PURPOSE_ENABLE);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['code' => $exception->getMessage()]);
        }

        $manage->enable($user);
        $request->session()->forget('mfa.enable_pending');

        return back()->with('status', 'Verifikasi dua langkah aktif.');
    }

    public function disable(Request $request, ManageTwoFactor $manage): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        try {
            $manage->disable($request->user(), $data['password']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['password' => $exception->getMessage()]);
        }

        return back()->with('status', 'Verifikasi dua langkah dimatikan.');
    }
}
