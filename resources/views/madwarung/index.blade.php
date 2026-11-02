@extends('layouts.app')

@section('title', 'Mad Warung | GoMad')

@section('content')
    {{-- Pemilih ruang kerja: satu kartu per usaha, bukan tautan di dalam kalimat. --}}
    <x-gm.page-header
        eyebrow="Mad Warung"
        title="Ruang kerja"
        subtitle="Kelola pembelian warung, katalog grosir, plafon penjaga, dan jadwal shift.">
        <x-slot:actions>
            <a href="{{ route('businesses.index') }}">
                <x-gm.button type="button" variant="secondary">Usaha saya</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @forelse ($businesses as $business)
        <x-gm.card
            :title="$business->name"
            :subtitle="($business->type === 'warung' ? 'Warung' : 'Grosir').' · '.$business->code">
            <x-slot:actions>
                @if ($business->status === 'active')
                    <x-gm.badge tone="success">Aktif</x-gm.badge>
                @elseif ($business->status === 'draft')
                    <x-gm.badge tone="warning">Menunggu verifikasi</x-gm.badge>
                @else
                    <x-gm.badge tone="neutral">{{ ucfirst($business->status) }}</x-gm.badge>
                @endif
            </x-slot:actions>

            @if ($business->status === 'active')
                <a href="{{ route('warung.show', $business) }}">
                    <x-gm.button type="button" size="sm">Buka ruang kerja</x-gm.button>
                </a>
            @else
                <p class="muted">
                    Ruang kerja terbuka setelah tim GoMad memverifikasi usaha.
                    Lengkapi profil usaha lebih dulu di halaman usaha.
                </p>
                <a href="{{ route('businesses.index') }}" class="mt-3 inline-block">
                    <x-gm.button type="button" size="sm" variant="brand">Lengkapi profil usaha</x-gm.button>
                </a>
            @endif
        </x-gm.card>
    @empty
        <x-gm.empty
            title="Belum ada usaha warung atau grosir"
            description="Usaha dibuat otomatis saat Anda mendaftar sebagai pemilik warung atau grosir. Jika belum ada, buat dari halaman usaha.">
            <x-slot:action>
                <a href="{{ route('businesses.index') }}">
                    <x-gm.button type="button" variant="brand">Buka halaman usaha</x-gm.button>
                </a>
            </x-slot:action>
        </x-gm.empty>
    @endforelse
@endsection
