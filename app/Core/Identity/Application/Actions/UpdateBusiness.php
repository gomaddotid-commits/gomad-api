<?php

namespace App\Core\Identity\Application\Actions;

use App\Core\Access\Application\ActionAuthorizer;
use App\Models\Business;
use App\Models\User;

final class UpdateBusiness
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    public function execute(User $user, Business $business, string $name): Business
    {
        $this->authorizer->authorize($user, 'business.update', $business);
        $business->forceFill(['name' => trim($name)])->save();

        return $business->refresh();
    }
}
