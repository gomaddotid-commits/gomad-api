@php
    /*
    | Tema ditentukan oleh **produk dan peran**, bukan oleh halaman (DS v1.0
    | §16.2). Sumbernya satu: App\Support\DesignTheme. Halaman publik selalu
    | consumer; sisanya mengikuti peran akun.
    |
    | Tema bukan mekanisme keamanan (§14.3) — otorisasi tetap di middleware `area:`.
    */
    $layoutUser = auth()->user();
    $layoutAccountType = $layoutUser?->accountType();
    $layoutTheme = \App\Support\DesignTheme::for($layoutUser, request()->route()?->getName());
    $layoutIsConsumer = $layoutTheme->allowsGlass();

    $layoutNavigation = app(\App\Support\Navigation::class);
    $layoutPrimary = $layoutNavigation->primary($layoutUser);
    $layoutAccount = $layoutUser === null ? [] : $layoutNavigation->account($layoutUser);
@endphp
<!doctype html>
<html lang="id" class="h-full" data-theme="{{ $layoutTheme->value }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Sebagian view menuliskan merek di judulnya, sebagian tidak; menambah
         akhiran di sini akan menghasilkan "… — GoMad — GoMad". --}}
    <title>@yield('title', 'GoMad — mad warung, mad trans')</title>

    {{-- DS v1.0 §5.1 — Inter adalah typeface utama seluruh produk. --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800" rel="stylesheet">

    {{-- Aset dipasang hanya bila build/dev server tersedia, supaya halaman tidak gagal di lingkungan tanpa Node. --}}
    @if (file_exists(public_path('hot')) || file_exists(public_path('build/manifest.json')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="min-h-full">
    {{-- §12.3/§12.7 — pengguna keyboard tidak perlu melewati navigasi untuk sampai ke isi. --}}
    <a href="#main"
       class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-black-900 focus:px-4 focus:py-2 focus:text-white">
        Lewati ke konten
    </a>

    {{--
        Dua kerangka, satu layout (§13.2 "theme separation").

        Semua halaman memakai `layouts.app`, jadi memilih kerangka di sini membuat
        seluruh aplikasi ikut bertema tanpa menyentuh 66 view — dan membuat mustahil
        ada halaman operasional yang diam-diam memakai kaca (§16.3).
    --}}
    @if ($layoutIsConsumer)
        @include('layouts.partials.consumer-shell')
    @else
        @include('layouts.partials.operational-shell')
    @endif
</body>
</html>
