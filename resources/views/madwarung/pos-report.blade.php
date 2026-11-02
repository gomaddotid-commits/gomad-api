@extends('layouts.app')

@section('title', 'Penjualan kasir '.$business->name)

@section('content')
    {{--
        Pemantauan kasir untuk pemilik (DS v1.0 §7.4).

        Pemilik tidak menjalankan kasir — izin `pos.sell` hanya dipegang penjaga.
        Halaman ini karena itu **hanya membaca**: apa yang terjual, oleh siapa,
        berapa uangnya. Rincian barang ada di tiap baris, bukan di halaman lain,
        supaya pemilik tidak perlu mengeklik untuk menjawab "tadi jualan apa".
    --}}
    <x-gm.page-header
        eyebrow="Kasir warung"
        title="Penjualan kasir"
        :subtitle="$business->name.' · penjualan langsung di meja warung'">
        <x-slot:actions>
            <a href="{{ route('warung.show', $business) }}">
                <x-gm.button type="button" variant="secondary">Kembali ke dasbor</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @include('madwarung._nav', ['business' => $business, 'active' => 'pos-report', 'canReport' => true])

    <div class="grid gap-4 sm:grid-cols-3">
        <x-gm.stat label="Penjualan hari ini" :value="\App\Support\Rupiah::format($todayTotals['amount'])" hint="Uang tunai diterima hari ini" tone="brand" />
        <x-gm.stat label="Transaksi hari ini" :value="$todayTotals['count']" hint="Jumlah nota kasir" />
        <x-gm.stat label="Barang dijual" :value="$listingCount" hint="Produk aktif di kasir" />
    </div>

    <div class="mt-5">
        @if ($sales->isEmpty())
            <x-gm.empty
                title="Belum ada penjualan kasir"
                description="Nota yang dibuat penjaga di halaman Kasir akan muncul di sini." />
        @else
            <x-gm.card title="Riwayat nota" :padded="false">
                <div class="divide-y divide-gray-100">
                    @foreach ($sales as $sale)
                        <div class="px-5 py-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-mono text-sm font-semibold text-black-900">{{ $sale->code }}</p>
                                    <p class="mt-0.5 text-xs text-gray-500">
                                        {{ $sale->created_at->format('d M Y H:i') }}
                                        · {{ $sale->seller?->name ?? '—' }}
                                        · {{ $sale->items->sum('quantity') }} barang
                                        @if ($sale->shift_id !== null)
                                            · dalam shift
                                        @endif
                                    </p>
                                </div>

                                <div class="text-right">
                                    <p class="text-base font-bold text-black-900">
                                        <x-gm.money :amount="$sale->total_amount" />
                                    </p>
                                    @if ((float) $sale->change_amount > 0)
                                        <p class="text-xs text-gray-500">
                                            tunai <x-gm.money :amount="$sale->paid_amount" />
                                            · kembali <x-gm.money :amount="$sale->change_amount" />
                                        </p>
                                    @endif
                                </div>
                            </div>

                            <p class="mt-2 flex flex-wrap gap-1.5 text-xs">
                                @foreach ($sale->items as $item)
                                    <span class="rounded-sm bg-gray-100 px-2 py-0.5 text-gray-600">
                                        {{ $item->product_name }} × {{ $item->quantity }}
                                    </span>
                                @endforeach
                            </p>

                            @if ($sale->note !== null)
                                <p class="mt-2 text-xs italic text-gray-500">{{ $sale->note }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-gm.card>

            <div class="mt-4">{{ $sales->links() }}</div>
        @endif
    </div>
@endsection
