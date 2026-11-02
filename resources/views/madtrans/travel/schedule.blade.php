@extends('layouts.app')

@section('title', 'Jadwal '.$schedule->departure_date->toDateString())

@section('content')
    <div class="card">
        <h1>{{ $schedule->route->origin_city }} → {{ $schedule->route->destination_city }}</h1>
        <p class="muted">
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

    <div class="card">
        <h2>Pesanan</h2>

        @forelse ($bookings as $booking)
            <div style="border-top:1px solid #e6edee;padding:14px 0">
                <div class="row">
                    <div>
                        <strong>{{ $booking->code }}</strong><br>
                        <span class="muted">{{ $booking->status }} · Rp {{ number_format((float) $booking->total_price, 0, ',', '.') }}</span><br>
                        <span class="muted">
                            {{ $booking->originStop?->name ?? '—' }} → {{ $booking->destinationStop?->name ?? '—' }}
                        </span>
                    </div>
                    <div>
                        <span class="muted">{{ $booking->created_at?->toDateTimeString() }}</span>
                    </div>
                </div>
                <ul>
                    @foreach ($booking->passengers as $passenger)
                        <li>
                            {{ $passenger->name }} — kursi {{ $passenger->seat_snapshot ?? '—' }}
                            <span class="muted">
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
                        <button type="submit">Pindahkan pesanan</button>
                    </form>
                @endif
            </div>
        @empty
            <p class="muted">Belum ada pesanan untuk jadwal ini.</p>
        @endforelse
    </div>
@endsection
