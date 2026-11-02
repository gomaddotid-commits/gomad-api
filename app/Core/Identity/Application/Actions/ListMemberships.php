<?php

namespace App\Core\Identity\Application\Actions;

use App\Core\Access\Application\ActionAuthorizer;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class ListMemberships
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    /** @return Collection<int, Business> */
    public function execute(User $user): Collection
    {
        $this->authorizer->authorize($user, 'membership.read');

        return $user->businesses()
            ->wherePivot('status', 'active')
            ->orderBy('businesses.name')
            ->get();
    }
}
