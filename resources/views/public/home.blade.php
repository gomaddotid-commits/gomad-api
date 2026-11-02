@extends('layouts.app')

@section('title', 'GoMad — pesan travel & belanja warung')

@section('content')
    {{--
        Beranda publik (DS v1.0 §8.5 "Public Interface").

        Halaman publik boleh memakai komposisi visual yang lebih kuat daripada
        halaman transaksi: hero besar, permukaan kaca, gradien dari tema. Yang
        dijaga: hierarki jelas, satu CTA utama, dan kontras teks tetap cukup
        (§12.9 — kaca tidak boleh mengorbankan keterbacaan).
    --}}
    <section class="gm-glass-strong rounded-2xl px-6 py-10 md:px-10 md:py-14">
        <div class="max-w-3xl">
            <p class="gm-label uppercase tracking-wide text-gomad-green">Mad Trans · Mad Warung</p>
            <h1 class="mt-3 text-4xl font-extrabold tracking-tight text-gomad-green-deep md:text-5xl md:leading-[1.05]">
                Pergi jauh, belanja dekat — satu akun untuk keduanya.
            </h1>
            <p class="mt-4 max-w-2xl text-lg text-gray-600">
                Cari jadwal travel, pesan kursi, lalu tunjukkan e-ticket ke supir. Untuk warung:
                belanja grosir dengan plafon yang disetujui pemilik.
            </p>
        </div>

        <form method="get" action="{{ route('public.search') }}" class="mt-8 grid gap-3 md:grid-cols-12">
            <label class="md:col-span-4">
                <span class="text-sm font-semibold text-black-900">Dari</span>
                <input type="text" name="origin_city" value="{{ old('origin_city') }}" required
                       placeholder="mis. Jakarta"
                       class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm">
            </label>

            <label class="md:col-span-4">
                <span class="text-sm font-semibold text-black-900">Ke</span>
                <input type="text" name="destination_city" value="{{ old('destination_city') }}" required
                       placeholder="mis. Bandung"
                       class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm">
            </label>

            <label class="md:col-span-2">
                <span class="text-sm font-semibold text-black-900">Tanggal</span>
                <input type="date" name="departure_date" required
                       value="{{ old('departure_date', today()->addDay()->toDateString()) }}"
                       min="{{ today()->toDateString() }}"
                       class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm">
            </label>

            <label class="md:col-span-1">
                <span class="text-sm font-semibold text-black-900">Orang</span>
                <input type="number" name="passengers" min="1" max="10" value="{{ old('passengers', 1) }}"
                       class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm">
            </label>

            <div class="md:col-span-1 md:self-end">
                <x-gm.button type="submit" class="w-full md:w-auto" variant="brand">Cari</x-gm.button>
            </div>
        </form>

        <p class="mt-3 text-xs text-gray-500">
            Tanpa akun pun bisa melihat jadwal dan harga. Akun dibutuhkan saat memilih kursi.
        </p>
    </section>

    <div class="mt-6 grid gap-4 md:grid-cols-3">
        <x-gm.stat label="Agency aktif" :value="$agencyCount" hint="Travel & rental terverifikasi" />
        <x-gm.stat label="Jadwal terbit" :value="$scheduleCount" hint="Siap dipesan hari ini dan setelahnya" />
        <x-gm.stat label="Outlet warung" :value="$warungCount" hint="Warung & grosir di jaringan" />
    </div>

    {{-- Rute yang sering dicari, diambil dari rute aktif — bukan daftar karangan. --}}
    <section class="mt-10">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="gm-h3 text-gomad-green-deep">Rute yang tersedia</h2>
                <p class="mt-1 text-sm text-gray-500">Harga mulai dari, per kursi.</p>
            </div>
            <a href="{{ route('public.partners') }}" class="text-sm font-semibold text-gomad-green hover:underline">
                Lihat semua mitra →
            </a>
        </div>

        @if ($routes->isEmpty())
            <x-gm.empty class="mt-4"
                title="Belum ada rute yang terbit"
                description="Rute muncul di sini setelah agency menerbitkan jadwal. Coba lagi nanti." />
        @else
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($routes as $route)
                    <a href="{{ route('public.search', [
                            'origin_city' => $route->origin_city,
                            'destination_city' => $route->destination_city,
                            'departure_date' => today()->addDay()->toDateString(),
                            'passengers' => 1,
                        ]) }}"
                       class="gm-glass group rounded-xl px-5 py-4 transition-colors duration-150 hover:border-gomad-lime">
                        <p class="text-base font-semibold text-gomad-green-deep">
                            {{ $route->origin_city }} → {{ $route->destination_city }}
                        </p>
                        <p class="mt-1 text-sm text-gray-500">
                            @if ($route->pricing_min_price !== null)
                                Mulai <x-gm.money :amount="$route->pricing_min_price" class="font-semibold text-black-900" />
                            @else
                                Harga menyusul
                            @endif
                        </p>
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    <section class="mt-10 grid gap-4 md:grid-cols-3">
        <x-gm.card title="Kursi jelas, harga terbuka">
            <p class="text-sm text-gray-600">
                Rincian harga per segmen ditampilkan sebelum membayar: tiket, biaya layanan, dan PPN.
            </p>
        </x-gm.card>
        <x-gm.card title="Bayar aman">
            <p class="text-sm text-gray-600">
                Pembayaran lewat Midtrans Snap. Dana penjual baru diteruskan setelah transaksi tercatat.
            </p>
        </x-gm.card>
        <x-gm.card title="Warung tanpa ribet">
            <p class="text-sm text-gray-600">
                Penjaga warung belanja grosir dengan plafon harian; pemilik tetap memegang persetujuan.
            </p>
        </x-gm.card>
    </section>

    {{--
        §8.5 — halaman publik boleh memakai komposisi yang lebih kuat. Blok ini
        satu-satunya permukaan gelap di halaman, sehingga ajakan mitra menjadi
        titik paling berat secara visual.
    --}}
    <section class="mt-10 overflow-hidden rounded-2xl bg-gomad-green-deep px-6 py-8 md:px-10 md:py-10">
        <div class="flex flex-wrap items-center justify-between gap-6">
            <div class="max-w-xl">
                <h2 class="gm-h3 text-white">Punya travel, rental, atau warung?</h2>
                <p class="mt-2 text-sm text-lime-100">Bergabung sebagai mitra, lalu kelola jadwal dan pesanan dari satu dasbor.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('public.partners') }}">
                    <x-gm.button type="button" variant="secondary" class="rounded-pill">Pelajari kemitraan</x-gm.button>
                </a>
                <a href="{{ route('register') }}">
                    <x-gm.button type="button" variant="brand" class="rounded-pill">Daftar gratis</x-gm.button>
                </a>
            </div>
        </div>
    </section>
@endsection
