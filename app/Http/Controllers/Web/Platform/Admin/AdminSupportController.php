<?php

namespace App\Http\Controllers\Web\Platform\Admin;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Support\Application\CloseSupportTicket;
use App\Core\Support\Application\ReplyToSupportTicket;
use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Antrean dukungan untuk staf platform. Staf melihat semua tiket, termasuk
 * catatan internal yang tidak pernah tampil ke pengguna.
 */
class AdminSupportController extends Controller
{
    public function index(Request $request, ActionAuthorizer $authorizer): View
    {
        $authorizer->authorize($request->user(), 'ops.view');

        $status = $request->string('status')->toString();
        $search = $request->string('q')->toString();
        $allowed = SupportTicket::STATUSES;

        return view('platform.admin.support.index', [
            'tickets' => SupportTicket::query()
                ->with('user:id,name,email')
                ->when(in_array($status, $allowed, true), fn ($query) => $query->where('status', $status))
                ->when($search !== '', fn ($query) => $query->where(
                    fn ($inner) => $inner
                        ->where('code', 'like', '%'.$search.'%')
                        ->orWhere('subject', 'like', '%'.$search.'%')
                        ->orWhereHas('user', fn ($user) => $user
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%')),
                ))
                ->withCount('messages')
                ->orderByRaw("case when status = 'open' then 0 when status = 'answered' then 1 else 2 end")
                ->latest('last_message_at')
                ->paginate(20)
                ->withQueryString(),
            'status' => in_array($status, $allowed, true) ? $status : null,
            'search' => $search,
            'counts' => SupportTicket::query()
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all(),
        ]);
    }

    public function show(Request $request, SupportTicket $ticket, ActionAuthorizer $authorizer): View
    {
        $authorizer->authorize($request->user(), 'ops.view');

        return view('platform.admin.support.show', [
            'ticket' => $ticket->load(['messages.user:id,name', 'user:id,name,email', 'related', 'closer:id,name']),
            'isStaff' => true,
        ]);
    }

    public function reply(
        Request $request,
        SupportTicket $ticket,
        ReplyToSupportTicket $reply,
    ): RedirectResponse {
        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:4000'],
            'internal' => ['nullable', 'boolean'],
        ]);

        $this->run(fn () => $reply->execute(
            $request->user(),
            $ticket,
            $data['body'],
            (bool) ($data['internal'] ?? false),
        ));

        return back()->with('status', 'Balasan tersimpan.');
    }

    public function close(
        Request $request,
        SupportTicket $ticket,
        CloseSupportTicket $close,
    ): RedirectResponse {
        $this->run(fn () => $close->execute($request->user(), $ticket));

        return back()->with('status', 'Tiket '.$ticket->code.' ditutup.');
    }

    private function run(callable $action): void
    {
        try {
            $action();
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['support' => $exception->getMessage()]);
        }
    }
}
