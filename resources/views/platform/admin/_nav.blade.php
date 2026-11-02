@props(['active'])

{{--
    Sub-navigasi konsol platform (blueprint/04 §8).

    Satu konsol, dua tingkat pekerjaan: ops menjalankan antrean harian, admin
    memegang administrasi. Tab disaring dengan izin yang sama yang menjaga
    rutenya, jadi akun ops tidak pernah melihat pintu yang akan menolaknya.
--}}
@php
    $user = auth()->user();
    $authorizer = app(\App\Core\Access\Application\ActionAuthorizer::class);

    $tabs = [
        ['key' => 'dashboard', 'label' => 'Dasbor', 'route' => 'admin.dashboard', 'permission' => 'ops.view'],
        ['key' => 'withdrawals', 'label' => 'Pencairan', 'route' => 'admin.withdrawals', 'permission' => 'withdrawal.review'],
        ['key' => 'partners', 'label' => 'Verifikasi mitra', 'route' => 'admin.partners', 'permission' => 'business.verify'],
        ['key' => 'reconciliation', 'label' => 'Rekonsiliasi', 'route' => 'admin.reconciliation', 'permission' => 'ops.view'],
        ['key' => 'support', 'label' => 'Dukungan', 'route' => 'admin.support.index', 'permission' => 'ops.view'],
        ['key' => 'documents', 'label' => 'Dokumen sewa', 'route' => 'admin.rental-documents.index', 'permission' => 'ops.view'],
        ['key' => 'settlement', 'label' => 'Settlement', 'route' => 'admin.settlement', 'permission' => 'settlement.execute'],
        ['key' => 'promos', 'label' => 'Promo', 'route' => 'admin.promos.index', 'permission' => 'catalog.publish'],
        ['key' => 'users', 'label' => 'Pengguna & peran', 'route' => 'admin.users', 'permission' => 'permissions.read'],
        ['key' => 'parameters', 'label' => 'Parameter', 'route' => 'admin.parameters', 'permission' => 'permissions.read'],
    ];
@endphp

<div class="mb-6 border-b border-gray-200">
    <nav aria-label="Konsol platform" class="-mb-px gm-scroll-x flex gap-1">
        @foreach ($tabs as $tab)
            @continue($user === null || ! $authorizer->allows($user, $tab['permission']))
            @php $isActive = $active === $tab['key']; @endphp
            <a href="{{ route($tab['route']) }}"
               @if ($isActive) aria-current="page" @endif
               class="shrink-0 border-b-2 px-3 py-2 text-sm font-medium transition-colors
                      {{ $isActive
                            ? 'border-black-900 text-black-900'
                            : 'border-transparent text-gray-600 hover:border-gray-300 hover:text-black-900' }}">
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>
</div>
