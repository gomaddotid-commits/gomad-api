@extends('layouts.app')

@section('title', 'Batalkan '.$booking->code)

@section('content')
    <x-gm.page-header
        eyebrow="Pembatalan"
        :title="'Batalkan '.$booking->code"
        subtitle="Periksa akibatnya dulu. Tidak ada biaya pembatalan yang tersembunyi." />

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-5">
            <x-gm.card title="Pesanan yang akan dibatalkan">
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Rute</dt>
                        <dd class="mt-1 text-base font-semibold text-black-900">
                            {{ $booking->schedule->route->origin_city }} → {{ $booking->schedule->route->destination_city }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Keberangkatan</dt>
                        <dd class="mt-1 text-base font-semibold text-black-900">
                            {{ $booking->schedule->departure_date->toDateString() }}
                            {{ substr((string) $booking->schedule->departure_time, 0, 5) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Agency</dt>
                        <dd class="mt-1 text-base font-semibold text-black-900">{{ $booking->schedule->business->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Total</dt>
                        <dd class="mt-1 text-base font-semibold text-black-900">
                            <x-gm.money :amount="$booking->total_price" />
                        </dd>
                    </div>
                </dl>
            </x-gm.card>

            <x-gm.card title="Yang terjadi setelah dibatalkan">
                @if ($cancellation->allowed)
                    <ul class="space-y-2 text-sm text-gray-600">
                        <li>Kursi {{ $booking->passengers->pluck('seat_snapshot')->filter()->implode(', ') ?: 'yang dipesan' }} dilepas untuk penumpang lain.</li>
                        @if ($cancellation->releasesCodHold)
                            <li>Jaminan kursi COD dikembalikan ke saldo agency — Anda tidak punya tagihan tersisa.</li>
                        @endif
                        @if (! $cancellation->moneyCaptured)
                            <li>Belum ada dana yang ditarik, jadi tidak ada pengembalian dana yang perlu diproses.</li>
                        @endif
                        <li>Pemesanan tidak dapat diaktifkan kembali; bila berubah pikiran, pesan ulang.</li>
                    </ul>
                @else
                    <x-gm.alert tone="warning">{{ $cancellation->reason }}</x-gm.alert>
                @endif
            </x-gm.card>
        </div>

        <aside class="space-y-5">
            <x-gm.card title="Konfirmasi">
                @if ($cancellation->allowed)
                    <form method="post" action="{{ route('mad-trans.bookings.cancel', $booking) }}">
                        @csrf
                        <x-gm.button type="submit" variant="danger" class="w-full">Ya, batalkan pesanan</x-gm.button>
                    </form>

                    <a href="{{ route('mad-trans.bookings.show', $booking) }}" class="mt-3 block">
                        <x-gm.button type="button" variant="secondary" class="w-full">Simpan pesanan</x-gm.button>
                    </a>
                @else
                    <p class="text-sm text-gray-600">
                        Bila ini pembayaran yang sudah terlanjur masuk, hubungi tim operasional agar
                        pengembalian dana tercatat di ledger.
                    </p>

                    <a href="{{ route('mad-trans.bookings.show', $booking) }}" class="mt-3 block">
                        <x-gm.button type="button" variant="secondary" class="w-full">Kembali ke e-ticket</x-gm.button>
                    </a>
                @endif
            </x-gm.card>
        </aside>
    </div>
@endsection
