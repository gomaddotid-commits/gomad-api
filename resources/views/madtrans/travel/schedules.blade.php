@extends('layouts.app')

@section('title', 'Jadwal '.$business->name)

@section('content')
    <x-gm.page-header
        eyebrow="Ruang kerja agency"
        title="Jadwal"
        :subtitle="$business->name.' · terbitkan, setujui, mulai, dan selesaikan perjalanan'">
        <x-slot:actions>
            <a href="{{ route('mad-trans.agency.routes', $business) }}">
                <x-gm.button type="button" variant="secondary">Kelola rute</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @include('madtrans.travel._nav', ['business' => $business, 'active' => 'schedules'])

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-3">
            @forelse ($schedules as $schedule)
                @php
                    $tone = match ($schedule->status) {
                        'published' => 'success',
                        'boarding', 'departed' => 'info',
                        'draft' => 'warning',
                        'cancelled' => 'error',
                        default => 'neutral',
                    };
                    $departureAt = \Illuminate\Support\Carbon::parse(
                        $schedule->departure_date->format('Y-m-d').' '.$schedule->departure_time,
                    );
                @endphp

                <x-gm.card :padded="false">
                    <div class="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
                        <div class="min-w-0">
                            <p class="text-base font-semibold text-black-900">
                                {{ $schedule->route->origin_city }} → {{ $schedule->route->destination_city }}
                            </p>
                            <p class="mt-1 text-sm text-gray-600">
                                {{ $schedule->departure_date->toDateString() }}
                                {{ substr((string) $schedule->departure_time, 0, 5) }}
                                · {{ $schedule->vehicle->name }} ({{ $schedule->vehicle->plate_number }})
                                · {{ $schedule->driverMembership?->user?->name ?? 'tanpa supir' }}
                            </p>
                            <p class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                <x-gm.badge :tone="$tone">{{ ucfirst((string) $schedule->status) }}</x-gm.badge>
                                <span class="text-gray-500">{{ $schedule->bookings_count }} pesanan</span>
                                <span class="text-gray-500">{{ $schedule->travel_class }}</span>
                            </p>
                        </div>

                        <div class="flex flex-col items-end gap-2">
                            <a href="{{ route('mad-trans.agency.schedules.show', [$business, $schedule]) }}">
                                <x-gm.button type="button" size="sm" variant="secondary">Detail</x-gm.button>
                            </a>
                        </div>
                    </div>

                    @if ($schedule->status === 'draft' || $schedule->status === 'published' || $schedule->status === 'boarding')
                        <div class="flex flex-wrap gap-2 border-t border-gray-200 px-5 py-3">
                            @if ($schedule->status === 'draft')
                                <form method="post" action="{{ route('mad-trans.agency.schedules.approve', $schedule) }}">
                                    @csrf
                                    <x-gm.button type="submit" size="sm" variant="brand">Setujui &amp; terbitkan</x-gm.button>
                                </form>
                            @endif

                            @if ($schedule->status === 'published')
                                @if (! $schedule->allow_cod)
                                    <form method="post" action="{{ route('mad-trans.agency.schedules.enable-cod', $schedule) }}">
                                        @csrf
                                        <x-gm.button type="submit" size="sm" variant="secondary">Aktifkan COD</x-gm.button>
                                    </form>
                                @endif

                                <form method="post" action="{{ route('mad-trans.agency.schedules.start', $schedule) }}">
                                    @csrf
                                    <x-gm.button type="submit" size="sm">Mulai perjalanan</x-gm.button>
                                </form>
                            @endif

                            @if ($schedule->status === 'boarding')
                                <form method="post" action="{{ route('mad-trans.agency.schedules.finish', $schedule) }}">
                                    @csrf
                                    <x-gm.button type="submit" size="sm">Selesaikan</x-gm.button>
                                </form>
                            @endif
                        </div>
                    @endif
                </x-gm.card>
            @empty
                <x-gm.empty title="Belum ada jadwal"
                    description="Jadwal dibuat dari rute yang sudah punya tarif segmen penuh." />
            @endforelse

            <div class="mt-4">{{ $schedules->links() }}</div>
        </div>

        <aside class="space-y-5">
            <x-gm.card title="Buat jadwal">
                @if ($routes->isEmpty() || $vehicles->isEmpty())
                    <p class="text-sm text-gray-600">
                        Butuh minimal satu rute dan satu kendaraan. Lengkapi di tab
                        <a href="{{ route('mad-trans.agency.routes', $business) }}" class="font-semibold text-black-900 hover:underline">Rute</a> dan
                        <a href="{{ route('mad-trans.agency.fleet', $business) }}" class="font-semibold text-black-900 hover:underline">Armada</a>.
                    </p>
                @else
                    <form method="post" action="{{ route('mad-trans.agency.schedules.store', $business) }}" class="space-y-3">
                        @csrf

                        <label>
                            <span class="text-sm font-semibold text-black-900">Rute</span>
                            <select name="route_id" required>
                                @foreach ($routes as $route)
                                    <option value="{{ $route->id }}">
                                        {{ $route->origin_city }} → {{ $route->destination_city }}
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <label>
                            <span class="text-sm font-semibold text-black-900">Kendaraan</span>
                            <select name="vehicle_id" required>
                                @foreach ($vehicles as $vehicle)
                                    <option value="{{ $vehicle->id }}">
                                        {{ $vehicle->name }} · {{ $vehicle->plate_number }} · {{ $vehicle->capacity }} kursi
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <label>
                            <span class="text-sm font-semibold text-black-900">Supir</span>
                            <select name="driver_membership_id">
                                <option value="">Tanpa supir (dipilih nanti)</option>
                                @foreach ($drivers as $driver)
                                    <option value="{{ $driver->id }}">{{ $driver->name }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label>
                            <span class="text-sm font-semibold text-black-900">Waktu berangkat</span>
                            <input type="datetime-local" name="departure_at" required
                                   value="{{ now()->addDay()->setTime(8, 0)->format('Y-m-d\TH:i') }}">
                        </label>

                        <label>
                            <span class="text-sm font-semibold text-black-900">Kelas</span>
                            <select name="travel_class" required>
                                <option value="regular">Regular</option>
                                <option value="ekonomi">Ekonomi</option>
                                <option value="eksekutif">Eksekutif</option>
                            </select>
                        </label>

                        <x-gm.button type="submit" variant="brand" class="w-full">Buat jadwal</x-gm.button>

                        <p class="text-xs text-gray-500">
                            Jadwal dibuat sebagai draf; terbitkan setelah yakin harga dan armadanya benar.
                        </p>
                    </form>
                @endif
            </x-gm.card>
        </aside>
    </div>
@endsection
