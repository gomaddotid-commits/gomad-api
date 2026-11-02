@extends('layouts.app')

@section('title', 'Dasbor '.$business->name)

@section('content')
    <x-gm.page-header
        eyebrow="Ruang kerja agency"
        :title="$business->name"
        :subtitle="$business->code.' · '.($business->verified_at !== null ? 'Terverifikasi' : 'Menunggu verifikasi')">
        <x-slot:actions>
            <a href="{{ route('mad-trans.agency.schedules', $business) }}">
                <x-gm.button type="button" variant="brand">Buat jadwal</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @include('madtrans.travel._nav', ['business' => $business, 'active' => 'dashboard'])

    {{--
        Dua kelompok angka, bukan tujuh kartu sekaligus: "hari ini" untuk kerja
        hari ini, "keuangan" untuk yang dibaca saat mengambil keputusan uang
        (UDS §7.4 "high information density" tanpa kehilangan hierarki).
    --}}
    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Hari ini</p>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-gm.stat label="Pesanan hari ini" :value="$bookingsToday" hint="Semua status, sejak tengah malam" />
        <x-gm.stat label="Nilai tiket hari ini" :value="\App\Support\Rupiah::format($revenueToday)" hint="Harga dasar tiket lunas" tone="brand" />
        <x-gm.stat label="Jadwal menunggu" :value="$pendingApproval" hint="Draf yang belum terbit" :tone="$pendingApproval > 0 ? 'warning' : 'neutral'" />
        <x-gm.stat label="Perlu tindakan" :value="$rentalsNeedingAction" hint="Rental menunggu bayar/serah terima" :tone="$rentalsNeedingAction > 0 ? 'warning' : 'neutral'" />
    </div>

    <p class="mb-2 mt-6 text-xs font-semibold uppercase tracking-wide text-gray-500">Keuangan</p>
    <div class="grid gap-4 sm:grid-cols-3">
        <x-gm.stat label="Saldo tersedia" :value="\App\Support\Rupiah::format($availableBalance)" hint="Bisa dicairkan" tone="success" />
        <x-gm.stat label="Jaminan COD" :value="\App\Support\Rupiah::format($collateralBalance)" hint="Ditahan selama COD aktif" tone="warning" />
        <x-gm.stat label="Payable" :value="\App\Support\Rupiah::format($payableBalance)" hint="Belum masuk saldo tersedia" />
    </div>

    <div class="mt-6 grid gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-gm.card title="Jadwal terbaru" subtitle="Urut dari keberangkatan terakhir."
                :padded="false">
                <div class="divide-y divide-gray-100">
                    @forelse ($schedules as $schedule)
                        @php
                            $tone = match ($schedule->status) {
                                'published' => 'success',
                                'boarding', 'departed' => 'info',
                                'draft' => 'warning',
                                'cancelled' => 'error',
                                default => 'neutral',
                            };
                        @endphp
                        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-black-900">
                                    {{ $schedule->route->origin_city }} → {{ $schedule->route->destination_city }}
                                </p>
                                <p class="mt-0.5 text-xs text-gray-500">
                                    {{ $schedule->departure_date->format('d M Y') }}
                                    <span class="font-semibold text-gray-700">{{ substr((string) $schedule->departure_time, 0, 5) }}</span>
                                    · {{ $schedule->vehicle->name }}
                                    · {{ $schedule->driverMembership?->user?->name ?? 'tanpa supir' }}
                                </p>
                            </div>

                            <div class="flex items-center gap-2 text-xs">
                                <x-gm.badge :tone="$tone">{{ ucfirst((string) $schedule->status) }}</x-gm.badge>
                                <span class="text-gray-500">{{ $schedule->bookings_count }} pesanan</span>
                                <a href="{{ route('mad-trans.agency.schedules.show', [$business, $schedule]) }}"
                                   class="font-semibold text-green-700 hover:underline">Detail</a>
                            </div>
                        </div>
                    @empty
                        <div class="px-5 py-6 text-sm text-gray-500">
                            Belum ada jadwal. Buat jadwal pertama dari tab
                            <a href="{{ route('mad-trans.agency.schedules', $business) }}" class="font-semibold text-green-700 hover:underline">Jadwal</a>.
                        </div>
                    @endforelse
                </div>
            </x-gm.card>
        </div>

        <aside class="space-y-5">
            <x-gm.card title="Settlement">
                <dl class="space-y-2 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-500">Nilai transaksi</dt>
                        <dd class="font-semibold"><x-gm.money :amount="$settlement['totals']['price']" /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-500">Biaya layanan &amp; platform</dt>
                        <dd><x-gm.money :amount="$settlement['totals']['fee']" /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-500">PPN</dt>
                        <dd><x-gm.money :amount="$settlement['totals']['ppn']" /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 border-t border-gray-200 pt-2">
                        <dt class="font-bold">Hak agency</dt>
                        <dd class="text-base font-bold text-success-600"><x-gm.money :amount="$settlement['totals']['net']" /></dd>
                    </div>
                </dl>

                <a href="{{ route('mad-trans.agency.reports', $business) }}" class="mt-3 block">
                    <x-gm.button type="button" variant="secondary" class="w-full">Lihat laporan</x-gm.button>
                </a>
            </x-gm.card>

            <x-gm.card title="Pintasan">
                <div class="space-y-2">
                    <a href="{{ route('mad-trans.agency.bookings', $business) }}" class="block">
                        <x-gm.button type="button" variant="ghost" class="w-full justify-start">Pesanan &amp; penumpang</x-gm.button>
                    </a>
                    <a href="{{ route('mad-trans.agency.wallet', $business) }}" class="block">
                        <x-gm.button type="button" variant="ghost" class="w-full justify-start">Dompet &amp; mutasi</x-gm.button>
                    </a>
                    <a href="{{ route('mad-trans.agency.rental', $business) }}" class="block">
                        <x-gm.button type="button" variant="ghost" class="w-full justify-start">Rental &amp; serah terima</x-gm.button>
                    </a>
                    <a href="{{ route('mad-trans.agency.promos.index', $business) }}" class="block">
                        <x-gm.button type="button" variant="ghost" class="w-full justify-start">Promo agency</x-gm.button>
                    </a>
                </div>
            </x-gm.card>
        </aside>
    </div>
@endsection
