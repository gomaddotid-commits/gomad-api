@extends('layouts.app')

@section('title', 'Mad Warung — belanja grosir untuk warung')

@section('content')
    <x-gm.page-header
        eyebrow="Mad Warung"
        title="Belanja grosir tanpa antre"
        subtitle="Penjaga warung memesan dari grosir dengan plafon yang disetujui pemilik. Pemilik memantau semuanya dari satu tempat.">
        <x-slot:actions>
            <a href="{{ route('login') }}">
                <x-gm.button type="button" variant="secondary">Masuk sebagai penjaga</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    <div class="grid gap-4 md:grid-cols-3">
        <x-gm.stat label="Outlet aktif" :value="$outlets->count()" hint="Warung & grosir" />
        <x-gm.stat label="Grosir" :value="$outlets->where('type', 'grosir')->count()" hint="Pemasok barang" />
        <x-gm.stat label="Warung" :value="$outlets->where('type', 'warung')->count()" hint="Pembeli harian" />
    </div>

    <section class="mt-10">
        <h2 class="text-xl font-bold tracking-tight text-black-900">Grosir yang memasok</h2>
        @php $wholesalers = $outlets->where('type', 'grosir'); @endphp

        @if ($wholesalers->isEmpty())
            <x-gm.empty class="mt-4" title="Belum ada grosir aktif"
                description="Grosir yang bergabung akan muncul di sini beserta katalognya." />
        @else
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($wholesalers as $wholesaler)
                    <div class="rounded-lg border border-gray-200 bg-white px-5 py-4">
                        <p class="text-base font-semibold text-black-900">{{ $wholesaler->name }}</p>
                        @if ($wholesaler->verified_at !== null)
                            <p class="mt-1"><x-gm.badge tone="success">Terverifikasi</x-gm.badge></p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <section class="mt-10">
        <h2 class="text-xl font-bold tracking-tight text-black-900">Warung yang berjualan</h2>
        @php $warungs = $outlets->where('type', 'warung'); @endphp

        @if ($warungs->isEmpty())
            <x-gm.empty class="mt-4" title="Belum ada warung aktif"
                description="Warung yang bergabung akan muncul di sini." />
        @else
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($warungs as $warung)
                    <div class="rounded-lg border border-gray-200 bg-white px-5 py-4">
                        <p class="text-base font-semibold text-black-900">{{ $warung->name }}</p>
                        @if ($warung->verified_at !== null)
                            <p class="mt-1"><x-gm.badge tone="success">Terverifikasi</x-gm.badge></p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <section class="mt-10 grid gap-4 md:grid-cols-2">
        <x-gm.card title="Untuk penjaga warung">
            <ul class="space-y-2 text-sm text-gray-600">
                <li>Plafon harian terlihat sebelum checkout.</li>
                <li>Pesanan di atas ambang dikirim ke pemilik untuk disetujui.</li>
                <li>Konfirmasi barang diterima langsung dari ponsel.</li>
            </ul>
        </x-gm.card>

        <x-gm.card title="Untuk pemilik warung">
            <ul class="space-y-2 text-sm text-gray-600">
                <li>Atur plafon per penjaga dan ambang persetujuan.</li>
                <li>Setujui atau tolak pesanan dengan alasan.</li>
                <li>Laporan per outlet dan aktivitas penjaga.</li>
            </ul>
        </x-gm.card>
    </section>

    <section class="mt-10 rounded-lg border border-gray-200 bg-white px-6 py-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-black-900">Sudah punya akun warung?</h2>
                <p class="mt-1 text-sm text-gray-600">Masuk untuk melihat katalog dan plafon Anda.</p>
            </div>
            <a href="{{ route('login') }}">
                <x-gm.button type="button" variant="brand">Masuk</x-gm.button>
            </a>
        </div>
    </section>
@endsection
