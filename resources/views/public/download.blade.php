@extends('layouts.app')

@section('title', 'Unduh aplikasi GoMad')

@section('content')
    <x-gm.page-header
        eyebrow="Aplikasi"
        title="GoMad di ponsel"
        subtitle="Aplikasi Android memakai kontrak API yang sama dengan web — tiket dan pesanan tetap sinkron." />

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-5">
            <x-gm.card title="Android">
                <ol class="space-y-3 text-sm text-gray-600">
                    <li><span class="font-semibold text-black-900">1.</span> Unduh berkas APK dari tautan yang dibagikan tim GoMad.</li>
                    <li><span class="font-semibold text-black-900">2.</span> Izinkan pemasangan dari sumber ini bila diminta.</li>
                    <li><span class="font-semibold text-black-900">3.</span> Masuk dengan akun yang sama seperti di web.</li>
                </ol>

                <x-gm.alert tone="info" class="mt-4">
                    Versi publik belum dirilis. Untuk uji coba, gunakan akun demo dan arahkan aplikasi ke
                    server staging yang diberikan tim.
                </x-gm.alert>
            </x-gm.card>

            <x-gm.card title="Apa yang bisa dilakukan di aplikasi">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <p class="text-sm font-semibold text-black-900">Penumpang</p>
                        <ul class="mt-1 space-y-1 text-sm text-gray-600">
                            <li>Cari jadwal & pilih kursi</li>
                            <li>Bayar dan simpan e-ticket</li>
                            <li>Riwayat pesanan</li>
                        </ul>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-black-900">Penjaga & supir</p>
                        <ul class="mt-1 space-y-1 text-sm text-gray-600">
                            <li>Pesan grosir sesuai plafon</li>
                            <li>Konfirmasi barang diterima</li>
                            <li>Daftar tugas dan boarding</li>
                        </ul>
                    </div>
                </div>
            </x-gm.card>
        </div>

        <aside class="space-y-5">
            <x-gm.card title="Sebelum memasang">
                <ul class="space-y-2 text-sm text-gray-600">
                    <li>Android 8 ke atas.</li>
                    <li>Koneksi internet untuk pembayaran.</li>
                    <li>Akun aktif — pendaftaran bisa dari web.</li>
                </ul>

                <a href="{{ route('register') }}" class="mt-4 block">
                    <x-gm.button type="button" class="w-full" variant="brand">Buat akun dulu</x-gm.button>
                </a>
            </x-gm.card>

            <x-gm.card title="Tanpa aplikasi?">
                <p class="text-sm text-gray-600">
                    Semua fitur penumpang tersedia di web. Buka
                    <a href="{{ route('mad-trans.index') }}" class="font-semibold text-black-900 hover:underline">halaman cari jadwal</a>
                    dari browser ponsel.
                </p>
            </x-gm.card>
        </aside>
    </div>
@endsection
