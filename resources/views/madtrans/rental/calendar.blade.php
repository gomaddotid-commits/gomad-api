@extends('layouts.app')

@section('title', 'Kalender armada — '.$vehicle->name)

@section('content')
    @php
        $startOffset = (int) $month->copy()->startOfMonth()->dayOfWeekIso - 1; // Senin = 0
        $totalDays = (int) $month->daysInMonth;
        $cells = array_fill(0, $startOffset, null);
        for ($day = 1; $day <= $totalDays; $day++) {
            $cells[] = $day;
        }
        while (count($cells) % 7 !== 0) {
            $cells[] = null;
        }
        $rows = array_chunk($cells, 7);
        $today = now()->toDateString();
        $weekdayLabels = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
        $monthLabels = [
            1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
        ];
    @endphp

    <x-gm.page-header
        eyebrow="Rental"
        title="Kalender armada"
        :subtitle="$vehicle->name.' · '.$vehicle->plate_number.' · '.$business->name">
        <x-slot:actions>
            <a href="{{ route('rental.show', $business) }}">
                <x-gm.button type="button" variant="secondary">← Sewa kendaraan</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    <x-gm.card :padded="false">
        {{-- Navigasi bulan: tiga tujuan yang selalu sama, jadi tidak perlu dijelaskan. --}}
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-5 py-4">
            <div>
                <p class="text-base font-semibold text-black-900">
                    {{ $monthLabels[(int) $month->month] }} {{ $month->year }}
                </p>
                <p class="mt-0.5 text-xs text-gray-500">{{ count($takenDays) }} hari terpakai</p>
            </div>

            <div class="flex flex-wrap items-center gap-1">
                <a href="{{ route('rental.calendar', [$business, $vehicle, 'month' => $prevMonth->format('Y-m')]) }}">
                    <x-gm.button type="button" size="sm" variant="ghost">← Bulan lalu</x-gm.button>
                </a>
                <a href="{{ route('rental.calendar', [$business, $vehicle, 'month' => now()->format('Y-m')]) }}">
                    <x-gm.button type="button" size="sm" variant="secondary">Bulan ini</x-gm.button>
                </a>
                <a href="{{ route('rental.calendar', [$business, $vehicle, 'month' => $nextMonth->format('Y-m')]) }}">
                    <x-gm.button type="button" size="sm" variant="ghost">Bulan depan →</x-gm.button>
                </a>
            </div>
        </div>

        {{--
            Kalender memakai grid Tailwind, bukan tabel bergaya inline.
            Keterangan warna tidak boleh jadi satu-satunya penanda (§12.5), jadi
            hari terpakai juga memuat label teks penyebabnya.
        --}}
        <div class="overflow-x-auto p-3">
            <div class="min-w-[560px]">
                <div class="grid grid-cols-7 gap-1">
                    @foreach ($weekdayLabels as $label)
                        <div class="px-2 py-1 text-xs font-semibold uppercase tracking-wide text-gray-500">
                            {{ $label }}
                        </div>
                    @endforeach

                    @foreach ($rows as $row)
                        @foreach ($row as $day)
                            @php
                                $date = $day === null ? null : $month->copy()->day($day);
                                $key = $date?->toDateString();
                                $entries = $key !== null ? ($takenDays[$key] ?? []) : [];
                                $isTaken = $entries !== [];
                                $isToday = $key === $today;
                            @endphp

                            <div class="min-h-[76px] rounded-md border p-2
                                        {{ $isTaken
                                            ? 'border-error-600/30 bg-error-600/5'
                                            : 'border-gray-200 bg-white' }}">
                                @if ($day !== null)
                                    <p class="text-[13px] {{ $isToday ? 'font-bold text-black-900' : 'font-medium text-gray-600' }}">
                                        {{ $day }}
                                        @if ($isToday)
                                            <span class="ml-1 text-[10px] font-semibold uppercase text-gomad-green">hari ini</span>
                                        @endif
                                    </p>

                                    @foreach ($entries as $entry)
                                        <p class="mt-1 text-[11px] leading-snug text-error-600">
                                            {{ $entry['label'] }}
                                        </p>
                                    @endforeach
                                @endif
                            </div>
                        @endforeach
                    @endforeach
                </div>
            </div>
        </div>

        <div class="border-t border-gray-200 px-5 py-3">
            <p class="text-xs text-gray-500">
                Hari bertanda merah sudah dipakai sewa yang menunggu/dibayar/berjalan, atau jadwal travel
                dengan armada ini.
            </p>
        </div>
    </x-gm.card>
@endsection
