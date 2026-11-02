@extends('layouts.app')

@section('title', 'Laporan '.$business->name)

@section('content')
    <x-gm.page-header
        eyebrow="Ruang kerja agency"
        title="Laporan & bagi hasil"
        :subtitle="$business->name.' · 14 hari terakhir'">
        <x-slot:actions>
            <a href="{{ route('mad-trans.agency.bookings', $business) }}">
                <x-gm.button type="button" variant="secondary">Daftar pesanan</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @include('madtrans.travel._nav', ['business' => $business, 'active' => 'reports'])

    @php
        $totals = (array) ($settlement['totals'] ?? []);
        $dailyMax = max(1, (float) $daily->max('gross'));
    @endphp

    <div class="mb-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-gm.stat label="Penjualan kotor" :value="'Rp '.number_format((float) ($totals['price'] ?? 0), 0, ',', '.')" tone="success" />
        <x-gm.stat label="Bagi hasil platform" :value="'Rp '.number_format((float) ($totals['fee'] ?? 0), 0, ',', '.')" hint="Komisi sesuai kontrak" />
        <x-gm.stat label="PPN" :value="'Rp '.number_format((float) ($totals['ppn'] ?? 0), 0, ',', '.')" />
        <x-gm.stat label="Pendapatan bersih" :value="'Rp '.number_format((float) ($totals['net'] ?? 0), 0, ',', '.')" tone="success" />
    </div>

    <div class="grid gap-5 lg:grid-cols-2">
        <x-gm.card title="Penjualan harian" subtitle="Pesanan berbayar per hari." :padded="false">
            <div class="divide-y divide-gray-100">
                @forelse ($daily as $row)
                    @php $width = max(2, (int) round(((float) $row->gross / $dailyMax) * 100)); @endphp
                    <div class="px-5 py-2.5">
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-600">{{ \Illuminate\Support\Carbon::parse($row->day)->format('d M') }}</span>
                            <span class="font-semibold text-black-900"><x-gm.money :amount="$row->gross" /></span>
                        </div>
                        <div class="mt-1 flex items-center gap-2">
                            <div class="h-1.5 flex-1 overflow-hidden rounded-pill bg-gray-100">
                                <div class="h-full rounded-pill bg-gomad-green" style="width: {{ $width }}%"></div>
                            </div>
                            <span class="w-14 text-right text-xs text-gray-500">{{ $row->bookings }} pms</span>
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-6 text-sm text-gray-600">Belum ada penjualan dalam 14 hari terakhir.</div>
                @endforelse
            </div>
        </x-gm.card>

        <x-gm.card title="Performa per rute" :padded="false">
            <div class="divide-y divide-gray-100">
                @forelse ($perRoute as $row)
                    <div class="flex items-center justify-between px-5 py-3 text-sm">
                        <div>
                            <p class="font-semibold text-black-900">{{ $row->origin_city }} → {{ $row->destination_city }}</p>
                            <p class="text-xs text-gray-600">{{ $row->bookings }} pesanan</p>
                        </div>
                        <x-gm.money :amount="$row->gross" />
                    </div>
                @empty
                    <div class="px-5 py-6 text-sm text-gray-600">Belum ada rute dengan penjualan.</div>
                @endforelse
            </div>
        </x-gm.card>

        <x-gm.card title="Rincian settlement" subtitle="Per transaksi lunas yang sudah dibagi hasil." :padded="false">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                            <th class="px-5 py-2">Transaksi</th>
                            <th class="px-5 py-2 text-right">Harga</th>
                            <th class="px-5 py-2 text-right">Komisi</th>
                            <th class="px-5 py-2 text-right">PPN</th>
                            <th class="px-5 py-2 text-right">Diskon</th>
                            <th class="px-5 py-2 text-right">Bersih</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse (($settlement['rows'] ?? []) as $row)
                            <tr>
                                <td class="px-5 py-2.5">
                                    <p class="font-medium text-black-900">{{ data_get($row, 'code') }}</p>
                                    <p class="text-xs text-gray-600">
                                        {{ data_get($row, 'kind') }}
                                        @if (data_get($row, 'date'))
                                            · {{ \Illuminate\Support\Carbon::parse(data_get($row, 'date'))->format('d M Y H:i') }}
                                        @endif
                                    </p>
                                </td>
                                <td class="whitespace-nowrap px-5 py-2.5 text-right"><x-gm.money :amount="data_get($row, 'price', 0)" /></td>
                                <td class="whitespace-nowrap px-5 py-2.5 text-right text-gray-600"><x-gm.money :amount="data_get($row, 'fee', 0)" /></td>
                                <td class="whitespace-nowrap px-5 py-2.5 text-right text-gray-600"><x-gm.money :amount="data_get($row, 'ppn', 0)" /></td>
                                <td class="whitespace-nowrap px-5 py-2.5 text-right text-gray-600"><x-gm.money :amount="data_get($row, 'discount', 0)" /></td>
                                <td class="whitespace-nowrap px-5 py-2.5 text-right font-semibold text-black-900"><x-gm.money :amount="data_get($row, 'net', 0)" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-6 text-center text-gray-600">Belum ada jadwal yang dibagi hasil.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-gm.card>

        <x-gm.card title="Diskon & promo" :subtitle="'Total diskon Rp '.number_format((float) $promoDiscountTotal, 0, ',', '.')" :padded="false">
            <div class="divide-y divide-gray-100">
                @forelse ($promoRedemptions as $redemption)
                    <div class="flex items-center justify-between px-5 py-3 text-sm">
                        <div>
                            <p class="font-semibold text-black-900">{{ $redemption->promo?->code ?? '—' }}</p>
                            <p class="text-xs text-gray-600">
                                {{ $redemption->created_at->format('d M Y H:i') }}
                                · ditanggung {{ $redemption->cost_bearer }}
                            </p>
                        </div>
                        <x-gm.money :amount="$redemption->discount_amount" />
                    </div>
                @empty
                    <div class="px-5 py-6 text-sm text-gray-600">Belum ada promo yang terpakai.</div>
                @endforelse
            </div>
            <p class="border-t border-gray-100 px-5 py-3 text-xs text-gray-500">
                Ditanggung platform: Rp {{ number_format((float) $promoBorneByPlatform, 0, ',', '.') }}
            </p>
        </x-gm.card>
    </div>
@endsection
