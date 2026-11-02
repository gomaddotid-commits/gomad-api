@props(['business', 'active', 'canManageShifts' => false, 'canReport' => false])

{{--
    Sub-navigasi ruang kerja Mad Warung. Warung dan grosir memakai tab yang
    berbeda karena pekerjaannya berbeda: warung membeli stok, grosir menjual.

    Tab disaring dengan izin, bukan dengan peran yang ditebak: penjaga tidak
    melihat pintu pemilik (penjaga & plafon, laporan), dan staf grosir tidak
    melihat tab yang memang bukan pekerjaannya.
--}}
@php
    $user = auth()->user();
    $authorizer = app(\App\Core\Access\Application\ActionAuthorizer::class);
    $isGrosir = $business->type === 'grosir';

    $canGuards = $authorizer->allows($user, 'member_limit.manage', $business);
    $canShifts = $authorizer->allows($user, 'shift.manage', $business)
        || $authorizer->allows($user, 'shift.self', $business);
    // Kasir: penjaga menjalankan, pemilik memantau. Dua izin, dua tab berbeda.
    $canSell = $authorizer->allows($user, 'pos.sell', $business);
    $canSeeSales = $authorizer->allows($user, 'pos.report', $business);

    $tabs = [
        ['key' => 'dashboard', 'label' => 'Dasbor', 'route' => 'warung.show'],
    ];

    if ($canSell) {
        $tabs[] = ['key' => 'pos', 'label' => 'Kasir', 'route' => 'warung.pos'];
    }

    if ($canSeeSales) {
        $tabs[] = ['key' => 'pos-report', 'label' => 'Penjualan', 'route' => 'warung.pos.report'];
    }

    $tabs[] = ['key' => 'catalog', 'label' => $isGrosir ? 'Katalog & harga' : 'Pesan stok', 'route' => 'warung.catalog'];
    $tabs[] = ['key' => 'orders', 'label' => $isGrosir ? 'Pesanan masuk' : 'Pesanan', 'route' => 'warung.orders.index'];

    if (! $isGrosir && $canGuards) {
        $tabs[] = ['key' => 'guards', 'label' => 'Penjaga & plafon', 'route' => 'warung.guards'];
    }

    if (! $isGrosir && $canShifts) {
        $tabs[] = ['key' => 'shifts', 'label' => 'Shift', 'route' => 'warung.shifts'];
    }

    if ($canReport) {
        $tabs[] = ['key' => 'report', 'label' => 'Laporan', 'route' => 'warung.report'];
    }
@endphp

<div class="mb-6 border-b border-gray-200">
    <nav aria-label="Ruang kerja Mad Warung" class="-mb-px gm-scroll-x flex gap-1">
        @foreach ($tabs as $tab)
            @php $isActive = $active === $tab['key']; @endphp
            <a href="{{ route($tab['route'], $business) }}"
               @if ($isActive) aria-current="page" @endif
               class="shrink-0 border-b-2 px-3 py-2 text-sm font-medium transition-colors
                      {{ $isActive
                            ? 'border-gomad-yellow text-black-900'
                            : 'border-transparent text-gray-600 hover:border-gray-300 hover:text-black-900' }}">
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>
</div>
