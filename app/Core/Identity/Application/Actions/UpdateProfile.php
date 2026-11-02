<?php

namespace App\Core\Identity\Application\Actions;

use App\Core\Access\Application\ActionAuthorizer;
use App\Models\User;

final class UpdateProfile
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    public function execute(User $user, string $name, ?string $email): User
    {
        $this->authorizer->authorize($user, 'profile.update');
        $user->forceFill([
            'name' => trim($name),
            'email' => $email === null ? null : mb_strtolower(trim($email)),
        ])->save();

        return $user->refresh();
    }
}
