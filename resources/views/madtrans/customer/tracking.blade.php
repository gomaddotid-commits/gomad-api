@extends('layouts.app')

@section('title', 'Perjalanan '.$booking->code)

@section('content')
    <x-gm.page-header
        eyebrow="Pelacakan perjalanan"
        :title="$schedule->route?->origin_city.' → '.$schedule->route?->destination_city"
        :subtitle="$booking->code.' · '.$schedule->departure_date->toDateString().' '.substr((string) $schedule->departure_time, 0, 5)">
        <x-slot:actions>
            <a href="{{ route('mad-trans.bookings.show', $booking) }}">
                <x-gm.button type="button" variant="secondary">Detail pesanan</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @php
        $scheduleTone = match ($schedule->status) {
            'in_progress' => 'info',
            'completed' => 'success',
            'cancelled' => 'error',
            'boarding' => 'warning',
            default => 'neutral',
        };
        $scheduleLabel = match ($schedule->status) {
            'published' => 'Terjadwal',
            'boarding' => 'Proses naik',
            'in_progress' => 'Sedang berjalan',
            'completed' => 'Selesai',
            'cancelled' => 'Dibatalkan',
            'pending_approval' => 'Menunggu persetujuan',
            default => ucfirst(str_replace('_', ' ', (string) $schedule->status)),
        };
    @endphp

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-gm.card title="Status perjalanan">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm text-gray-600">Status jadwal</p>
                        <p class="mt-1"><x-gm.badge :tone="$scheduleTone">{{ $scheduleLabel }}</x-gm.badge></p>
                    </div>
                    <div class="text-right text-sm text-gray-600">
                        <p>Berangkat {{ $schedule->departure_date->toDateString() }} {{ substr((string) $schedule->departure_time, 0, 5) }}</p>
                        @if ($schedule->started_at)
                            <p>Mulai {{ $schedule->started_at->format('d M Y H:i') }}</p>
                        @endif
                        @if ($schedule->finished_at)
                            <p>Selesai {{ $schedule->finished_at->format('d M Y H:i') }}</p>
                        @endif
                    </div>
                </div>

                <ol class="mt-4 space-y-2">
                    @foreach ($timeline as $step)
                        <li class="flex items-center gap-3 text-sm">
                            <span class="flex h-5 w-5 items-center justify-center rounded-full text-xs font-bold
                                         {{ $step['done'] ? 'bg-gomad-green text-black-900' : 'bg-gray-200 text-gray-500' }}">
                                {{ $step['done'] ? '✓' : '·' }}
                            </span>
                            <span class="{{ $step['done'] ? 'font-medium text-black-900' : 'text-gray-500' }}">
                                {{ $step['label'] }}
                            </span>
                            <span class="text-xs text-gray-500">
                                {{ $step['at'] !== null ? \Illuminate\Support\Carbon::parse($step['at'])->format('d M Y H:i') : '' }}
                            </span>
                        </li>
                    @endforeach
                </ol>

                <p class="mt-4 text-xs text-gray-500">
                    Posisi kendaraan belum dikirim ke sistem, jadi tidak ada peta di halaman ini.
                    Yang tampil hanya kejadian yang benar-benar tercatat.
                </p>
            </x-gm.card>

            <x-gm.card title="Penumpang Anda" :padded="false">
                <div class="divide-y divide-gray-100">
                    @foreach ($passengers as $passenger)
                        @php
                            $state = match (true) {
                                $passenger->boarded_at !== null => ['Sudah naik', 'success'],
                                $passenger->no_show_at !== null => ['Tidak hadir', 'error'],
                                default => ['Belum naik', 'neutral'],
                            };
                        @endphp
                        <div class="flex items-center justify-between gap-3 px-5 py-3 text-sm">
                            <div>
                                <p class="font-semibold text-black-900">{{ $passenger->name }}</p>
                                <p class="text-xs text-gray-600">Kursi {{ $passenger->seat_snapshot }}</p>
                            </div>
                            <div class="text-right">
                                <x-gm.badge :tone="$state[1]">{{ $state[0] }}</x-gm.badge>
                                @if ($passenger->boarded_at)
                                    <p class="mt-1 text-xs text-gray-500">{{ $passenger->boarded_at->format('d M H:i') }}</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="border-t border-gray-200 px-5 py-3 text-xs text-gray-600">
                    {{ $boardedCount }} dari {{ $passengers->count() }} penumpang sudah naik
                    @if ($noShowCount > 0)
                        · {{ $noShowCount }} tidak hadir
                    @endif
                </div>
            </x-gm.card>
        </div>

        <aside class="space-y-5">
            <x-gm.card title="Kendaraan & supir">
                <dl class="space-y-2 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-600">Armada</dt>
                        <dd class="font-semibold text-black-900">{{ $schedule->vehicle?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-600">Nomor polisi</dt>
                        <dd class="font-semibold text-black-900">{{ $schedule->vehicle?->plate_number ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-600">Supir</dt>
                        <dd class="font-semibold text-black-900">{{ $schedule->driverMembership?->user?->name ?? 'belum ditetapkan' }}</dd>
                    </div>
                    @if ($schedule->driverMembership?->user?->phone)
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-gray-600">Kontak supir</dt>
                            <dd class="font-semibold text-black-900">{{ $schedule->driverMembership->user->phone }}</dd>
                        </div>
                    @endif
                </dl>
            </x-gm.card>

            <x-gm.card title="Titik perjalanan" :padded="false">
                <ol class="divide-y divide-gray-100">
                    @foreach ($schedule->stops as $stop)
                        <li class="px-5 py-2.5 text-sm">
                            <span class="text-xs text-gray-500">{{ $stop->stop_order }}.</span>
                            <span class="font-medium text-black-900">{{ $stop->routeStop?->city_name ?? '—' }}</span>
                            <span class="block text-xs text-gray-600">{{ $stop->routeStop?->name }}</span>
                        </li>
                    @endforeach
                </ol>
            </x-gm.card>

            @if ($booking->status !== 'cancelled')
                <a href="{{ route('mad-trans.bookings.show', $booking) }}" class="block">
                    <x-gm.button type="button" variant="secondary" class="w-full">Buka e-ticket</x-gm.button>
                </a>
            @endif
        </aside>
    </div>
@endsection
