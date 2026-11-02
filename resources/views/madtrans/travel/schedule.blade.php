@extends('layouts.app')

@section('title', 'Jadwal '.$schedule->departure_date->toDateString())

@section('content')
    <div class="gm-surface p-5 mb-4">
        <h1 class="gm-h2 text-black-900 mb-1">{{ $schedule->route->origin_city }} → {{ $schedule->route->destination_city }}</h1>
        <p class="text-sm text-gray-500">
            {{ $schedule->departure_date->toDateString() }}
            {{ substr((string) $schedule->departure_time, 0, 5) }} ·
            {{ $schedule->vehicle->name }} ({{ $schedule->vehicle->plate_number }}) ·
            {{ $schedule->driverMembership?->user?->name ?? 'tanpa supir' }}
        </p>
        <div class="row"><span>Status</span><strong>{{ $schedule->status }}</strong></div>
        <div class="row">
            <span>Kursi terisi</span>
            <strong>{{ $bookings->sum(fn ($booking) => $booking->passengers->count()) }} / {{ $schedule->vehicle->capacity }}</strong>
        </div>
        <p><a href="{{ route('mad-trans.agency.show', $business) }}">← Kembali ke portal agency</a></p>
    </div>

    <div class="gm-surface p-5 mb-4">
        <h2 class="gm-h4 text-black-900 mb-2">Pesanan</h2>

        @forelse ($bookings as $booking)
            <div class="gm-list-block">
                <div class="row">
                    <div>
                        <strong>{{ $booking->code }}</strong><br>
                        <span class="text-sm text-gray-500">{{ $booking->status }} · Rp {{ number_format((float) $booking->total_price, 0, ',', '.') }}</span><br>
                        <span class="text-sm text-gray-500">
                            {{ $booking->originStop?->name ?? '—' }} → {{ $booking->destinationStop?->name ?? '—' }}
                        </span>
                    </div>
                    <div>
                        <span class="text-sm text-gray-500">{{ $booking->created_at?->toDateTimeString() }}</span>
                    </div>
                </div>
                <ul>
                    @foreach ($booking->passengers as $passenger)
                        <li>
                            {{ $passenger->name }} — kursi {{ $passenger->seat_snapshot ?? '—' }}
                            <span class="text-sm text-gray-500">
                                ({{ $passenger->boarded_at ? 'sudah naik' : ($passenger->no_show_at ? 'tidak naik' : 'belum naik') }})
                            </span>
                        </li>
                    @endforeach
                </ul>

                @if ($transferTargets->isNotEmpty() && in_array($booking->status, ['confirmed', 'paid'], true))
                    <form method="post" action="{{ route('mad-trans.agency.bookings.transfer', [$business, $booking]) }}"
                          style="border-top:1px dashed #e6edee;padding-top:8px">
                        @csrf
                        <label for="to_schedule_{{ $booking->getKey() }}">Pindahkan ke jadwal</label>
                        <select id="to_schedule_{{ $booking->getKey() }}" name="to_schedule_id" required>
                            @foreach ($transferTargets as $target)
                                <option value="{{ $target->getKey() }}">
                                    {{ $target->departure_date->toDateString() }}
                                    {{ substr((string) $target->departure_time, 0, 5) }}
                                </option>
                            @endforeach
                        </select>
                        <x-gm.button type="submit">Pindahkan pesanan</x-gm.button>
                    </form>
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-500">Belum ada pesanan untuk jadwal ini.</p>
        @endforelse
    </div>
@endsection
