<?php

namespace App\Core\Support\Application;

use App\Models\SupportTicket;
use App\Models\User;

final class CloseSupportTicket
{
    public function __construct(private readonly SupportAccess $access) {}

    public function execute(User $actor, SupportTicket $ticket): SupportTicket
    {
        $this->access->authorize($actor, $ticket);

        if ($ticket->isClosed()) {
            return $ticket;
        }

        $ticket->forceFill([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $actor->getKey(),
        ])->save();

        return $ticket->refresh();
    }
}
