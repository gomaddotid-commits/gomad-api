<?php

namespace App\Core\Support\Application;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReplyToSupportTicket
{
    public function __construct(private readonly SupportAccess $access) {}

    public function execute(User $actor, SupportTicket $ticket, string $body, bool $internal = false): SupportMessage
    {
        $this->access->authorize($actor, $ticket);

        $isStaff = $this->access->isStaff($actor);

        if ($internal && ! $isStaff) {
            throw new InvalidArgumentException('Catatan internal hanya untuk staf platform.');
        }

        if ($ticket->isClosed() && ! $isStaff) {
            throw new InvalidArgumentException('Tiket sudah ditutup. Buat tiket baru bila masalahnya berlanjut.');
        }

        $text = trim($body);
        if (mb_strlen($text) < 2) {
            throw new InvalidArgumentException('Isi pesan terlalu pendek.');
        }

        return DB::transaction(function () use ($actor, $ticket, $text, $internal, $isStaff): SupportMessage {
            $message = $ticket->messages()->create([
                'user_id' => $actor->getKey(),
                'body' => $text,
                'is_internal' => $internal,
            ]);

            $attributes = [];

            if (! $internal) {
                $attributes['last_message_at'] = now();
                $attributes['status'] = $isStaff ? 'answered' : 'open';
            }

            if ($isStaff && $ticket->isClosed() && ! $internal) {
                // Staf boleh membuka kembali tiket yang sudah ditutup lewat balasan.
                $attributes['closed_at'] = null;
                $attributes['closed_by'] = null;
                $attributes['status'] = 'answered';
            }

            if ($attributes !== []) {
                $ticket->forceFill($attributes)->save();
            }

            return $message;
        });
    }
}
