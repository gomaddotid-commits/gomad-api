@extends('layouts.app')

@section('title', 'Tugas '.$schedule->departure_date->toDateString())

@section('content')
    @php
        $departureAt = \Illuminate\Support\Carbon::parse(
            $schedule->departure_date->toDateString().' '.$schedule->departure_time,
        );
        $seats = $bookings->sum(fn ($booking) => $booking->passengers->count());
        $boarded = $bookings->sum(fn ($booking) => $booking->passengers->whereNotNull('boarded_at')->count());

        $tone = match ($schedule->status) {
            'in_progress' => 'info',
            'published' => 'success',
            'completed' => 'neutral',
            default => 'neutral',
        };
        $statusLabel = match ($schedule->status) {
            'in_progress' => 'Sedang berjalan',
            'published' => 'Siap berangkat',
            'completed' => 'Selesai',
            default => ucfirst((string) $schedule->status),
        };
    @endphp

    {{--
        Layar tugas supir (DS v1.0 §7.4).

        Aturan keselamatan yang dipegang: **satu tindakan utama per keadaan**.
        Saat perjalanan belum mulai, satu-satunya tombol besar adalah "Mulai".
        Saat berjalan, tombol besar itu berganti menjadi "Selesaikan", dan aksi
        penumpang muncul di baris masing-masing — supaya supir tidak perlu memilih
        dari deretan tombol yang serupa saat sedang mengemudi.
    --}}
    <div class="mx-auto w-full max-w-2xl">
        <div class="rounded-lg border border-gray-200 bg-white px-5 py-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-500">{{ $schedule->business->name }}</p>
                    <h1 class="mt-1 text-2xl font-bold tracking-tight text-black-900">
                        {{ $schedule->route->origin_city }} → {{ $schedule->route->destination_city }}
                    </h1>
                </div>

                <x-gm.badge :tone="$tone">{{ $statusLabel }}</x-gm.badge>
            </div>

            <dl class="mt-5 grid grid-cols-2 gap-4 border-t border-gray-200 pt-4">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Berangkat</dt>
                    <dd class="mt-1 text-3xl font-bold leading-none tracking-tight text-black-900">
                        {{ substr((string) $schedule->departure_time, 0, 5) }}
                    </dd>
                    <dd class="mt-1 text-sm text-gray-500">{{ $schedule->departure_date->format('d M Y') }}</dd>
                </div>

                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Penumpang</dt>
                    <dd class="mt-1 text-3xl font-bold leading-none tracking-tight text-black-900">
                        {{ $boarded }}<span class="text-base font-medium text-gray-500"> / {{ $seats }}</span>
                    </dd>
                    <dd class="mt-1 text-sm text-gray-500">sudah naik</dd>
                </div>
            </dl>

            <p class="mt-4 text-sm text-gray-500">
                {{ $schedule->vehicle->name }} · {{ $schedule->vehicle->plate_number }}
            </p>
        </div>

        {{-- Satu tindakan utama, selebar layar, tinggi 56px. --}}
        <div class="mt-4">
            @if ($schedule->status === 'published')
                @if ($departureAt->isPast())
                    <form method="post" action="{{ route('mad-trans.driver.schedules.start', $schedule) }}">
                        @csrf
                        <x-gm.button type="submit" variant="brand" size="lg" class="min-h-[56px] w-full text-base">
                            Mulai perjalanan
                        </x-gm.button>
                    </form>
                @else
                    <p class="rounded-lg border border-gray-200 bg-white px-5 py-4 text-center text-sm text-gray-500">
                        Tombol mulai aktif {{ $departureAt->format('d/m H:i') }}.
                    </p>
                @endif
            @elseif ($schedule->status === 'in_progress')
                <form method="post" action="{{ route('mad-trans.driver.schedules.finish', $schedule) }}">
                    @csrf
                    <x-gm.button type="submit" variant="primary" size="lg" class="min-h-[56px] w-full text-base">
                        Selesaikan perjalanan
                    </x-gm.button>
                </form>
            @endif
        </div>

        <section class="mt-6">
            <h2 class="text-sm font-semibold text-black-900">Penumpang</h2>

            @if ($schedule->status !== 'in_progress')
                <p class="mt-1 text-sm text-gray-500">Tombol boarding aktif setelah perjalanan dimulai.</p>
            @endif

            @forelse ($bookings as $booking)
                <div class="mt-3 rounded-lg border border-gray-200 bg-white">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-5 py-3">
                        <p class="font-mono text-sm font-semibold text-black-900">{{ $booking->code }}</p>
                        <div class="flex flex-wrap gap-2">
                            <x-gm.badge tone="neutral">{{ $booking->payment_method === 'cod' ? 'COD' : 'Online' }}</x-gm.badge>
                        </div>
                    </div>

                    @foreach ($booking->passengers as $passenger)
                        <div class="px-5 py-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-base font-semibold text-black-900">{{ $passenger->name }}</p>
                                    <p class="mt-0.5 text-sm text-gray-500">
                                        Kursi {{ $passenger->seat_snapshot ?? '—' }}
                                        @if ($passenger->boarded_at)
                                            · sudah naik {{ $passenger->boarded_at->format('H:i') }}
                                        @elseif ($passenger->no_show_at)
                                            · tidak naik {{ $passenger->no_show_at->format('H:i') }}
                                        @else
                                            · belum naik
                                        @endif
                                    </p>
                                </div>

                                @if ($passenger->boarded_at)
                                    <x-gm.badge tone="success">Naik</x-gm.badge>
                                @elseif ($passenger->no_show_at)
                                    <x-gm.badge tone="error">Tidak naik</x-gm.badge>
                                @endif
                            </div>

                            @if ($schedule->status === 'in_progress'
                                && $booking->payment_method === 'cod'
                                && ! $passenger->boarded_at
                                && ! $passenger->no_show_at)
                                {{-- Dua tombol besar, bukan tautan kecil: dilakukan sambil berdiri di pintu. --}}
                                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                    <form method="post"
                                          action="{{ route('mad-trans.driver.passengers.board', [$booking, $passenger]) }}">
                                        @csrf
                                        <x-gm.button type="submit" variant="brand" class="min-h-[48px] w-full">
                                            Konfirmasi naik
                                        </x-gm.button>
                                    </form>

                                    <form method="post"
                                          action="{{ route('mad-trans.driver.passengers.no-show', [$booking, $passenger]) }}">
                                        @csrf
                                        <x-gm.button type="submit" variant="secondary" class="min-h-[48px] w-full">
                                            Tidak naik
                                        </x-gm.button>
                                    </form>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @empty
                <x-gm.empty class="mt-3"
                    title="Belum ada penumpang"
                    description="Pesanan yang masuk untuk jadwal ini akan muncul di sini." />
            @endforelse
        </section>

        <p class="mt-4 text-sm text-gray-500">
            Penumpang COD harus dikonfirmasi naik agar biaya layanan terambil dari jaminan agency.
            Tandai tidak naik bila penumpang tidak datang.
        </p>

        <a href="{{ route('mad-trans.driver.index') }}"
           class="mt-4 inline-flex min-h-[44px] items-center text-sm font-semibold text-green-700 hover:underline">
            ← Semua tugas
        </a>
    </div>
@endsection
