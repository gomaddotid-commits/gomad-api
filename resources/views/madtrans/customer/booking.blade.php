@extends('layouts.app')

@section('title', 'E-Ticket '.$booking->code)

@section('content')
    @php
        $tone = match ($booking->status) {
            'paid', 'confirmed' => 'success',
            'pending' => 'warning',
            'cancelled' => 'error',
            'on_going' => 'info',
            default => 'neutral',
        };
    @endphp

    <x-gm.page-header
        eyebrow="E-Ticket"
        :title="$booking->code"
        :subtitle="$booking->schedule->route->origin_city.' → '.$booking->schedule->route->destination_city">
        <x-slot:actions>
            <x-gm.badge :tone="$tone">{{ ucfirst(str_replace('_', ' ', (string) $booking->status)) }}</x-gm.badge>
        </x-slot:actions>
    </x-gm.page-header>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-gm.card title="Perjalanan">
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Keberangkatan</dt>
                        <dd class="mt-1 text-base font-semibold text-black-900">
                            {{ $booking->schedule->departure_date->toDateString() }}
                            {{ substr((string) $booking->schedule->departure_time, 0, 5) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Armada</dt>
                        <dd class="mt-1 text-base font-semibold text-black-900">{{ $booking->schedule->vehicle->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Naik di</dt>
                        <dd class="mt-1 text-sm text-black-900">{{ $booking->originStop?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Turun di</dt>
                        <dd class="mt-1 text-sm text-black-900">{{ $booking->destinationStop?->name ?? '—' }}</dd>
                    </div>

                    {{--
                        Titik jemput hanya muncul bila pelanggan memang memintanya.
                        Biaya jemput **tidak** ikut basis biaya layanan/PPN (§9a J6),
                        jadi ia ditulis terpisah, bukan dilebur ke harga tiket.
                    --}}
                    @if ($booking->pickup_address !== null || (float) $booking->pickup_fee > 0)
                        <div class="sm:col-span-2 rounded-md bg-lime-50 px-3 py-2.5">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gomad-green">
                                Jemput di luar titik kumpul
                            </dt>
                            <dd class="mt-1 text-sm text-black-900">
                                {{ $booking->pickup_address ?: 'Titik dari peta' }}

                                @if ($booking->pickup_distance_km !== null)
                                    <span class="text-gray-500">
                                        · {{ rtrim(rtrim(number_format((float) $booking->pickup_distance_km, 2, ',', '.'), '0'), ',') }} km
                                    </span>
                                @endif

                                @if ((float) $booking->pickup_fee > 0)
                                    <span class="font-semibold">
                                        · biaya <x-gm.money :amount="$booking->pickup_fee" />
                                    </span>
                                @else
                                    <span class="text-success-600">· gratis (dalam radius)</span>
                                @endif
                            </dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Agency</dt>
                        <dd class="mt-1 text-sm text-black-900">{{ $booking->schedule->business->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Pembayaran</dt>
                        <dd class="mt-1">
                            <x-gm.badge tone="neutral">{{ $booking->payment_method === 'cod' ? 'Tunai ke supir (COD)' : 'Online (Midtrans)' }}</x-gm.badge>
                        </dd>
                    </div>
                </dl>
            </x-gm.card>

            <x-gm.card title="Penumpang">
                <ul class="divide-y divide-gray-200">
                    @foreach ($booking->passengers as $passenger)
                        @php $boarded = $passenger->boarded_at !== null; @endphp
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                            <span class="font-semibold text-black-900">{{ $passenger->name }}</span>
                            <span class="flex items-center gap-2">
                                <x-gm.badge tone="neutral">Kursi {{ $passenger->seat_snapshot ?? '—' }}</x-gm.badge>
                                @if ($boarded)
                                    <x-gm.badge tone="success">Sudah naik</x-gm.badge>
                                @elseif ($passenger->no_show_at !== null)
                                    <x-gm.badge tone="error">Tidak hadir</x-gm.badge>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </x-gm.card>

            <x-gm.card title="Rincian biaya">
                <dl class="space-y-2 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-600">Harga tiket</dt>
                        <dd><x-gm.money :amount="$booking->base_price" /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-600">Biaya layanan</dt>
                        <dd><x-gm.money :amount="$booking->service_fee" /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-600">Biaya platform</dt>
                        <dd><x-gm.money :amount="$booking->platform_fee" /></dd>
                    </div>
                    @if ((float) $booking->pickup_fee > 0)
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-gray-600">Biaya jemput</dt>
                            <dd><x-gm.money :amount="$booking->pickup_fee" /></dd>
                        </div>
                    @endif
                    @if ((float) $booking->discount_amount > 0)
                        <div class="flex items-center justify-between gap-3 text-success-600">
                            <dt>
                                Diskon {{ $booking->promo_code }}
                                {{ $booking->discount_bearer === 'platform' ? '(ditanggung GoMad)' : '' }}
                            </dt>
                            <dd>−<x-gm.money :amount="$booking->discount_amount" /></dd>
                        </div>
                    @endif
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-600">PPN</dt>
                        <dd><x-gm.money :amount="$booking->ppn_amount" /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 border-t border-gray-200 pt-2 text-base font-bold">
                        <dt>Total</dt>
                        <dd><x-gm.money :amount="$booking->total_price" /></dd>
                    </div>
                </dl>
            </x-gm.card>
        </div>

        <aside class="space-y-5">
            {{--
                E-ticket: satu-satunya hal yang diperiksa saat naik adalah kode dan
                nama penumpang (§8.5 "e-ticket"). Karena itu kode ditaruh paling atas
                di kolom samping, bukan di dasar halaman.
            --}}
            <x-gm.card>
                <p class="gm-label uppercase tracking-wide text-gomad-green">Tunjukkan ke supir</p>
                <p class="mt-2 rounded-lg border border-dashed border-gomad-lime bg-lime-50 px-3 py-4 text-center font-mono text-xl font-extrabold tracking-[0.2em] text-gomad-green-deep">
                    {{ $booking->code }}
                </p>
                <p class="mt-2 text-xs text-gray-500">
                    Kode dan nama penumpang diperiksa saat naik. Cukup dari ponsel.
                </p>
            </x-gm.card>

            @if ($booking->status === 'pending' && $booking->payment_method === 'midtrans')
                <x-gm.card title="Selesaikan pembayaran">
                    <p class="text-sm text-gray-600">
                        Kursi ditahan sampai batas waktu pembayaran. Setelah dibayar, e-ticket aktif otomatis.
                    </p>

                    <form method="post" action="{{ route('mad-trans.bookings.pay', $booking) }}" class="mt-3">
                        @csrf
                        <x-gm.button type="submit" variant="brand" class="w-full">Bayar online sekarang</x-gm.button>
                    </form>
                </x-gm.card>
            @endif

            <x-gm.card title="Tindakan">
                @if ($cancellation->allowed && $booking->status !== 'cancelled')
                    <a href="{{ route('mad-trans.bookings.cancel-form', $booking) }}" class="block">
                        <x-gm.button type="button" variant="secondary" class="w-full">Batalkan pesanan</x-gm.button>
                    </a>
                    <p class="mt-2 text-xs text-gray-500">{{ $cancellation->reason }}</p>
                @else
                    <p class="text-sm text-gray-500">{{ $cancellation->reason }}</p>
                @endif

                <a href="{{ route('mad-trans.bookings.index') }}" class="mt-3 block">
                    <x-gm.button type="button" variant="ghost" class="w-full">← Semua pesanan</x-gm.button>
                </a>
            </x-gm.card>
        </aside>
    </div>
@endsection
