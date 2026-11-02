@extends('layouts.app')

@section('title', 'Pelacakan '.$business->name)

@section('content')
    <x-gm.page-header
        eyebrow="Ruang kerja agency"
        title="Papan pelacakan"
        subtitle="Keberangkatan hari ini sampai dua hari ke depan, beserta jumlah penumpang yang sudah naik.">
        <x-slot:actions>
            <a href="{{ route('mad-trans.agency.schedules', $business) }}">
                <x-gm.button type="button" variant="secondary">Kelola jadwal</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @include('madtrans.travel._nav', ['business' => $business, 'active' => 'tracking'])

    @if ($schedules->isEmpty())
        <x-gm.empty title="Tidak ada keberangkatan"
            description="Belum ada jadwal dalam rentang kemarin sampai dua hari ke depan." />
    @else
        <div class="space-y-3">
            @foreach ($schedules as $schedule)
                @php
                    $tone = match ($schedule->status) {
                        'in_progress' => 'info',
                        'completed' => 'success',
                        'cancelled' => 'error',
                        'boarding' => 'warning',
                        default => 'neutral',
                    };
                    $total = (int) $schedule->passenger_count;
                    $boarded = (int) $schedule->boarded_count;
                    $percent = $total > 0 ? (int) round($boarded / $total * 100) : 0;
                @endphp
                <x-gm.card :padded="false">
                    <div class="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
                        <div>
                            <p class="text-sm font-semibold text-black-900">
                                {{ $schedule->route?->origin_city }} → {{ $schedule->route?->destination_city }}
                            </p>
                            <p class="mt-0.5 text-xs text-gray-600">
                                {{ $schedule->departure_date->toDateString() }}
                                {{ substr((string) $schedule->departure_time, 0, 5) }}
                                · {{ $schedule->vehicle?->name ?? '—' }} ({{ $schedule->vehicle?->plate_number ?? '—' }})
                                · {{ $schedule->driverMembership?->user?->name ?? 'tanpa supir' }}
                            </p>
                            @if ($schedule->started_at)
                                <p class="mt-1 text-xs text-gray-600">
                                    Mulai {{ $schedule->started_at->format('d M H:i') }}
                                    @if ($schedule->finished_at)
                                        · selesai {{ $schedule->finished_at->format('d M H:i') }}
                                    @endif
                                </p>
                            @endif
                        </div>
                        <div class="text-right">
                            <x-gm.badge :tone="$tone">{{ str_replace('_', ' ', (string) $schedule->status) }}</x-gm.badge>
                            <p class="mt-1 text-xs text-gray-600">
                                {{ $boarded }}/{{ $total }} naik
                                @if ((int) $schedule->no_show_count > 0)
                                    · {{ (int) $schedule->no_show_count }} tidak hadir
                                @endif
                            </p>
                        </div>
                    </div>

                    <div class="px-5 pb-4">
                        <div class="h-1.5 overflow-hidden rounded-pill bg-gray-100">
                            <div class="h-full rounded-pill bg-gomad-green" style="width: {{ max(2, $percent) }}%"></div>
                        </div>
                    </div>

                    <div class="border-t border-gray-200 px-5 py-3">
                        <a href="{{ route('mad-trans.agency.schedules.show', [$business, $schedule]) }}"
                           class="text-sm font-semibold text-black-900 hover:underline">Detail jadwal &amp; penumpang →</a>
                    </div>
                </x-gm.card>
            @endforeach
        </div>
    @endif
@endsection
