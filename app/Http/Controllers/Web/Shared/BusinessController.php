<?php

namespace App\Http\Controllers\Web\Shared;

use App\Core\Identity\Application\Actions\CreateBusiness;
use App\Core\Identity\Application\Actions\IssueBusinessInvitation;
use App\Core\Identity\Application\Actions\ListBusinesses;
use App\Core\Identity\Application\Actions\ListBusinessMembers;
use App\Core\Identity\Application\Actions\RemoveBusinessMember;
use App\Core\Identity\Application\Actions\ShowBusiness;
use App\Core\Identity\Application\Actions\UpdateBusiness;
use App\Core\Identity\Application\Actions\UpdateBusinessMember;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BusinessController extends Controller
{
    public function index(Request $request, ListBusinesses $listBusinesses): View
    {
        return view('shared.businesses.index', [
            'businesses' => $listBusinesses->execute($request->user()),
        ]);
    }

    public function store(Request $request, CreateBusiness $createBusiness): RedirectResponse
    {
        $type = $request->user()->accountType()->businessType();

        if ($type === null) {
            throw ValidationException::withMessages([
                'type' => ['Akun '.$request->user()->accountType()->label().' tidak dapat membuat usaha. Daftar akun mitra lebih dulu.'],
            ]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'type' => ['required', Rule::in([$type])],
        ]);
        $business = $createBusiness->execute($request->user(), $data['name'], $data['type']);

        return redirect()->route('businesses.show', $business);
    }

    /**
     * Melengkapi nama usaha hasil provisioning otomatis saat pendaftaran mitra.
     */
    public function update(
        Business $business,
        Request $request,
        UpdateBusiness $updateBusiness,
    ): RedirectResponse {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
        ]);

        $updateBusiness->execute($request->user(), $business, $data['name']);

        return redirect()->route('businesses.index')->with('status', 'Profil usaha diperbarui.');
    }

    public function show(
        Business $business,
        Request $request,
        ShowBusiness $showBusiness,
        ListBusinessMembers $listMembers,
    ): View {
        $business = $showBusiness->execute($request->user(), $business);

        return view('shared.businesses.show', [
            'business' => $business,
            'members' => $listMembers->execute($request->user(), $business),
        ]);
    }

    public function invite(
        Business $business,
        Request $request,
        IssueBusinessInvitation $issueInvitation,
    ): RedirectResponse {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'role' => ['required', 'string', 'in:penjaga,driver,staf'],
        ]);
        $issued = $issueInvitation->execute(
            $request->user(),
            $business,
            $data['phone'],
            $data['role'],
        );

        return redirect()->route('businesses.show', $business)
            ->with('invitation_url', route('invitations.accept-page').'#'.$issued['token']);
    }

    public function updateMember(
        Business $business,
        int $membership,
        Request $request,
        UpdateBusinessMember $updateMember,
    ): RedirectResponse {
        $data = $request->validate([
            'role' => ['required', 'string', Rule::in(['penjaga', 'driver', 'staf'])],
        ]);
        $updateMember->execute($request->user(), $business, $membership, $data['role']);

        return redirect()->route('businesses.show', $business)->with('status', 'Peran anggota diperbarui.');
    }

    public function removeMember(
        Business $business,
        User $user,
        Request $request,
        RemoveBusinessMember $removeMember,
    ): RedirectResponse {
        $removeMember->execute($request->user(), $business, $user);

        return redirect()->route('businesses.show', $business)->with('status', 'Anggota dinonaktifkan.');
    }
}
