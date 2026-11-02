<?php

namespace App\Http\Controllers\Web\MadTrans\Customer;

use App\Core\Rental\Application\VehicleAvailability;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class RentalCalendarController extends Controller
{
    public function show(
        Request $request,
        Business $business,
        Vehicle $vehicle,
        VehicleAvailability $availability,
    ): View {
        abort_unless($business->type === 'agency', 404);
        abort_unless((int) $vehicle->business_id === (int) $business->getKey(), 404);

        $month = $this->resolveMonth($request->query('month'));
        $data = $availability->month($vehicle, $month);

        return view('madtrans.rental.calendar', [
            'business' => $business,
            'vehicle' => $vehicle,
            'month' => $data['from'],
            'prevMonth' => $data['from']->copy()->subMonthNoOverflow(),
            'nextMonth' => $data['from']->copy()->addMonthNoOverflow(),
            'takenDays' => $data['days'],
        ]);
    }

    private function resolveMonth(mixed $value): Carbon
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
            $parsed = Carbon::createFromFormat('!Y-m', $value);

            if ($parsed !== false) {
                return $parsed->startOfMonth();
            }
        }

        return Carbon::now()->startOfMonth();
    }
}
