<?php

namespace App\Core\Support\Application;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class OpenSupportTicket
{
    public function __construct(private readonly SupportAccess $access) {}

    public function execute(
        User $user,
        string $category,
        string $subject,
        string $body,
        string $priority = 'normal',
        ?Model $related = null,
    ): SupportTicket {
        return $this->access->create($user, $category, $subject, $body, $priority, $related);
    }
}
