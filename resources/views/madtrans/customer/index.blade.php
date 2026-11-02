@extends('layouts.app')

@section('title', 'Cari jadwal Mad Trans')

@section('content')
    <x-gm.page-header
        eyebrow="Mad Trans"
        title="Cari jadwal"
        subtitle="Bandingkan jadwal dari semua agency, lalu pilih kursi. Rincian biaya layanan dan PPN tampil sebelum membayar.">
        <x-slot:actions>
            <a href="{{ route('mad-trans.bookings.index') }}">
                <x-gm.button type="button" variant="secondary">Pesanan saya</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    <x-gm.card :padded="false">
        <form method="get" action="{{ route('mad-trans.index') }}" class="grid gap-3 px-5 py-4 md:grid-cols-12">
            <label class="md:col-span-3">
                <span class="text-sm font-semibold text-black-900">Dari</span>
                <input id="origin_city" type="text" name="origin_city" value="{{ $filters['origin_city'] ?? '' }}" required
                       placeholder="Kota asal">
            </label>

            <label class="md:col-span-3">
                <span class="text-sm font-semibold text-black-900">Ke</span>
                <input id="destination_city" type="text" name="destination_city" value="{{ $filters['destination_city'] ?? '' }}" required
                       placeholder="Kota tujuan">
            </label>

            <label class="md:col-span-2">
                <span class="text-sm font-semibold text-black-900">Tanggal berangkat</span>
                <input id="departure_date" type="date" name="departure_date"
                       value="{{ $filters['departure_date'] ?? today()->addDay()->toDateString() }}"
                       min="{{ today()->toDateString() }}" required>
            </label>

            <label class="md:col-span-2">
                <span class="text-sm font-semibold text-black-900">Jumlah penumpang</span>
                <input id="passengers" type="number" min="1" max="10" name="passengers"
                       value="{{ $filters['passengers'] ?? 1 }}">
            </label>

            <div class="md:col-span-2 md:self-end">
                <x-gm.button type="submit" variant="brand" class="w-full">Cari jadwal</x-gm.button>
            </div>
        </form>
    </x-gm.card>

    @if ($error)
        <x-gm.alert tone="error" class="mt-4">{{ $error }}</x-gm.alert>
    @elseif ($hasQuery)
        @if ($schedules->isEmpty())
            <x-gm.empty class="mt-4"
                title="Tidak ada jadwal untuk rute dan tanggal itu"
                description="Coba tanggal atau rute lain. Jadwal muncul setelah agency menerbitkannya.">
                <x-slot:action>
                    <a href="{{ route('public.partners') }}">
                        <x-gm.button type="button" variant="secondary">Lihat mitra tersedia</x-gm.button>
                    </a>
                </x-slot:action>
            </x-gm.empty>
        @else
            <p class="mt-6 mb-3 text-sm text-gray-500">
                {{ $schedules->count() }} jadwal tersedia untuk
                <span class="font-semibold text-gomad-green-deep">{{ $filters['origin_city'] }} → {{ $filters['destination_city'] }}</span>.
            </p>

            {{-- Kartu perjalanan — susunan sama dengan hasil pencarian publik. --}}
            <div class="space-y-3">
                @foreach ($schedules as $schedule)
                    <div class="gm-glass rounded-xl px-5 py-4 transition-colors duration-150 hover:border-gomad-lime">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-base font-semibold text-gomad-green-deep">{{ $schedule->business->name }}</p>
                                <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-gray-500">
                                    <span class="text-lg font-bold text-black-900">
                                        {{ substr((string) $schedule->departure_time, 0, 5) }}
                                    </span>
                                    <span aria-hidden="true">·</span>
                                    <span>{{ $schedule->route->origin_city }} → {{ $schedule->route->destination_city }}</span>
                                    <span aria-hidden="true">·</span>
                                    <span>{{ $schedule->vehicle->name }}</span>
                                </p>
                                <p class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                    <x-gm.badge tone="neutral">{{ ucfirst((string) $schedule->travel_class) }}</x-gm.badge>
                                    @if ($schedule->allow_cod)
                                        <x-gm.badge tone="brand">COD tersedia</x-gm.badge>
                                    @endif
                                    <span class="text-gray-500">
                                        sisa
                                        {{ $schedule->segment_available
                                            ?? max(0, (int) $schedule->vehicle->capacity - (int) ($schedule->reserved_seats ?? 0)) }}
                                        kursi
                                    </span>
                                </p>
                            </div>

                            <div class="text-right">
                                <p class="text-2xl font-bold tracking-tight text-gomad-green-deep">
                                    <x-gm.money :amount="$schedule->price_per_seat" />
                                </p>
                                <p class="text-xs text-gray-500">per kursi</p>

                                <a href="{{ route('mad-trans.schedules.show', $schedule) }}" class="mt-3 inline-block">
                                    <x-gm.button type="button" size="sm" variant="brand" class="rounded-pill">Pilih kursi</x-gm.button>
                                </a>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @else
        <x-gm.empty class="mt-4"
            title="Mulai dengan mengisi rute"
            description="Masukkan kota asal, kota tujuan, dan tanggal keberangkatan untuk melihat jadwal yang tersedia." />
    @endif
@endsection
