@extends('layouts.app')

@section('title', 'Mad Trans — Portal Agency')

@section('content')
    <x-gm.page-header
        eyebrow="Ruang kerja agency"
        title="Portal agency"
        subtitle="Kelola jadwal, armada, rute, dan pesanan penumpang.">
        <x-slot:actions>
            <a href="{{ route('businesses.index') }}">
                <x-gm.button type="button" variant="secondary">Semua usaha</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @forelse ($agencies as $agency)
        <x-gm.card :title="$agency->name" :subtitle="$agency->code">
            <x-slot:actions>
                @if ($agency->status === 'active')
                    <x-gm.badge tone="success">Aktif</x-gm.badge>
                @elseif ($agency->status === 'draft')
                    <x-gm.badge tone="warning">Menunggu verifikasi</x-gm.badge>
                @else
                    <x-gm.badge tone="neutral">{{ ucfirst($agency->status) }}</x-gm.badge>
                @endif
            </x-slot:actions>

            @if ($agency->status === 'active')
                <a href="{{ route('mad-trans.agency.show', $agency) }}">
                    <x-gm.button type="button" size="sm">Buka ruang kerja</x-gm.button>
                </a>
            @else
                {{--
                    Usaha draft selalu berasal dari provisioning otomatis saat
                    pendaftaran. Ruang kerja belum dibuka sampai ops memverifikasi,
                    jadi yang ditawarkan adalah melengkapi profil — bukan pintu
                    yang akan menolak saat diklik.
                --}}
                <p class="muted">
                    Usaha ini belum diverifikasi, sehingga ruang kerja belum bisa dibuka.
                    Lengkapi profil usaha lebih dulu; tim GoMad memverifikasi setelahnya.
                </p>
                <a href="{{ route('businesses.index') }}" class="mt-3 inline-block">
                    <x-gm.button type="button" size="sm" variant="brand">Lengkapi profil usaha</x-gm.button>
                </a>
            @endif
        </x-gm.card>
    @empty
        <x-gm.empty
            title="Belum ada agency atas nama Anda"
            description="Usaha agency dibuat otomatis saat Anda mendaftar sebagai pemilik travel & rental. Jika belum ada, buat usaha dengan jenis Agency.">
            <x-slot:action>
                <a href="{{ route('businesses.index') }}">
                    <x-gm.button type="button" variant="brand">Buka halaman usaha</x-gm.button>
                </a>
            </x-slot:action>
        </x-gm.empty>
    @endforelse
@endsection
