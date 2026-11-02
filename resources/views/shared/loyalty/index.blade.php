@extends('layouts.app')

@section('title', 'Level & misi | GoMad')

@section('content')
    {{--
        Level & misi. Pelanggan membuka ini untuk satu pertanyaan: "berapa lagi
        supaya naik level?" Karena itu poin dan bilah kemajuan taruh paling atas,
        sebelum daftar misi.
    --}}
    <x-gm.page-header
        eyebrow="Akun"
        title="Level & misi"
        :subtitle="'Level Anda sekarang: '.$summary['level']['name']">
        <x-slot:actions>
            <a href="{{ route('dashboard') }}">
                <x-gm.button type="button" variant="secondary">← Beranda</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    <div class="grid gap-4 sm:grid-cols-2">
        <x-gm.stat label="Poin"
            :value="number_format($summary['points'], 0, ',', '.')"
            hint="Setiap Rp 10.000 transaksi lunas menambah 1 poin"
            tone="brand" />
        <x-gm.stat label="Level"
            :value="$summary['level']['name']"
            :hint="$summary['next'] !== null
                ? number_format($summary['to_next'], 0, ',', '.').' poin lagi menuju '.$summary['next']['name']
                : 'Sudah di level tertinggi'" />
    </div>

    <x-gm.card class="mt-4" title="Kemajuan level">
        @if ($summary['next'] !== null)
            <div class="flex items-center justify-between text-sm">
                <span class="font-semibold text-black-900">{{ $summary['level']['name'] }}</span>
                <span class="text-gray-500">{{ $summary['next']['name'] }}</span>
            </div>
            <div class="mt-2 h-2.5 w-full overflow-hidden rounded-pill bg-gray-100"
                 role="img"
                 aria-label="Kemajuan {{ $summary['progress_percent'] }} persen menuju {{ $summary['next']['name'] }}">
                <div class="h-full rounded-pill bg-gomad-green" style="width: {{ $summary['progress_percent'] }}%"></div>
            </div>
            <p class="muted mt-2">{{ number_format($summary['to_next'], 0, ',', '.') }} poin lagi.</p>
        @else
            <p class="muted">Anda sudah di level tertinggi.</p>
        @endif
    </x-gm.card>

    <x-gm.card class="mt-4" title="Misi" :padded="false">
        <div class="divide-y divide-gray-100">
            @forelse ($summary['missions'] as $mission)
                <div class="gm-list-row px-5">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-black-900">
                            {{ $mission['title'] }}
                            @if ($mission['done'])
                                <x-gm.badge tone="success" class="ml-1">Selesai</x-gm.badge>
                            @endif
                        </p>
                        <p class="mt-0.5 text-xs text-gray-500">{{ $mission['description'] }}</p>
                    </div>
                    <p class="text-sm text-gray-500">{{ $mission['progress'] }}/{{ $mission['target'] }}</p>
                </div>
            @empty
                <p class="px-5 py-6 text-sm text-gray-500">Belum ada misi untuk level Anda.</p>
            @endforelse
        </div>
    </x-gm.card>

    <x-gm.card class="mt-4" title="Riwayat poin" :padded="false">
        <div class="divide-y divide-gray-100">
            @forelse ($events as $event)
                <div class="gm-list-row px-5">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-black-900">{{ $event->kind }}</p>
                        <p class="mt-0.5 text-xs text-gray-500">
                            {{ $event->reference_type }} #{{ $event->reference_id }}
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-semibold text-success-600">+{{ $event->points }} poin</p>
                        <p class="text-xs text-gray-500">{{ $event->occurred_at?->format('d M Y H:i') }}</p>
                    </div>
                </div>
            @empty
                <p class="px-5 py-6 text-sm text-gray-500">
                    Belum ada poin. Selesaikan transaksi pertama Anda.
                </p>
            @endforelse
        </div>
    </x-gm.card>
@endsection
