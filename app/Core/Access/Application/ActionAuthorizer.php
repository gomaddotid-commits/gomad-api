<?php

namespace App\Core\Access\Application;

use App\Models\Business;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class ActionAuthorizer
{
    public function allows(User $user, string $action, ?Business $business = null): bool
    {
        if ($user->status !== 'active') {
            return false;
        }

        $roles = ['pihak_luar'];

        if ($business !== null) {
            $roles = array_merge($roles, $business->members()
                ->whereKey($user->getKey())
                ->wherePivot('status', 'active')
                ->pluck('business_members.role')
                ->all());
        } else {
            $roles = array_merge($roles, $user->businesses()
                ->wherePivot('status', 'active')
                ->pluck('business_members.role')
                ->all());
        }

        $roles = array_merge($roles, $user->platformRoles()->pluck('role')->all());

        return RolePermission::query()
            ->whereIn('role', array_unique($roles))
            ->whereHas('permission', fn ($query) => $query->where('code', $action))
            ->exists();
    }

    public function authorize(User $user, string $action, ?Business $business = null): void
    {
        if (! $this->allows($user, $action, $business)) {
            throw new AuthorizationException('Anda tidak memiliki izin untuk melakukan aksi ini.');
        }
    }
}
