<?php

namespace App\Http\Controllers\Web\MadTrans\Driver;

use App\Core\MadTrans\Application\ConfirmCodPassengerBoarding;
use App\Core\MadTrans\Application\FinishTravelSchedule;
use App\Core\MadTrans\Application\StartTravelSchedule;
use App\Core\MadTrans\Application\VoidCodPassengerHold;
use App\Core\MadTrans\Domain\CodCollateralInsufficient;
use App\Core\MadTrans\Domain\TravelCheckoutNotConfigured;
use App\Core\Payment\Domain\PaymentConflict;
use App\Http\Controllers\Controller;
use App\Models\BookingPassenger;
use App\Models\BusinessMember;
use App\Models\TravelBooking;
use App\Models\TravelSchedule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class DriverTravelController extends Controller
{
    public function index(Request $request): View
    {
        $membershipIds = BusinessMember::query()
            ->where('user_id', $request->user()->getKey())
            ->where('role', 'driver')
            ->where('status', 'active')
            ->pluck('id');

        $schedules = TravelSchedule::query()
            ->whereIn('driver_id', $membershipIds)
            ->whereIn('status', ['published', 'in_progress'])
            ->with([
                'business:id,name',
                'route:id,origin_city,destination_city',
                'vehicle:id,name,plate_number',
            ])
            ->withCount('bookings')
            ->orderBy('departure_date')
            ->orderBy('departure_time')
            ->limit(50)
            ->get();

        /*
        | Tugas yang sudah selesai tetap ditampilkan, terpisah.
        |
        | DS v1.0 §7.4 meminta "clear distinction between active and completed
        | trips": tanpa daftar selesai, supir tidak punya cara memastikan perjalanan
        | mana yang sudah ia tutup — dan itu pertanyaan pertama saat ada sengketa
        | penumpang. Dibatasi 7 hari terakhir supaya daftar tidak tumbuh selamanya.
        */
        $completedSchedules = TravelSchedule::query()
            ->whereIn('driver_id', $membershipIds)
            ->where('status', 'completed')
            ->whereDate('departure_date', '>=', today()->subDays(7))
            ->with([
                'business:id,name',
                'route:id,origin_city,destination_city',
                'vehicle:id,name,plate_number',
            ])
            ->withCount('bookings')
            ->orderByDesc('departure_date')
            ->orderByDesc('departure_time')
            ->limit(10)
            ->get();

        return view('madtrans.driver.index', [
            'schedules' => $schedules,
            'completedSchedules' => $completedSchedules,
        ]);
    }

    public function show(Request $request, TravelSchedule $schedule): View
    {
        $this->authorizeAssignedDriver($request, $schedule);

        $schedule->load([
            'business:id,name',
            'route:id,origin_city,destination_city',
            'vehicle:id,name,plate_number,capacity',
        ]);

        $bookings = TravelBooking::query()
            ->with('passengers')
            ->where('schedule_id', $schedule->getKey())
            ->orderBy('id')
            ->get();

        return view('madtrans.driver.show', [
            'schedule' => $schedule,
            'bookings' => $bookings,
        ]);
    }

    public function board(
        Request $request,
        TravelBooking $booking,
        BookingPassenger $passenger,
        ConfirmCodPassengerBoarding $confirm,
    ): RedirectResponse {
        $this->runAction(fn () => $confirm->execute($request->user(), $booking, $passenger));

        return back()->with('status', 'Boarding '.$passenger->name.' dikonfirmasi.');
    }

    public function noShow(
        Request $request,
        TravelBooking $booking,
        BookingPassenger $passenger,
        VoidCodPassengerHold $voidHold,
    ): RedirectResponse {
        $this->runAction(fn () => $voidHold->execute($request->user(), $booking, $passenger));

        return back()->with('status', $passenger->name.' ditandai tidak naik; jaminan dilepas.');
    }

    public function start(
        Request $request,
        TravelSchedule $schedule,
        StartTravelSchedule $start,
    ): RedirectResponse {
        $this->runAction(fn () => $start->execute($request->user(), $schedule));

        return back()->with('status', 'Perjalanan dimulai.');
    }

    public function finish(
        Request $request,
        TravelSchedule $schedule,
        FinishTravelSchedule $finish,
    ): RedirectResponse {
        $this->runAction(fn () => $finish->execute($request->user(), $schedule));

        return back()->with('status', 'Perjalanan diselesaikan.');
    }

    private function authorizeAssignedDriver(Request $request, TravelSchedule $schedule): void
    {
        abort_unless($schedule->driver_id !== null, 404);

        $isAssigned = BusinessMember::query()
            ->whereKey($schedule->driver_id)
            ->where('user_id', $request->user()->getKey())
            ->where('role', 'driver')
            ->where('status', 'active')
            ->exists();

        abort_unless($isAssigned, 403);
    }

    private function runAction(callable $action): mixed
    {
        try {
            return $action();
        } catch (InvalidArgumentException|CodCollateralInsufficient|TravelCheckoutNotConfigured|PaymentConflict $exception) {
            throw ValidationException::withMessages(['driver' => $exception->getMessage()]);
        } catch (AuthorizationException $exception) {
            throw $exception;
        }
    }
}
