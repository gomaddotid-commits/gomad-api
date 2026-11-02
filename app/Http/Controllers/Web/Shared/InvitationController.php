<?php

namespace App\Http\Controllers\Web\Shared;

use App\Core\Identity\Application\Actions\AcceptBusinessInvitation;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InvitationController extends Controller
{
    public function show(): View
    {
        return view('shared.invitations.accept');
    }

    public function accept(Request $request, AcceptBusinessInvitation $acceptInvitation): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'name' => [Rule::requiredIf($request->user() === null), 'nullable', 'string', 'min:2', 'max:120'],
            'password' => [Rule::requiredIf($request->user() === null), 'nullable', 'string', 'min:12', 'confirmed'],
            'email' => ['nullable', 'email:rfc', 'max:255', 'unique:users,email'],
        ]);
        $result = $acceptInvitation->execute(
            $data['token'],
            $request->user(),
            $data['name'] ?? null,
            $data['password'] ?? null,
            $data['email'] ?? null,
        );

        if ($request->user() === null) {
            Auth::login($result['user']);
            $request->session()->regenerate();
        }

        return redirect()->route('businesses.show', $result['business'])
            ->with('status', 'Undangan berhasil diterima.');
    }

    public function logout(Request $request): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(route('invitations.accept-page').'#'.$data['token']);
    }
}
