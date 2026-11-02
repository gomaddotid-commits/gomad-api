<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Support\Application\CloseSupportTicket;
use App\Core\Support\Application\OpenSupportTicket;
use App\Core\Support\Application\ReplyToSupportTicket;
use App\Core\Support\Application\SupportAccess;
use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\TravelBooking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Dukungan lewat API: paritas layar `/dukungan` dan antrean `/admin/dukungan`.
 * Pengguna hanya melihat tiketnya sendiri; staf (`ops.view`) melihat semua.
 */
class SupportController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->string('status')->toString();

        $page = SupportTicket::query()
            ->where('user_id', $request->user()->getKey())
            ->when(
                in_array($status, SupportTicket::STATUSES, true),
                fn ($query) => $query->where('status', $status),
            )
            ->withCount('messages')
            ->latest('last_message_at')
            ->paginate(15);

        return response()->json([
            'data' => $page->getCollection()->map(fn (SupportTicket $ticket): array => $this->summary($ticket))->values(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function store(Request $request, OpenSupportTicket $open): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(SupportTicket::CATEGORIES)],
            'subject' => ['required', 'string', 'min:5', 'max:160'],
            'body' => ['required', 'string', 'min:10', 'max:4000'],
            'priority' => ['required', Rule::in(SupportTicket::PRIORITIES)],
            'booking_code' => ['nullable', 'string', 'max:32'],
        ]);

        $related = null;

        if (! empty($data['booking_code'])) {
            $related = TravelBooking::query()
                ->where('code', $data['booking_code'])
                ->where('customer_id', $request->user()->getKey())
                ->first();

            if ($related === null) {
                throw ValidationException::withMessages(['booking_code' => 'Pesanan tidak ditemukan atas nama Anda.']);
            }
        }

        $ticket = $this->run(fn () => $open->execute(
            $request->user(),
            $data['category'],
            $data['subject'],
            $data['body'],
            $data['priority'],
            $related,
        ));

        return response()->json(['data' => $this->detail($ticket->load('messages.user:id,name'), false)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    public function show(Request $request, SupportTicket $ticket, SupportAccess $access): JsonResponse
    {
        if (! $access->allows($request->user(), $ticket)) {
            abort(403, 'Anda tidak memiliki akses ke tiket ini.');
        }

        return response()->json([
            'data' => $this->detail($ticket->load('messages.user:id,name'), $access->isStaff($request->user())),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function reply(
        Request $request,
        SupportTicket $ticket,
        ReplyToSupportTicket $reply,
        SupportAccess $access,
    ): JsonResponse {
        if (! $access->allows($request->user(), $ticket)) {
            abort(403, 'Anda tidak memiliki akses ke tiket ini.');
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:4000'],
            'internal' => ['nullable', 'boolean'],
        ]);

        $message = $this->run(fn () => $reply->execute(
            $request->user(),
            $ticket,
            $data['body'],
            $access->isStaff($request->user()) && (bool) ($data['internal'] ?? false),
        ));

        return response()->json([
            'data' => [
                'id' => $message->getKey(),
                'body' => $message->body,
                'is_internal' => $message->is_internal,
                'created_at' => $message->created_at?->toIso8601String(),
                'ticket_status' => $ticket->fresh()->status,
            ],
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    public function close(
        Request $request,
        SupportTicket $ticket,
        CloseSupportTicket $close,
        SupportAccess $access,
    ): JsonResponse {
        if (! $access->allows($request->user(), $ticket)) {
            abort(403, 'Anda tidak memiliki akses ke tiket ini.');
        }

        $ticket = $this->run(fn () => $close->execute($request->user(), $ticket));

        return response()->json([
            'data' => [
                'id' => $ticket->getKey(),
                'status' => $ticket->status,
                'closed_at' => $ticket->closed_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Antrean staf: semua tiket, terbuka lebih dulu.
     *
     * @return array<string, mixed>
     */
    public function adminIndex(Request $request, ActionAuthorizer $authorizer): JsonResponse
    {
        $authorizer->authorize($request->user(), 'ops.view');

        $status = $request->string('status')->toString();

        $page = SupportTicket::query()
            ->with('user:id,name,email')
            ->when(
                in_array($status, SupportTicket::STATUSES, true),
                fn ($query) => $query->where('status', $status),
            )
            ->withCount('messages')
            ->orderByRaw("case when status = 'open' then 0 when status = 'answered' then 1 else 2 end")
            ->latest('last_message_at')
            ->paginate(20);

        return response()->json([
            'data' => $page->getCollection()->map(fn (SupportTicket $ticket): array => array_merge(
                $this->summary($ticket),
                ['user' => [
                    'id' => $ticket->user?->getKey(),
                    'name' => $ticket->user?->name,
                    'email' => $ticket->user?->email,
                ]],
            ))->values(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(SupportTicket $ticket): array
    {
        return [
            'id' => $ticket->getKey(),
            'code' => $ticket->code,
            'subject' => $ticket->subject,
            'category' => $ticket->category,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'messages_count' => $ticket->messages_count ?? $ticket->messages()->count(),
            'last_message_at' => $ticket->last_message_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(SupportTicket $ticket, bool $withInternal): array
    {
        return array_merge($this->summary($ticket), [
            'closed_at' => $ticket->closed_at?->toIso8601String(),
            'related' => $ticket->related === null ? null : [
                'type' => class_basename((string) $ticket->related_type),
                'id' => $ticket->related_id,
                'code' => $ticket->related->code ?? null,
            ],
            'messages' => $ticket->messages
                ->filter(fn ($message): bool => $withInternal || ! $message->is_internal)
                ->map(fn ($message): array => [
                    'id' => $message->getKey(),
                    'author' => $message->user?->name,
                    'body' => $message->body,
                    'is_internal' => $message->is_internal,
                    'created_at' => $message->created_at?->toIso8601String(),
                ])
                ->values(),
        ]);
    }

    private function run(callable $action): mixed
    {
        try {
            return $action();
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['support' => $exception->getMessage()]);
        }
    }
}
