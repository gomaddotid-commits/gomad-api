<?php

namespace App\Core\Identity\Application\Actions;

use App\Core\Access\Application\ActionAuthorizer;
use App\Models\Business;
use App\Models\User;

final class ShowBusiness
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    public function execute(User $user, Business $business): Business
    {
        $this->authorizer->authorize($user, 'business.read', $business);

        return $business->loadCount([
            // Di dalam loadCount, closure menerima query builder biasa — bukan
            // relasi BelongsToMany — sehingga kolom pivot harus ditulis lengkap.
            'members as active_members_count' => fn ($query) => $query->where('business_members.status', 'active'),
        ]);
    }
}
