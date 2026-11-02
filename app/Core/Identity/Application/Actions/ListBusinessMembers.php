<?php

namespace App\Core\Identity\Application\Actions;

use App\Core\Access\Application\ActionAuthorizer;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class ListBusinessMembers
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    /** @return Collection<int, User> */
    public function execute(User $actor, Business $business): Collection
    {
        $this->authorizer->authorize($actor, 'member.read', $business);

        return $business->members()
            ->wherePivot('status', 'active')
            ->orderBy('users.name')
            ->get();
    }
}
