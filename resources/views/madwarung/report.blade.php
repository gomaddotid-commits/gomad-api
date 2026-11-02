@extends('layouts.app')

@section('title', 'Laporan '.$business->name)

@section('content')
    <x-gm.page-header
        eyebrow="Ruang kerja Mad Warung"
        title="Laporan pesanan"
        :subtitle="$business->name.' · '.($isGrosir ? 'grosir' : 'warung')">
        <x-slot:actions>
            <a href="{{ route('warung.orders.index', $business) }}">
                <x-gm.button type="button" variant="secondary">Daftar pesanan</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @include('madwarung._nav', ['business' => $business, 'active' => 'report', 'canReport' => true])

    <div class="mb-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-gm.stat label="Total pesanan" :value="$report['order_count']" hint="Semua status" />
        <x-gm.stat label="Nilai pesanan" :value="\App\Support\Rupiah::format($report['ordered_amount_total'])" />
        <x-gm.stat label="Pesanan dibayar" :value="$report['paid_order_count']" tone="success" />
        <x-gm.stat label="Nilai dibayar" :value="\App\Support\Rupiah::format($report['paid_amount_total'])" tone="success" />
    </div>

    <div class="grid gap-5 lg:grid-cols-2">
        <x-gm.card title="Per status" :padded="false">
            <div class="divide-y divide-gray-100">
                @forelse ($report['by_status'] as $row)
                    @php
                        $statusLabel = match ($row['status']) {
                            'pending_approval' => 'Menunggu persetujuan',
                            'pending_payment' => 'Menunggu bayar',
                            'paid' => 'Dibayar',
                            'preparing' => 'Disiapkan',
                            'ready' => 'Siap',
                            'in_delivery' => 'Dikirim',
                            'ready_for_pickup' => 'Siap diambil',
                            'received' => 'Diterima',
                            'completed' => 'Selesai',
                            'rejected' => 'Ditolak',
                            'cancelled' => 'Dibatalkan',
                            'expired' => 'Kedaluwarsa',
                            default => ucfirst(str_replace('_', ' ', (string) $row['status'])),
                        };
                    @endphp
                    <div class="flex items-center justify-between gap-3 px-5 py-2.5 text-sm">
                        <span class="text-gray-600">{{ $statusLabel }}</span>
                        <span class="flex items-center gap-3">
                            <span class="text-xs text-gray-500">{{ $row['order_count'] }} pesanan</span>
                            <x-gm.money :amount="$row['order_amount']" />
                        </span>
                    </div>
                @empty
                    <div class="px-5 py-6 text-sm text-gray-600">Belum ada pesanan.</div>
                @endforelse
            </div>
        </x-gm.card>

        <x-gm.card :title="$isGrosir ? 'Warung teratas' : 'Grosir teratas'" :padded="false">
            <div class="divide-y divide-gray-100">
                @forelse ($report['by_counterparty'] as $row)
                    <div class="flex items-center justify-between gap-3 px-5 py-2.5 text-sm">
                        <span class="font-medium text-black-900">{{ $row['business_name'] }}</span>
                        <span class="flex items-center gap-3">
                            <span class="text-xs text-gray-500">{{ $row['order_count'] }} pesanan</span>
                            <x-gm.money :amount="$row['order_amount']" />
                        </span>
                    </div>
                @empty
                    <div class="px-5 py-6 text-sm text-gray-600">Belum ada transaksi dengan mitra.</div>
                @endforelse
            </div>
        </x-gm.card>

        <x-gm.card title="Produk terlaris" :padded="false" class="lg:col-span-2">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                            <th class="px-5 py-2">Produk</th>
                            <th class="px-5 py-2">SKU</th>
                            <th class="px-5 py-2 text-right">Jumlah</th>
                            <th class="px-5 py-2 text-right">Nilai</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($report['top_products'] as $row)
                            <tr>
                                <td class="px-5 py-2.5 font-medium text-black-900">{{ $row['name'] }}</td>
                                <td class="px-5 py-2.5 text-gray-600">{{ $row['sku'] }}</td>
                                <td class="px-5 py-2.5 text-right text-gray-600">{{ $row['quantity'] }} {{ $row['unit'] }}</td>
                                <td class="px-5 py-2.5 text-right"><x-gm.money :amount="$row['item_amount']" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-6 text-center text-gray-600">Belum ada produk terjual.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-gm.card>
    </div>
@endsection
