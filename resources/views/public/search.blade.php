@extends('layouts.app')

@section('title', 'Hasil pencarian jadwal')

@section('content')
    <x-gm.page-header
        eyebrow="Mad Trans"
        title="Cari jadwal"
        subtitle="Perbandingan jadwal dari semua agency yang aktif. Pilih satu untuk melihat kursi." />

    <x-gm.card :padded="false" class="mb-6">
        <form method="get" action="{{ route('public.search') }}" class="grid gap-3 px-5 py-4 md:grid-cols-12">
            <label class="md:col-span-3">
                <span class="text-sm font-semibold text-black-900">Dari</span>
                <input type="text" name="origin_city" value="{{ $filters['origin_city'] ?? '' }}" required
                       class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm">
            </label>

            <label class="md:col-span-3">
                <span class="text-sm font-semibold text-black-900">Ke</span>
                <input type="text" name="destination_city" value="{{ $filters['destination_city'] ?? '' }}" required
                       class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm">
            </label>

            <label class="md:col-span-2">
                <span class="text-sm font-semibold text-black-900">Tanggal</span>
                <input type="date" name="departure_date"
                       value="{{ $filters['departure_date'] ?? today()->addDay()->toDateString() }}"
                       min="{{ today()->toDateString() }}" required
                       class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm">
            </label>

            <label class="md:col-span-2">
                <span class="text-sm font-semibold text-black-900">Penumpang</span>
                <input type="number" name="passengers" min="1" max="10"
                       value="{{ $filters['passengers'] ?? 1 }}"
                       class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm">
            </label>

            <div class="md:col-span-2 md:self-end">
                <x-gm.button type="submit" class="w-full" variant="brand">Cari ulang</x-gm.button>
            </div>
        </form>
    </x-gm.card>

    @if ($error !== null)
        <x-gm.alert tone="error" class="mb-4">{{ $error }}</x-gm.alert>
    @endif

    @if (! $hasQuery)
        <x-gm.empty
            title="Isi rute dan tanggal dulu"
            description="Masukkan kota asal, kota tujuan, dan tanggal keberangkatan untuk melihat jadwal." />
    @elseif ($schedules->isEmpty())
        <x-gm.empty
            title="Tidak ada jadwal pada tanggal itu"
            description="Coba tanggal lain, atau lihat rute yang tersedia di halaman utama.">
            <x-slot:action>
                <a href="{{ route('home') }}">
                    <x-gm.button type="button" variant="secondary">Kembali ke beranda</x-gm.button>
                </a>
            </x-slot:action>
        </x-gm.empty>
    @else
        <p class="mb-3 text-sm text-gray-500">
            {{ $schedules->count() }} jadwal ditemukan untuk
            <span class="font-semibold text-gomad-green-deep">{{ $filters['origin_city'] }} → {{ $filters['destination_city'] }}</span>.
        </p>

        {{--
            Kartu perjalanan (DS v1.0 §9.4).

            Urutan baca dibuat tetap sama di setiap kartu — agen, jam, rute, sisa
            kursi, lalu harga — supaya mata bisa membandingkan beberapa jadwal
            tanpa membaca ulang. Harga adalah penanda terbesar karena itulah yang
            dicari orang saat membandingkan.
        --}}
        <div class="space-y-3">
            @foreach ($schedules as $schedule)
                <div class="gm-glass rounded-xl px-5 py-4 transition-colors duration-150 hover:border-gomad-lime">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-base font-semibold text-gomad-green-deep">
                                {{ $schedule->business?->name ?? 'Agency' }}
                            </p>

                            <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-gray-500">
                                <span class="text-lg font-bold text-black-900">
                                    {{ substr((string) $schedule->departure_time, 0, 5) }}
                                </span>
                                <span aria-hidden="true">·</span>
                                <span>
                                    {{ $schedule->route?->origin_city }} → {{ $schedule->route?->destination_city }}
                                </span>
                                <span aria-hidden="true">·</span>
                                <span>{{ $schedule->vehicle?->name }}</span>
                            </p>

                            <p class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                <x-gm.badge tone="neutral">{{ ucfirst((string) $schedule->travel_class) }}</x-gm.badge>
                                @if ($schedule->allow_cod)
                                    <x-gm.badge tone="brand">COD tersedia</x-gm.badge>
                                @endif
                                <span class="text-gray-500">
                                    sisa
                                    {{ $schedule->segment_available
                                        ?? max(0, (int) $schedule->vehicle?->capacity - (int) ($schedule->reserved_seats ?? 0)) }}
                                    kursi
                                </span>
                            </p>
                        </div>

                        <div class="text-right">
                            <p class="text-2xl font-bold tracking-tight text-gomad-green-deep">
                                <x-gm.money :amount="$schedule->price_per_seat" />
                            </p>
                            <p class="text-xs text-gray-500">per kursi</p>

                            <a href="{{ route('public.schedule', $schedule) }}" class="mt-3 inline-block">
                                <x-gm.button type="button" size="sm" variant="brand" class="rounded-pill">Lihat kursi</x-gm.button>
                            </a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
