<?php

namespace App\Core\Access\Application;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class ListPermissions
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    /** @return Collection<int, Permission> */
    public function execute(User $user): Collection
    {
        $this->authorizer->authorize($user, 'permissions.read');

        return Permission::query()->orderBy('group')->orderBy('code')->get();
    }
}
