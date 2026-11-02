<?php

namespace App\Http\Controllers\Web\Shared;

use App\Core\Support\Application\CloseSupportTicket;
use App\Core\Support\Application\OpenSupportTicket;
use App\Core\Support\Application\ReplyToSupportTicket;
use App\Core\Support\Application\SupportAccess;
use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\TravelBooking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Dukungan pelanggan dari sisi pengguna: buka tiket, balas, tutup.
 *
 * Satu tiket = satu percakapan. Pesan internal staf tidak pernah tampil di sini.
 */
class SupportController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->string('status')->toString();
        $allowed = SupportTicket::STATUSES;

        return view('shared.support.index', [
            'tickets' => SupportTicket::query()
                ->where('user_id', $request->user()->getKey())
                ->when(in_array($status, $allowed, true), fn ($query) => $query->where('status', $status))
                ->withCount('messages')
                ->latest('last_message_at')
                ->paginate(15)
                ->withQueryString(),
            'status' => in_array($status, $allowed, true) ? $status : null,
        ]);
    }

    public function create(Request $request): View
    {
        $booking = null;

        if ($request->filled('booking')) {
            $booking = TravelBooking::query()
                ->where('code', $request->string('booking')->toString())
                ->where('customer_id', $request->user()->getKey())
                ->first();
        }

        return view('shared.support.create', [
            'booking' => $booking,
            'categories' => SupportTicket::CATEGORIES,
        ]);
    }

    public function store(
        Request $request,
        OpenSupportTicket $open,
        SupportAccess $access,
    ): RedirectResponse {
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

        return redirect()
            ->route('support.show', $ticket)
            ->with('status', 'Tiket '.$ticket->code.' dibuat. Tim kami akan membalas di sini.');
    }

    public function show(Request $request, SupportTicket $ticket, SupportAccess $access): View
    {
        abort_unless($access->allows($request->user(), $ticket), 403);

        return view('shared.support.show', [
            'ticket' => $ticket->load(['messages.user:id,name', 'related']),
            'isStaff' => false,
        ]);
    }

    public function reply(
        Request $request,
        SupportTicket $ticket,
        ReplyToSupportTicket $reply,
    ): RedirectResponse {
        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:4000'],
        ]);

        $this->run(fn () => $reply->execute($request->user(), $ticket, $data['body']));

        return back()->with('status', 'Balasan terkirim.');
    }

    public function close(
        Request $request,
        SupportTicket $ticket,
        CloseSupportTicket $close,
    ): RedirectResponse {
        $this->run(fn () => $close->execute($request->user(), $ticket));

        return back()->with('status', 'Tiket ditutup.');
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
