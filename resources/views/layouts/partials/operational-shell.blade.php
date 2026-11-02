{{--
    Kerangka CLASSIC MINIMALIST (DS v1.0 §7, §6.3).

    Dipakai admin, ops, seluruh mitra Mad Warung, pemilik/staf agency, dan supir.
    Ciri yang dipegang: permukaan padat, garis tipis, tanpa blur, sidebar
    terstruktur, kepadatan informasi tinggi.

    Variabel dari `layouts.app`: $layoutPrimary, $layoutAccount, $layoutUser.
--}}
<div class="min-h-screen lg:grid lg:grid-cols-[16rem_minmax(0,1fr)]">
    {{-- §6.3 / §10.1 — sidebar bernavigasi per modul. Menyembunyikan menu yang
         tidak boleh dibuka adalah kewajiban, bukan kerapian (§10.1). --}}
    <aside class="hidden border-r border-gray-200 bg-white lg:flex lg:flex-col">
        <div class="flex h-16 items-center gap-2.5 border-b border-gray-200 px-5">
            <span aria-hidden="true" class="inline-block h-6 w-6 rounded-md bg-gomad-lime"></span>
            <a href="{{ route('home') }}" class="text-lg font-extrabold tracking-tight text-black-900">GoMad</a>
        </div>

        <nav aria-label="Navigasi utama" class="flex-1 overflow-y-auto px-3 py-4">
            <p class="px-2 pb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Ruang kerja</p>
            <ul class="space-y-0.5">
                @foreach ($layoutPrimary as $item)
                    <li>
                        <a href="{{ $item['href'] }}"
                           @if ($item['active']) aria-current="page" @endif
                           class="flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors duration-150
                                  {{ $item['active']
                                        ? 'bg-green-500/10 text-green-700'
                                        : 'text-gray-600 hover:bg-gray-50 hover:text-black-900' }}">
                            <span aria-hidden="true"
                                  class="inline-block h-1.5 w-1.5 shrink-0 rounded-pill {{ $item['active'] ? 'bg-green-500' : 'bg-gray-300' }}"></span>
                            {{ $item['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>

            @if ($layoutAccount !== [])
                <p class="px-2 pb-2 pt-6 text-xs font-semibold uppercase tracking-wide text-gray-500">Akun</p>
                <ul class="space-y-0.5">
                    @foreach ($layoutAccount as $item)
                        <li>
                            <a href="{{ $item['href'] }}"
                               @if ($item['active']) aria-current="page" @endif
                               class="flex items-center justify-between gap-2 rounded-md px-3 py-2 text-sm font-medium
                                      {{ $item['active'] ? 'bg-gray-100 text-black-900' : 'text-gray-600 hover:bg-gray-50 hover:text-black-900' }}">
                                <span>{{ $item['label'] }}</span>
                                @if (($item['count'] ?? 0) > 0)
                                    <x-gm.badge tone="brand">{{ $item['count'] > 99 ? '99+' : $item['count'] }}</x-gm.badge>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </nav>

        @auth
            <div class="border-t border-gray-200 px-3 py-3">
                <p class="truncate px-2 pb-2 text-sm font-semibold text-black-900">{{ $layoutUser->name }}</p>
                <p class="truncate px-2 pb-3 text-xs text-gray-500">{{ $layoutAccountType->label() }}</p>
                <form method="post" action="{{ route('logout') }}">
                    @csrf
                    <x-gm.button type="submit" variant="secondary" size="sm" class="w-full">Keluar</x-gm.button>
                </form>
            </div>
        @endauth
    </aside>

    <div class="flex min-h-screen flex-col">
        {{-- §6.3 — top bar: identitas pada layar kecil, aksi akun pada layar besar.
             Permukaannya **padat**, bukan setengah transparan: tanpa `backdrop-filter`
             (yang memang tidak dipakai tema ini, §7.3), latar 95% membuat teks yang
             bergulir di belakangnya tetap terbaca samar — terbaca sebagai cacat, bukan
             sebagai efek. --}}
        <header class="sticky top-0 z-30 border-b border-gray-200 bg-white">
            <div class="flex items-center justify-between gap-3 px-4 py-3 sm:px-6">
                <div class="flex min-w-0 items-center gap-2.5 lg:hidden">
                    <span aria-hidden="true" class="inline-block h-5 w-5 rounded-md bg-gomad-lime"></span>
                    <a href="{{ route('home') }}" class="text-base font-extrabold tracking-tight text-black-900">GoMad</a>
                </div>

                <div class="hidden min-w-0 lg:block">
                    @hasSection('title')
                        <p class="truncate text-sm font-medium text-gray-500">@yield('title')</p>
                    @endif
                </div>

                <div class="flex items-center gap-3">
                    @auth
                        @foreach ($layoutAccount as $item)
                            <a href="{{ $item['href'] }}"
                               @if ($item['active']) aria-current="page" @endif
                               class="flex items-center gap-1.5 text-sm font-medium text-gray-500 transition-colors duration-150 hover:text-black-900">
                                {{ $item['label'] }}
                                @if (($item['count'] ?? 0) > 0)
                                    <x-gm.badge tone="brand">{{ $item['count'] > 99 ? '99+' : $item['count'] }}</x-gm.badge>
                                @endif
                            </a>
                        @endforeach

                        <form method="post" action="{{ route('logout') }}" class="lg:hidden">
                            @csrf
                            <x-gm.button type="submit" variant="secondary" size="sm">Keluar</x-gm.button>
                        </form>
                    @else
                        <a href="{{ route('login') }}" class="text-sm font-semibold text-black-900 hover:underline">Masuk</a>
                        <a href="{{ route('register') }}">
                            <x-gm.button type="button" size="sm" variant="brand">Daftar</x-gm.button>
                        </a>
                    @endauth
                </div>
            </div>

            {{-- §11.2 mobile: navigasi ringkas yang dapat digeser mendatar. --}}
            <nav aria-label="Navigasi utama (ponsel)" class="border-t border-gray-200 lg:hidden">
                <div class="gm-scroll-x flex gap-1 px-3 py-1.5">
                    @foreach ($layoutPrimary as $item)
                        <a href="{{ $item['href'] }}"
                           @if ($item['active']) aria-current="page" @endif
                           class="shrink-0 rounded-md px-3 py-1.5 text-sm font-medium
                                  {{ $item['active'] ? 'bg-gray-100 text-black-900' : 'text-gray-500' }}">
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </div>
            </nav>
        </header>

        <main id="main" class="flex-1 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="mx-auto w-full max-w-[1200px]">
                @include('layouts.partials.flash')

                @yield('content')
            </div>
        </main>

        <footer class="border-t border-gray-200 bg-white">
            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-5 text-sm text-gray-500 sm:px-6 lg:px-8">
                <p>&copy; {{ date('Y') }} GoMad — mad warung, mad trans.</p>
                <p class="flex flex-wrap gap-4">
                    <a href="{{ route('mad-trans.index') }}" class="hover:text-black-900">Cari jadwal</a>
                    <a href="{{ route('warung.public.index') }}" class="hover:text-black-900">Mad Warung</a>
                    <a href="{{ route('public.partners') }}" class="hover:text-black-900">Jadi mitra</a>
                    <a href="{{ route('public.download') }}" class="hover:text-black-900">Unduh aplikasi</a>
                </p>
            </div>
        </footer>
    </div>
</div>
