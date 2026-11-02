@extends('layouts.app')

@section('title', 'Mad Trans — Tugas Saya')

@section('content')
    {{--
        Antarmuka supir (DS v1.0 §7.4).

        Syarat yang dipegang di sini:
        - mobile-first: satu kolom, tidak ada tabel;
        - kontrol besar: seluruh kartu adalah tautan, tinggi jauh di atas 44px;
        - status jelas: badge status + jam besar, bisa dibaca sekilas;
        - perjalanan aktif dan selesai dipisah tegas;
        - sedikit mungkin yang harus disentuh saat mengemudi. Kartu tugas hanya
          membuka satu halaman; konfirmasi penumpang dilakukan di sana, bukan di
          daftar ini.
    --}}
    <x-gm.page-header
        eyebrow="Mad Trans"
        title="Tugas saya"
        subtitle="Jadwal yang ditugaskan kepada Anda sebagai supir.">
        <x-slot:actions>
            <x-gm.badge tone="brand">{{ $schedules->count() }} tugas aktif</x-gm.badge>
        </x-slot:actions>
    </x-gm.page-header>

    @if ($schedules->isEmpty())
        <x-gm.empty
            title="Belum ada tugas"
            description="Jadwal yang ditugaskan pemilik agency akan muncul di sini." />
    @else
        <ul class="space-y-3">
            @foreach ($schedules as $schedule)
                @php
                    $tone = match ($schedule->status) {
                        'in_progress' => 'info',
                        'published' => 'success',
                        default => 'neutral',
                    };
                    $statusLabel = match ($schedule->status) {
                        'in_progress' => 'Sedang berjalan',
                        'published' => 'Siap berangkat',
                        default => ucfirst((string) $schedule->status),
                    };
                @endphp

                <li>
                    <a href="{{ route('mad-trans.driver.schedules.show', $schedule) }}"
                       class="block rounded-lg border border-gray-200 bg-white px-5 py-4 transition-colors duration-150 hover:border-green-400 hover:bg-gray-50">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-lg font-semibold text-black-900">
                                    {{ $schedule->route->origin_city }} → {{ $schedule->route->destination_city }}
                                </p>
                                <p class="mt-1 text-sm text-gray-500">
                                    {{ $schedule->business->name }}
                                    · {{ $schedule->vehicle->name }} ({{ $schedule->vehicle->plate_number }})
                                </p>
                            </div>

                            <x-gm.badge :tone="$tone">{{ $statusLabel }}</x-gm.badge>
                        </div>

                        <div class="mt-4 flex flex-wrap items-end justify-between gap-3 border-t border-gray-200 pt-3">
                            <div>
                                <p class="text-3xl font-bold leading-none tracking-tight text-black-900">
                                    {{ substr((string) $schedule->departure_time, 0, 5) }}
                                </p>
                                <p class="mt-1 text-sm text-gray-500">
                                    {{ $schedule->departure_date->format('d M Y') }}
                                </p>
                            </div>

                            <p class="text-sm text-gray-500">
                                <span class="font-semibold text-black-900">{{ $schedule->bookings_count }}</span>
                                pesanan
                            </p>
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    @if (($completedSchedules ?? collect())->isNotEmpty())
        <section class="mt-8">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                Selesai — 7 hari terakhir
            </h2>

            <ul class="mt-2 space-y-2">
                @foreach ($completedSchedules as $schedule)
                    <li>
                        <a href="{{ route('mad-trans.driver.schedules.show', $schedule) }}"
                           class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-gray-50 px-5 py-3 hover:bg-white">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-gray-700">
                                    {{ $schedule->route->origin_city }} → {{ $schedule->route->destination_city }}
                                </p>
                                <p class="mt-0.5 text-xs text-gray-500">
                                    {{ $schedule->departure_date->format('d M Y') }}
                                    {{ substr((string) $schedule->departure_time, 0, 5) }}
                                    · {{ $schedule->bookings_count }} pesanan
                                </p>
                            </div>

                            <x-gm.badge tone="neutral">Selesai</x-gm.badge>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
@endsection
