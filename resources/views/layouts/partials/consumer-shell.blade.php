{{--
    Kerangka FRESH & ENERGETIC MINIMALIST GLASSMORPHISM (DS v1.0 §8, §6.4).

    Dipakai pelanggan MadTrans dan seluruh halaman publik. Ciri yang dipegang:
    latar bergradien (dari CSS tema), navigasi mengambang berkaca, sudut besar,
    ruang lega, satu CTA utama yang jelas.

    §8.4 — kaca **tidak** dipakai pada teks panjang, tabel, data keuangan, atau
    formulir rumit. Karena itu kerangka ini hanya memakai kaca untuk navigasi;
    isi halaman memakai `x-gm.card` yang tetap solid agar harga dan detail
    perjalanan terbaca.

    Variabel dari `layouts.app`: $layoutPrimary, $layoutAccount, $layoutUser.
--}}
<div class="flex min-h-screen flex-col">
    {{-- §10.2 — navigasi mengambang, tetap dapat dijangkau saat menggulir. --}}
    <header class="sticky top-0 z-40 px-3 pt-3 sm:px-6 sm:pt-4">
        <div class="gm-container">
            {{--
                Satu panel kaca untuk baris utama dan baris navigasi ponsel.

                Kalau keduanya dipisah, celah di antaranya memperlihatkan isi halaman
                yang bergulir di belakang — kelihatan seperti tata letak yang bocor,
                bukan seperti kaca.
            --}}
            <div class="gm-glass-strong overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5">
                <div class="flex items-center gap-5">
                    <a href="{{ route('home') }}" class="flex items-center gap-2">
                        <span aria-hidden="true" class="inline-block h-7 w-7 rounded-md bg-gomad-lime"></span>
                        <span class="text-lg font-extrabold tracking-tight text-gomad-green-deep">GoMad</span>
                    </a>

                    <nav aria-label="Navigasi utama" class="hidden md:block">
                        <ul class="flex items-center gap-0.5">
                            @foreach ($layoutPrimary as $item)
                                <li>
                                    <a href="{{ $item['href'] }}"
                                       @if ($item['active']) aria-current="page" @endif
                                       class="rounded-pill px-3.5 py-2 text-sm font-medium transition-colors duration-150
                                              {{ $item['active']
                                                    ? 'bg-gomad-green-deep text-white'
                                                    : 'text-gray-600 hover:bg-white/70 hover:text-gomad-green-deep' }}">
                                        {{ $item['label'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </nav>
                </div>

                <div class="flex items-center gap-2">
                    @auth
                        {{--
                            Akun operasional bisa saja membuka halaman publik. Tanpa pintu
                            ini mereka melihat halaman publik tanpa jalan kembali ke
                            ruang kerjanya — dan menyangka sudah keluar dari akun.
                        --}}
                        @unless ($layoutAccountType->designLanguage()->allowsGlass())
                            <a href="{{ route($layoutAccountType->homeRoute()) }}">
                                <x-gm.button type="button" size="sm" variant="brand" class="rounded-pill">Ruang kerja</x-gm.button>
                            </a>
                        @endunless

                        @foreach ($layoutAccount as $item)
                            <a href="{{ $item['href'] }}"
                               @if ($item['active']) aria-current="page" @endif
                               class="hidden items-center gap-1.5 rounded-pill px-3 py-2 text-sm font-medium text-gray-600 transition-colors duration-150 hover:bg-white/70 hover:text-gomad-green-deep sm:flex">
                                {{ $item['label'] }}
                                @if (($item['count'] ?? 0) > 0)
                                    <x-gm.badge tone="brand">{{ $item['count'] > 99 ? '99+' : $item['count'] }}</x-gm.badge>
                                @endif
                            </a>
                        @endforeach

                        <form method="post" action="{{ route('logout') }}">
                            @csrf
                            <x-gm.button type="submit" variant="secondary" size="sm" class="rounded-pill">Keluar</x-gm.button>
                        </form>
                    @else
                        <a href="{{ route('login') }}"
                           class="rounded-pill px-3.5 py-2 text-sm font-semibold text-gomad-green-deep hover:bg-white/70">Masuk</a>
                        <a href="{{ route('register') }}">
                            <x-gm.button type="button" size="sm" variant="brand" class="rounded-pill">Daftar</x-gm.button>
                        </a>
                    @endauth
                </div>
                </div>

                {{-- §11.3 mobile: navigasi geser mendatar, tanpa luapan horizontal. --}}
                <nav aria-label="Navigasi utama (ponsel)" class="border-t border-white/60 md:hidden">
                    <div class="gm-scroll-x flex gap-1.5 px-3 py-2">
                        @foreach ($layoutPrimary as $item)
                            <a href="{{ $item['href'] }}"
                               @if ($item['active']) aria-current="page" @endif
                               class="shrink-0 rounded-pill px-3.5 py-1.5 text-sm font-medium
                                      {{ $item['active']
                                            ? 'bg-gomad-green-deep text-white'
                                            : 'bg-white/70 text-gray-600' }}">
                                {{ $item['label'] }}
                            </a>
                        @endforeach
                    </div>
                </nav>
            </div>
        </div>
    </header>

    {{-- `pb-24` memberi ruang bagi navigasi bawah di ponsel (§10.2). --}}
    <main id="main" class="flex-1 px-3 py-6 pb-24 sm:px-6 sm:py-10 md:pb-10">
        <div class="gm-container">
            @include('layouts.partials.flash')

            @yield('content')
        </div>
    </main>

    <footer class="px-3 pb-24 sm:px-6 md:pb-8">
        <div class="gm-container">
            <div class="gm-surface flex flex-wrap items-center justify-between gap-3 px-5 py-5 text-sm text-gray-500">
                <p>&copy; {{ date('Y') }} GoMad — mad warung, mad trans.</p>
                <p class="flex flex-wrap gap-4">
                    <a href="{{ route('mad-trans.index') }}" class="hover:text-black-900">Cari jadwal</a>
                    <a href="{{ route('warung.public.index') }}" class="hover:text-black-900">Mad Warung</a>
                    <a href="{{ route('public.partners') }}" class="hover:text-black-900">Jadi mitra</a>
                    <a href="{{ route('public.download') }}" class="hover:text-black-900">Unduh aplikasi</a>
                </p>
            </div>
        </div>
    </footer>

    {{--
        §10.2 bottom navigation.

        Hanya untuk pelanggan: akun mitra/admin yang membuka halaman publik tidak
        boleh diberi jalan pintas ke area yang bukan haknya — tautan yang menolak
        saat diklik lebih buruk daripada tautan yang tidak ada (§10.1).
    --}}
    @if ($layoutAccountType === \App\Core\Identity\Domain\AccountType::Customer)
        <nav aria-label="Navigasi bawah"
             class="fixed inset-x-0 bottom-0 z-40 px-3 pb-[calc(8px+env(safe-area-inset-bottom))] md:hidden">
            <div class="gm-glass-strong flex items-center justify-around px-2 py-1.5">
                @foreach ([
                    ['label' => 'Beranda', 'route' => 'dashboard', 'pattern' => 'dashboard'],
                    ['label' => 'Cari', 'route' => 'mad-trans.index', 'pattern' => 'mad-trans.index'],
                    ['label' => 'Pesanan', 'route' => 'mad-trans.bookings.index', 'pattern' => 'mad-trans.bookings.*'],
                    ['label' => 'Akun', 'route' => 'account.show', 'pattern' => 'account.*'],
                ] as $bottomItem)
                    @php($bottomActive = request()->routeIs($bottomItem['pattern']))
                    <a href="{{ route($bottomItem['route']) }}"
                       @if ($bottomActive) aria-current="page" @endif
                       class="flex min-h-[44px] flex-1 flex-col items-center justify-center gap-0.5 rounded-lg px-2 py-1 text-[11px] font-semibold
                              {{ $bottomActive ? 'text-gomad-green-deep' : 'text-gray-500' }}">
                        <span aria-hidden="true"
                              class="inline-block h-1.5 w-1.5 rounded-pill {{ $bottomActive ? 'bg-gomad-green' : 'bg-gray-300' }}"></span>
                        {{ $bottomItem['label'] }}
                    </a>
                @endforeach
            </div>
        </nav>
    @endif
</div>
