@extends('layouts.app')

@section('title', 'Beranda | GoMad')

@section('content')
<x-gm.page-header
    title="Halo, {{ auth()->user()->name }}"
    subtitle="Akun {{ $accountType->label() }} — pesan perjalanan, sewa kendaraan, dan belanja kebutuhan warung dari sini." />

<div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <a href="{{ route('mad-trans.index') }}" class="block">
        <x-gm.card title="Cari jadwal travel">
            <p class="muted">Lihat jadwal terbit, harga per segmen, dan kursi yang masih kosong.</p>
        </x-gm.card>
    </a>
    <a href="{{ route('mad-trans.bookings.index') }}" class="block">
        <x-gm.card title="Pesanan saya">
            <p class="muted">Bayar, batalkan, atau pantau perjalanan yang sedang berjalan.</p>
        </x-gm.card>
    </a>
    <a href="{{ route('account.rentals') }}" class="block">
        <x-gm.card title="Sewa kendaraan">
            <p class="muted">Riwayat sewa dan status pengembalian jaminan.</p>
        </x-gm.card>
    </a>
    <a href="{{ route('warung.public.index') }}" class="block">
        <x-gm.card title="Mad Warung">
            <p class="muted">Belanja kebutuhan harian dari warung mitra terdekat.</p>
        </x-gm.card>
    </a>
</div>

<x-gm.card title="Perjalanan terakhir">
    @forelse ($bookings as $booking)
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 py-2 last:border-0">
            <div>
                <p class="font-mono font-semibold text-black-900">{{ $booking->code }}</p>
                <p class="muted">
                    {{ $booking->schedule?->route?->origin_city }} → {{ $booking->schedule?->route?->destination_city }}
                    · {{ $booking->schedule?->departure_date?->translatedFormat('d M Y') }}
                    {{ substr((string) $booking->schedule?->departure_time, 0, 5) }}
                </p>
            </div>
            <a href="{{ route('mad-trans.bookings.show', $booking) }}" class="text-sm font-semibold hover:underline">Buka</a>
        </div>
    @empty
        <x-gm.empty title="Belum ada perjalanan" description="Pesanan travel Anda akan muncul di sini." />
    @endforelse
</x-gm.card>

<x-gm.card title="Sewa terakhir">
    @forelse ($rentals as $rental)
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 py-2 last:border-0">
            <div>
                <p class="font-mono font-semibold text-black-900">{{ $rental->code ?? '#'.$rental->getKey() }}</p>
                <p class="muted">
                    {{ $rental->vehicle?->name }} · {{ $rental->vehicle?->plate_number }}
                    · {{ $rental->status }}
                </p>
            </div>
            <a href="{{ route('account.rentals') }}" class="text-sm font-semibold hover:underline">Lihat</a>
        </div>
    @empty
        <x-gm.empty title="Belum ada sewa" description="Sewa kendaraan yang Anda pesan akan tercatat di sini." />
    @endforelse
</x-gm.card>
@endsection
