@extends('layouts.app')

@section('title', 'Pesanan saya')

@section('content')
    <x-gm.page-header
        eyebrow="Mad Trans"
        title="Pesanan saya"
        subtitle="Semua pemesanan tiket Anda, terbaru di atas.">
        <x-slot:actions>
            <a href="{{ route('mad-trans.index') }}">
                <x-gm.button type="button" variant="brand">Cari jadwal baru</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @if ($bookings->isEmpty())
        <x-gm.empty
            title="Belum ada pesanan"
            description="Setelah memesan, e-ticket dan status pembayaran muncul di sini.">
            <x-slot:action>
                <a href="{{ route('mad-trans.index') }}">
                    <x-gm.button type="button" variant="secondary">Cari jadwal</x-gm.button>
                </a>
            </x-slot:action>
        </x-gm.empty>
    @else
        <div class="space-y-3">
            @foreach ($bookings as $booking)
                @php
                    $tone = match ($booking->status) {
                        'paid', 'confirmed' => 'success',
                        'pending' => 'warning',
                        'cancelled' => 'error',
                        'on_going' => 'info',
                        default => 'neutral',
                    };
                @endphp

                <div class="gm-glass rounded-xl px-5 py-4 transition-colors duration-150 hover:border-gomad-lime">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-base font-semibold text-gomad-green-deep">
                                {{ $booking->schedule->route->origin_city }} → {{ $booking->schedule->route->destination_city }}
                            </p>
                            <p class="mt-1 text-sm text-gray-500">
                                {{ $booking->schedule->departure_date->format('d M Y') }}
                                {{ substr((string) $booking->schedule->departure_time, 0, 5) }}
                                · {{ $booking->schedule->business->name }}
                                · {{ $booking->passengers->count() }} penumpang
                            </p>
                            <p class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                <x-gm.badge :tone="$tone">{{ ucfirst(str_replace('_', ' ', (string) $booking->status)) }}</x-gm.badge>
                                <x-gm.badge tone="neutral">{{ $booking->payment_method === 'cod' ? 'COD' : 'Online' }}</x-gm.badge>
                                <span class="font-mono text-gray-500">{{ $booking->code }}</span>
                            </p>
                        </div>

                        <div class="text-right">
                            <p class="text-xl font-bold tracking-tight text-gomad-green-deep">
                                <x-gm.money :amount="$booking->total_price" />
                            </p>

                            <div class="mt-3 flex flex-wrap justify-end gap-2">
                                <a href="{{ route('mad-trans.bookings.show', $booking) }}">
                                    <x-gm.button type="button" size="sm" variant="secondary" class="rounded-pill">E-ticket</x-gm.button>
                                </a>

                                @if ($booking->status === 'pending' && $booking->payment_method === 'midtrans')
                                    <form method="post" action="{{ route('mad-trans.bookings.pay', $booking) }}">
                                        @csrf
                                        <x-gm.button type="submit" size="sm" variant="brand" class="rounded-pill">Bayar</x-gm.button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-6">{{ $bookings->links() }}</div>
    @endif
@endsection
