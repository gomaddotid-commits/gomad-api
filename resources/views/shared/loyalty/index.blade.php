@extends('layouts.app')

@section('title', 'Level & misi | GoMad')

@section('content')
    <div class="card">
        <h1>Level &amp; misi</h1>
        <div class="row">
            <span>Level Anda</span>
            <strong>{{ $summary['level']['name'] }}</strong>
        </div>
        <div class="row">
            <span>Poin</span>
            <strong>{{ number_format($summary['points'], 0, ',', '.') }}</strong>
        </div>
        @if ($summary['next'] !== null)
            <div class="row">
                <span>Menuju {{ $summary['next']['name'] }}</span>
                <strong>{{ number_format($summary['to_next'], 0, ',', '.') }} poin lagi</strong>
            </div>
            <div style="background:#e6edee;border-radius:999px;height:10px;margin-top:8px">
                <div style="background:#1f7a6d;height:10px;border-radius:999px;width:{{ $summary['progress_percent'] }}%"></div>
            </div>
        @else
            <p class="muted">Anda sudah di level tertinggi.</p>
        @endif
        <p class="muted">Setiap Rp 10.000 transaksi lunas menambah 1 poin.</p>
        <p><a href="{{ route('dashboard') }}">← Kembali</a></p>
    </div>

    <div class="card">
        <h2>Misi</h2>
        @foreach ($summary['missions'] as $mission)
            <div class="row" style="border-top:1px solid #e6edee;padding:10px 0">
                <div>
                    <strong>{{ $mission['title'] }}</strong>
                    @if ($mission['done'])
                        <span class="muted"> · selesai</span>
                    @endif
                    <br>
                    <span class="muted">{{ $mission['description'] }}</span>
                </div>
                <div class="muted">
                    {{ $mission['progress'] }}/{{ $mission['target'] }}
                </div>
            </div>
        @endforeach
    </div>

    <div class="card">
        <h2>Riwayat poin</h2>
        @forelse ($events as $event)
            <div class="row" style="border-top:1px solid #e6edee;padding:8px 0">
                <div>
                    <strong>{{ $event->kind }}</strong><br>
                    <span class="muted">{{ $event->reference_type }} #{{ $event->reference_id }}</span>
                </div>
                <div class="muted">
                    +{{ $event->points }} poin
                    · {{ $event->occurred_at?->toDateTimeString() }}
                </div>
            </div>
        @empty
            <p class="muted">Belum ada poin. Selesaikan transaksi pertama Anda.</p>
        @endforelse
    </div>
@endsection
