@extends('layouts.app')

@section('title', $schedule->business->name.' — '.$schedule->route->origin_city.' ke '.$schedule->route->destination_city)

@section('content')
    <x-gm.page-header
        eyebrow="Mad Trans"
        :title="$schedule->business->name"
        :subtitle="$schedule->route->origin_city.' → '.$schedule->route->destination_city.' · '.$schedule->departure_date->toDateString()">
        <x-slot:actions>
            <a href="{{ route('public.search', [
                    'origin_city' => $schedule->route->origin_city,
                    'destination_city' => $schedule->route->destination_city,
                    'departure_date' => $schedule->departure_date->toDateString(),
                    'passengers' => 1,
                ]) }}">
                <x-gm.button type="button" variant="secondary">Hasil pencarian</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-5">
            {{--
                Ringkasan perjalanan.

                Jam berangkat, rute, dan harga adalah tiga hal pertama yang dicari
                orang sebelum memutuskan. Ketiganya ditaruh di satu kartu teratas
                supaya tidak perlu menyusuri halaman (§8.5 "transparent price
                presentation").
            --}}
            <x-gm.card>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="gm-label uppercase tracking-wide text-gomad-green">
                            {{ $schedule->departure_date->format('d M Y') }}
                        </p>
                        <p class="mt-1 text-3xl font-bold tracking-tight text-gomad-green-deep">
                            {{ substr((string) $schedule->departure_time, 0, 5) }}
                        </p>
                        <p class="mt-1 text-sm text-gray-500">
                            {{ $stops->first()?->routeStop?->name ?? $schedule->route->origin_city }}
                            →
                            {{ $stops->last()?->routeStop?->name ?? $schedule->route->destination_city }}
                        </p>
                    </div>

                    <div class="text-right">
                        <p class="text-2xl font-bold tracking-tight text-gomad-green-deep">
                            <x-gm.money :amount="$schedule->price_per_seat" />
                        </p>
                        <p class="text-xs text-gray-500">per kursi</p>
                    </div>
                </div>

                <dl class="mt-4 grid gap-4 border-t border-gray-200/70 pt-4 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Kendaraan</dt>
                        <dd class="mt-1 text-sm font-semibold text-black-900">
                            {{ $schedule->vehicle?->name }}
                            <span class="font-normal text-gray-500">· {{ count($seats) }} kursi</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Kelas</dt>
                        <dd class="mt-1 text-sm font-semibold text-black-900">
                            {{ ucfirst((string) $schedule->travel_class) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Pembayaran</dt>
                        <dd class="mt-1 flex flex-wrap gap-2">
                            <x-gm.badge tone="info">Online (Midtrans)</x-gm.badge>
                            @if ($schedule->allow_cod)
                                <x-gm.badge tone="brand">COD — bayar ke supir</x-gm.badge>
                            @endif
                        </dd>
                    </div>
                </dl>
            </x-gm.card>

            <x-gm.card title="Titik pemberhentian" subtitle="Pilih titik jemput dan antar saat memesan.">
                @if ($stops->isEmpty())
                    <p class="text-sm text-gray-600">Rute ini belum memiliki daftar titik.</p>
                @else
                    <ol class="space-y-3">
                        @foreach ($stops as $stop)
                            <li class="flex items-start gap-3">
                                <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-pill bg-lime-100 text-xs font-bold text-gomad-green-deep">
                                    {{ $stop->stop_order }}
                                </span>
                                <div>
                                    <p class="text-sm font-semibold text-black-900">
                                        {{ $stop->routeStop?->city_name }}
                                    </p>
                                    <p class="text-xs text-gray-500">{{ $stop->routeStop?->name }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-gm.card>

            <x-gm.card title="Harga per segmen" subtitle="Harga ditampilkan apa adanya dari agency.">
                @if ($pricing->isEmpty())
                    <p class="text-sm text-gray-600">Rincian harga belum tersedia.</p>
                @else
                    <ul class="divide-y divide-gray-200">
                        @foreach ($pricing as $price)
                            @php
                                $origin = $stops->firstWhere('route_stop_id', $price->origin_stop_id);
                                $destination = $stops->firstWhere('route_stop_id', $price->destination_stop_id);
                            @endphp
                            <li class="flex items-center justify-between gap-3 py-2 text-sm">
                                <span class="text-gray-600">
                                    {{ $origin?->routeStop?->city_name ?? 'Titik #'.$price->origin_stop_id }}
                                    →
                                    {{ $destination?->routeStop?->city_name ?? 'Titik #'.$price->destination_stop_id }}
                                </span>
                                <span class="font-semibold text-black-900"><x-gm.money :amount="$price->price" /></span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-gm.card>
        </div>

        <aside class="space-y-5">
            <x-gm.card title="Kursi">
                <p class="text-sm text-gray-500">Denah kursi terbuka setelah masuk, agar kursi yang sedang dipesan orang lain tidak tertukar.</p>

                <div class="mt-3 flex flex-wrap gap-1.5">
                    @foreach ($seats as $seat)
                        <span class="inline-flex h-8 w-9 items-center justify-center rounded-sm border border-gray-300 text-xs font-medium text-gray-500">
                            {{ $seat }}
                        </span>
                    @endforeach
                </div>

                <p class="mt-4 text-2xl font-bold tracking-tight text-gomad-green-deep">
                    <x-gm.money :amount="$schedule->price_per_seat" />
                    <span class="text-xs font-normal text-gray-500">/ kursi</span>
                </p>

                <a href="{{ route('login') }}" class="mt-3 block">
                    <x-gm.button type="button" class="w-full rounded-pill" variant="brand">Masuk untuk memesan</x-gm.button>
                </a>

                <p class="mt-2 text-center text-xs text-gray-500">
                    Belum punya akun?
                    <a href="{{ route('register') }}" class="font-semibold text-gomad-green hover:underline">Daftar</a>
                </p>
            </x-gm.card>

            <x-gm.card title="Kenapa lewat GoMad?">
                <ul class="space-y-2 text-sm text-gray-500">
                    <li>Kursi terkunci untuk Anda selama batas waktu pembayaran.</li>
                    <li>E-ticket langsung terbit setelah pembayaran terkonfirmasi.</li>
                    <li>Pembatalan menampilkan estimasi refund sebelum dikonfirmasi.</li>
                </ul>
            </x-gm.card>
        </aside>
    </div>

    {{--
        §10.2 \"sticky booking CTA\" — hanya di ponsel.

        Di layar kecil kolom ringkasan berada jauh di bawah; tanpa CTA lengket,
        pengunjung harus menggulir balik ke atas untuk memesan.
    --}}
    <div class="gm-sticky-cta mt-5 flex items-center justify-between gap-3 md:hidden">
        <div class="min-w-0">
            <p class="truncate text-lg font-bold text-gomad-green-deep">
                <x-gm.money :amount="$schedule->price_per_seat" />
            </p>
            <p class="text-xs text-gray-500">per kursi</p>
        </div>
        <a href="{{ route('login') }}" class="shrink-0">
            <x-gm.button type="button" variant="brand" class="rounded-pill">Masuk untuk memesan</x-gm.button>
        </a>
    </div>
@endsection
