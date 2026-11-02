<?php

namespace App\Http\Controllers\Web\Shared;

use App\Core\Identity\Application\Actions\AuthenticateUser;
use App\Core\Identity\Application\Actions\ProvisionBusinessForOwner;
use App\Core\Identity\Application\Actions\RegisterUser;
use App\Core\Identity\Application\Mfa\StartTwoFactorChallenge;
use App\Core\Identity\Domain\AccountType;
use App\Core\Notification\Application\TwoFactorCodeNotification;
use App\Core\Referral\Application\ReferralCode;
use App\Core\Referral\Application\RegisterReferral;
use App\Http\Controllers\Controller;
use App\Models\TwoFactorChallenge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function loginForm(): View
    {
        return view('shared.auth.login');
    }

    public function login(
        Request $request,
        AuthenticateUser $authenticate,
        StartTwoFactorChallenge $startChallenge,
    ): RedirectResponse {
        $data = $request->validate([
            'identity' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        $user = $authenticate->execute($data['identity'], $data['password']);

        if ($user === null) {
            throw ValidationException::withMessages([
                'identity' => ['Nomor telepon/email atau kata sandi tidak sesuai.'],
            ]);
        }

        if ($user->two_factor_enabled_at !== null) {
            $started = $startChallenge->execute($user, TwoFactorChallenge::PURPOSE_LOGIN, $request->ip());
            $user->notify(new TwoFactorCodeNotification($started['code'], 'login'));

            $request->session()->put('mfa.pending_user_id', $user->getKey());
            $request->session()->put('mfa.intended', route('dashboard'));

            return redirect()->route('mfa.challenge');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function registerForm(): View
    {
        return view('shared.auth.register', [
            'referralCode' => (string) request()->query('ref', ''),
            'accountTypes' => AccountType::selfRegistrable(),
        ]);
    }

    public function register(
        Request $request,
        RegisterUser $register,
        ReferralCode $referralCodes,
        RegisterReferral $registerReferral,
        ProvisionBusinessForOwner $provisionBusiness,
    ): RedirectResponse {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'account_type' => ['nullable', Rule::in(AccountType::selfRegistrableValues())],
            'ref' => ['nullable', 'string', 'max:20'],
        ]);

        $ref = isset($data['ref']) ? trim((string) $data['ref']) : '';
        if ($ref !== '' && $referralCodes->resolve($ref) === null) {
            throw ValidationException::withMessages(['ref' => 'Kode referral tidak ditemukan.']);
        }

        $accountType = AccountType::tryFrom((string) ($data['account_type'] ?? '')) ?? AccountType::Customer;

        $user = $register->execute(
            $data['name'],
            $data['phone'],
            $data['email'] ?? null,
            $data['password'],
            $accountType,
        );

        // Akun mitra langsung mendapat usaha draft; pemilik tinggal melengkapi nama
        // dan ops memverifikasi. Akun pelanggan/supir/staf tidak mendapat usaha.
        $provisionBusiness->execute($user);

        if ($ref !== '') {
            $registerReferral->execute($user, $ref);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
