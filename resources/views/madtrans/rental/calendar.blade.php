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

    <div class="card">
        <h1>Kalender armada</h1>
        <p class="muted">
            {{ $vehicle->name }} · {{ $vehicle->plate_number }} · {{ $business->name }}
        </p>
        <p class="muted">
            Hari bertanda <strong>terpakai</strong> sudah dipakai sewa yang menunggu/dibayar/berjalan
            atau jadwal travel dengan armada ini.
        </p>
        <p><a href="{{ route('rental.show', $business) }}">← Kembali ke sewa kendaraan</a></p>
    </div>

    <div class="card">
        <div class="row" style="align-items:center;justify-content:space-between">
            <div>
                <strong>{{ $monthLabels[(int) $month->month] }} {{ $month->year }}</strong>
                <span class="muted"> · {{ count($takenDays) }} hari terpakai</span>
            </div>
            <div>
                <a href="{{ route('rental.calendar', [$business, $vehicle, 'month' => $prevMonth->format('Y-m')]) }}">← Bulan lalu</a>
                <a href="{{ route('rental.calendar', [$business, $vehicle, 'month' => now()->format('Y-m')]) }}">Bulan ini</a>
                <a href="{{ route('rental.calendar', [$business, $vehicle, 'month' => $nextMonth->format('Y-m')]) }}">Bulan depan →</a>
            </div>
        </div>

        <table style="width:100%;margin-top:12px;border-collapse:collapse;table-layout:fixed">
            <thead>
                <tr>
                    @foreach ($weekdayLabels as $label)
                        <th style="padding:6px;font-size:12px;color:#5b7071;text-align:left">{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($row as $day)
                            @php
                                $date = $day === null ? null : $month->copy()->day($day);
                                $key = $date?->toDateString();
                                $entries = $key !== null ? ($takenDays[$key] ?? []) : [];
                                $isTaken = $entries !== [];
                            @endphp
                            <td style="border:1px solid #e6edee;vertical-align:top;height:76px;padding:6px;
                                {{ $isTaken ? 'background:#fdecec' : 'background:#fff' }}">
                                @if ($day !== null)
                                    <div style="font-size:13px;{{ $key === $today ? 'font-weight:700' : '' }}">
                                        {{ $day }}
                                    </div>
                                    @foreach ($entries as $entry)
                                        <div style="font-size:11px;color:#8a3b3b;margin-top:2px">
                                            {{ $entry['label'] }}
                                        </div>
                                    @endforeach
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
