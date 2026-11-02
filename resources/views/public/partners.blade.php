@extends('layouts.app')

@section('title', 'Jadi mitra GoMad')

@section('content')
    <x-gm.page-header
        eyebrow="Kemitraan"
        title="Tumbuh bersama GoMad"
        subtitle="Satu akun untuk travel, rental, dan warung. Tidak ada biaya pendaftaran; yang dibutuhkan hanya usaha yang aktif dan data yang benar.">
        <x-slot:actions>
            <a href="{{ route('register') }}">
                <x-gm.button type="button" variant="brand">Daftar sebagai mitra</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    <div class="grid gap-4 md:grid-cols-3">
        <x-gm.card title="Travel & rental">
            <ul class="space-y-2 text-sm text-gray-600">
                <li>Terbitkan jadwal dan atur harga per segmen.</li>
                <li>Rental dengan supir, lepas kunci, atau charter.</li>
                <li>Dana masuk ke dompet setelah transaksi tercatat.</li>
            </ul>
        </x-gm.card>

        <x-gm.card title="Warung">
            <ul class="space-y-2 text-sm text-gray-600">
                <li>Belanja grosir dengan plafon harian per penjaga.</li>
                <li>Pemilik menyetujui pesanan di atas ambang.</li>
                <li>Laporan per outlet dan per penjaga.</li>
            </ul>
        </x-gm.card>

        <x-gm.card title="Grosir">
            <ul class="space-y-2 text-sm text-gray-600">
                <li>Katalog & harga per grosir.</li>
                <li>Pesanan masuk dengan status yang jelas.</li>
                <li>Settlement tercatat di ledger yang sama.</li>
            </ul>
        </x-gm.card>
    </div>

    <section class="mt-10">
        <h2 class="text-xl font-bold tracking-tight text-black-900">Mitra travel & rental</h2>
        <p class="mt-1 text-sm text-gray-600">Agency yang sudah aktif di GoMad.</p>

        @if ($agencies->isEmpty())
            <x-gm.empty class="mt-4" title="Belum ada agency aktif"
                description="Agency yang selesai verifikasi akan tampil di sini." />
        @else
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($agencies as $agency)
                    <div class="rounded-lg border border-gray-200 bg-white px-5 py-4">
                        <p class="text-base font-semibold text-black-900">{{ $agency->name }}</p>
                        <p class="mt-1">
                            @if ($agency->verified_at !== null)
                                <x-gm.badge tone="success">Terverifikasi</x-gm.badge>
                            @else
                                <x-gm.badge tone="warning">Menunggu verifikasi</x-gm.badge>
                            @endif
                        </p>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <section class="mt-10">
        <h2 class="text-xl font-bold tracking-tight text-black-900">Outlet warung & grosir</h2>
        <p class="mt-1 text-sm text-gray-600">Jaringan dagang yang memasok kebutuhan harian.</p>

        @if ($outlets->isEmpty())
            <x-gm.empty class="mt-4" title="Belum ada outlet aktif"
                description="Warung dan grosir yang aktif akan tampil di sini." />
        @else
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($outlets as $outlet)
                    <div class="rounded-lg border border-gray-200 bg-white px-5 py-4">
                        <p class="text-base font-semibold text-black-900">{{ $outlet->name }}</p>
                        <p class="mt-1 flex items-center gap-2">
                            <x-gm.badge tone="neutral">{{ ucfirst($outlet->type) }}</x-gm.badge>
                            @if ($outlet->verified_at !== null)
                                <x-gm.badge tone="success">Terverifikasi</x-gm.badge>
                            @endif
                        </p>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
@endsection
