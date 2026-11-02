@props(['business', 'active'])

{{-- Sub-navigasi ruang kerja agency: satu tab per pekerjaan (blueprint/04 §7). --}}
@php
    $tabs = [
        ['key' => 'dashboard', 'label' => 'Dasbor', 'route' => 'mad-trans.agency.show'],
        ['key' => 'fleet', 'label' => 'Armada', 'route' => 'mad-trans.agency.fleet'],
        ['key' => 'routes', 'label' => 'Rute', 'route' => 'mad-trans.agency.routes'],
        ['key' => 'schedules', 'label' => 'Jadwal', 'route' => 'mad-trans.agency.schedules'],
        ['key' => 'drivers', 'label' => 'Pengemudi', 'route' => 'mad-trans.agency.drivers'],
        ['key' => 'bookings', 'label' => 'Pesanan', 'route' => 'mad-trans.agency.bookings'],
        ['key' => 'tracking', 'label' => 'Pelacakan', 'route' => 'mad-trans.agency.tracking'],
        ['key' => 'rental', 'label' => 'Rental', 'route' => 'mad-trans.agency.rental'],
        ['key' => 'wallet', 'label' => 'Dompet', 'route' => 'mad-trans.agency.wallet'],
        ['key' => 'withdrawals', 'label' => 'Pencairan', 'route' => 'mad-trans.agency.withdrawals'],
        ['key' => 'reports', 'label' => 'Laporan', 'route' => 'mad-trans.agency.reports'],
    ];
@endphp

<div class="mb-6 border-b border-gray-200">
    <nav aria-label="Ruang kerja agency" class="-mb-px gm-scroll-x flex gap-1">
        @foreach ($tabs as $tab)
            @php $isActive = $active === $tab['key']; @endphp
            <a href="{{ route($tab['route'], $business) }}"
               @if ($isActive) aria-current="page" @endif
               class="shrink-0 border-b-2 px-3 py-2 text-sm font-medium transition-colors
                      {{ $isActive
                            ? 'border-gomad-green text-black-900'
                            : 'border-transparent text-gray-600 hover:border-gray-300 hover:text-black-900' }}">
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>
</div>
