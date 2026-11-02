<?php

namespace App\Core\Support\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Aturan akses tiket dukungan: pemilik tiket, atau staf platform (`ops.view`).
 *
 * Dipakai oleh pembacaan maupun penulisan supaya daftar tiket dan isi tiket
 * tidak pernah punya aturan yang berbeda.
 */
final class SupportAccess
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    public function isStaff(User $user): bool
    {
        return $this->authorizer->allows($user, 'ops.view');
    }

    public function allows(User $user, SupportTicket $ticket): bool
    {
        return $this->isStaff($user) || (int) $ticket->user_id === (int) $user->getKey();
    }

    public function authorize(User $user, SupportTicket $ticket): void
    {
        if (! $this->allows($user, $ticket)) {
            throw new AuthorizationException('Anda tidak memiliki akses ke tiket ini.');
        }
    }

    /**
     * @param  list<string>  $categories
     */
    public function assertCategory(string $category, array $categories): void
    {
        if (! in_array($category, $categories, true)) {
            throw new InvalidArgumentException('Kategori tiket tidak dikenal.');
        }
    }

    public static function related(Model|int|null $related): array
    {
        if ($related === null) {
            return ['related_type' => null, 'related_id' => null];
        }

        if ($related instanceof Model) {
            return [
                'related_type' => $related->getMorphClass(),
                'related_id' => $related->getKey(),
            ];
        }

        return ['related_type' => null, 'related_id' => null];
    }

    public static function code(): string
    {
        return 'SP-'.now()->format('ymd').'-'.strtoupper(Str::random(6));
    }

    /**
     * Membuat tiket beserta pesan pertamanya dalam satu transaksi.
     */
    public function create(User $user, string $category, string $subject, string $body, string $priority, ?Model $related): SupportTicket
    {
        $this->assertCategory($category, SupportTicket::CATEGORIES);

        if (! in_array($priority, SupportTicket::PRIORITIES, true)) {
            throw new InvalidArgumentException('Prioritas tiket tidak dikenal.');
        }

        return DB::transaction(function () use ($user, $category, $subject, $body, $priority, $related): SupportTicket {
            $ticket = SupportTicket::query()->create(array_merge([
                'code' => self::code(),
                'user_id' => $user->getKey(),
                'category' => $category,
                'subject' => trim($subject),
                'status' => 'open',
                'priority' => $priority,
                'last_message_at' => now(),
            ], self::related($related)));

            $ticket->messages()->create([
                'user_id' => $user->getKey(),
                'body' => trim($body),
                'is_internal' => false,
            ]);

            return $ticket->refresh();
        });
    }
}
