<?php

namespace App\Core\Identity\Application\Actions;

use App\Core\Access\Application\ActionAuthorizer;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class ListBusinesses
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    /** @return Collection<int, Business> */
    public function execute(User $user): Collection
    {
        $this->authorizer->authorize($user, 'membership.read');

        if ($user->platformRoles()->where('role', 'admin')->exists()) {
            return Business::query()->orderBy('name')->get();
        }

        return $user->businesses()
            ->wherePivot('status', 'active')
            ->orderBy('businesses.name')
            ->get();
    }
}
