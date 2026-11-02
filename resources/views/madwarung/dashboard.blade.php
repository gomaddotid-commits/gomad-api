@extends('layouts.app')

@section('title', 'Dasbor '.$business->name)

@section('content')
    <x-gm.page-header
        eyebrow="Ruang kerja Mad Warung"
        :title="$business->name"
        :subtitle="($isGrosir ? 'Grosir' : 'Warung').' · '.$business->code.' · '.($business->status === 'active' ? 'Aktif' : 'Belum aktif')">
        <x-slot:actions>
            <a href="{{ route('warung.catalog', $business) }}">
                <x-gm.button type="button" variant="brand">
                    {{ $isGrosir ? 'Atur katalog' : 'Pesan stok' }}
                </x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @include('madwarung._nav', ['business' => $business, 'active' => 'dashboard', 'canReport' => $canReport, 'canManageShifts' => $canManageShifts])

    @if ($business->status !== 'active')
        <div class="mb-5">
            <x-gm.alert tone="warning">
                Usaha ini belum aktif. Katalog dan pesanan belum bisa dipakai sampai status aktif.
            </x-gm.alert>
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-gm.stat label="Pesanan hari ini" :value="$ordersToday" hint="Dibuat sejak tengah malam" />
        <x-gm.stat label="Nilai hari ini" :value="\App\Support\Rupiah::format($amountToday)"
            hint="Tanpa pesanan yang batal/ditolak" />
        <x-gm.stat label="Masih berjalan" :value="$openOrders" hint="Belum diterima atau selesai" />
        <x-gm.stat
            :label="$isGrosir ? 'Perlu disiapkan' : 'Menunggu persetujuan'"
            :value="$isGrosir ? $toFulfill : $pendingApproval"
            :tone="$isGrosir ? 'warning' : 'warning'"
            :hint="$isGrosir ? 'Sudah dibayar, belum siap' : 'Penjaga menunggu keputusan Anda'" />
    </div>

    <div class="mt-5 grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-gm.card title="Pesanan terbaru" :padded="false">
                <div class="divide-y divide-gray-100">
                    @forelse ($recentOrders as $order)
                        @php
                            $tone = match ($order->status) {
                                'completed', 'received' => 'success',
                                'pending_approval', 'pending_payment' => 'warning',
                                'rejected', 'cancelled', 'expired' => 'error',
                                default => 'info',
                            };
                        @endphp
                        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-black-900">
                                    {{ $order->code }}
                                    · {{ $isGrosir ? $order->business?->name : $order->wholesaler?->name }}
                                </p>
                                <p class="mt-0.5 text-xs text-gray-600">
                                    {{ $order->creator?->name ?? '—' }}
                                    · {{ $order->items->sum('quantity') }} unit
                                    · {{ $order->created_at->format('d M Y H:i') }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <x-gm.badge :tone="$tone">{{ str_replace('_', ' ', (string) $order->status) }}</x-gm.badge>
                                <x-gm.money :amount="$order->total_amount" />
                            </div>
                        </div>
                    @empty
                        <div class="px-5 py-6 text-sm text-gray-600">
                            Belum ada pesanan. {{ $isGrosir ? 'Pesanan dari warung akan muncul di sini.' : 'Mulai dengan memesan stok dari grosir.' }}
                        </div>
                    @endforelse
                </div>
                <div class="border-t border-gray-200 px-5 py-3">
                    <a href="{{ route('warung.orders.index', $business) }}"
                       class="text-sm font-semibold text-black-900 hover:underline">Lihat semua pesanan →</a>
                </div>
            </x-gm.card>

            @if (! $isGrosir)
                <x-gm.card title="Penjaga mendekati plafon" subtitle="Pemakaian hari ini sudah melewati 80% plafon harian." :padded="false">
                    <div class="divide-y divide-gray-100">
                        @forelse ($guardsNearLimit as $row)
                            <div class="flex items-center justify-between gap-3 px-5 py-3 text-sm">
                                <span class="font-medium text-black-900">{{ $row['name'] }}</span>
                                <span class="text-gray-600">
                                    <x-gm.money :amount="$row['spent']" /> / <x-gm.money :amount="$row['limit']" />
                                </span>
                            </div>
                        @empty
                            <div class="px-5 py-5 text-sm text-gray-600">Semua penjaga masih di bawah plafon.</div>
                        @endforelse
                    </div>
                    <div class="border-t border-gray-200 px-5 py-3">
                        <a href="{{ route('warung.guards', $business) }}"
                           class="text-sm font-semibold text-black-900 hover:underline">Atur plafon &amp; approval →</a>
                    </div>
                </x-gm.card>
            @endif
        </div>

        <aside class="space-y-5">
            @if ($activeShift !== null)
                <x-gm.card title="Shift Anda aktif">
                    <p class="text-sm text-gray-600">
                        Dimulai {{ $activeShift->started_at?->format('d M Y H:i') ?? $activeShift->scheduled_start_at?->format('d M Y H:i') }}
                    </p>
                    <form method="post" action="{{ route('warung.shifts.clock-out', $activeShift) }}" class="mt-3">
                        @csrf
                        <x-gm.button type="submit" variant="secondary" class="w-full">Akhiri shift</x-gm.button>
                    </form>
                </x-gm.card>
            @elseif (! $isGrosir)
                <x-gm.card title="Shift">
                    <p class="text-sm text-gray-600">
                        Tidak ada shift aktif atas nama Anda. Penjaga memulai shift sebelum berbelanja.
                    </p>
                    <a href="{{ route('warung.shifts', $business) }}" class="mt-3 block">
                        <x-gm.button type="button" variant="secondary" class="w-full">Lihat jadwal shift</x-gm.button>
                    </a>
                </x-gm.card>
            @endif

            @if ($report !== null)
                <x-gm.card title="Ringkasan pesanan">
                    <dl class="space-y-2 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-gray-600">Total pesanan</dt>
                            <dd class="font-semibold text-black-900">{{ $report['order_count'] }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-gray-600">Nilai pesanan</dt>
                            <dd><x-gm.money :amount="$report['ordered_amount_total']" /></dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-gray-600">Sudah dibayar</dt>
                            <dd class="font-semibold text-success"><x-gm.money :amount="$report['paid_amount_total']" /></dd>
                        </div>
                    </dl>
                    <a href="{{ route('warung.report', $business) }}" class="mt-3 block">
                        <x-gm.button type="button" variant="secondary" class="w-full">Buka laporan</x-gm.button>
                    </a>
                </x-gm.card>
            @endif
        </aside>
    </div>
@endsection
