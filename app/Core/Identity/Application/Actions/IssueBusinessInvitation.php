<?php

namespace App\Core\Identity\Application\Actions;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Identity\Domain\PhoneNumber;
use App\Models\Business;
use App\Models\BusinessInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class IssueBusinessInvitation
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    /** @return array{invitation: BusinessInvitation, token: string} */
    public function execute(User $actor, Business $business, string $phone, string $role): array
    {
        $this->authorizer->authorize($actor, 'member.invite', $business);
        try {
            $phone = PhoneNumber::fromInput($phone)->value;
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['phone' => [$exception->getMessage()]]);
        }
        $allowedRoles = match ($business->type) {
            'warung' => ['penjaga', 'staf'],
            'grosir' => ['staf'],
            'agency' => ['driver', 'staf'],
            default => [],
        };

        if (! in_array($role, $allowedRoles, true)) {
            throw ValidationException::withMessages([
                'role' => ['Peran tersebut tidak tersedia untuk jenis usaha ini.'],
            ]);
        }

        if ($business->members()
            ->where('users.phone', $phone)
            ->wherePivot('role', $role)
            ->wherePivot('status', 'active')
            ->exists()) {
            throw ValidationException::withMessages([
                'phone' => ['Pengguna dengan peran tersebut sudah menjadi anggota usaha ini.'],
            ]);
        }

        $token = bin2hex(random_bytes(32));
        $invitation = DB::transaction(function () use ($actor, $business, $phone, $role, $token): BusinessInvitation {
            $lockedBusiness = Business::query()->whereKey($business->getKey())->lockForUpdate()->firstOrFail();
            $lockedBusiness->invitations()
                ->where('phone', $phone)
                ->where('role', $role)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->update(['revoked_at' => now()]);

            return $lockedBusiness->invitations()->create([
                'invited_by' => $actor->getKey(),
                'phone' => $phone,
                'role' => $role,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addDays(7),
            ]);
        });

        return ['invitation' => $invitation, 'token' => $token];
    }
}
