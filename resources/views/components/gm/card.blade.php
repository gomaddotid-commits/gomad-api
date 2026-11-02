@props(['title' => null, 'subtitle' => null, 'elevated' => false, 'padded' => true])

{{--
    Anatomi kartu: Header / Isi / Metadata / Aksi (DS v1.0 §9.2).

    Permukaan mengikuti tema. Operasional memakai permukaan padat bergaris
    (§7.3 "blur not used by default"); consumer memakai kaca tipis (§8.3).
    Kartu adalah tempat data — harga, jadwal, nominal — sehingga di tema consumer
    pun yang dipakai `gm-glass`, bukan `gm-glass-strong`: angka harus tetap
    terbaca (§8.4 "avoid excessive glass on financial data").
--}}
@php
    $isConsumer = \App\Support\DesignTheme::current()->allowsGlass();

    $classes = implode(' ', [
        'rounded-lg',
        $isConsumer
            ? 'gm-glass'
            : ($elevated ? 'bg-white shadow-md' : 'border border-gray-200 bg-white'),
    ]);
@endphp

<section {{ $attributes->class($classes) }}>
    @if ($title !== null || isset($header) || isset($actions))
        <header class="flex flex-wrap items-start justify-between gap-4 border-b border-gray-200/70 px-5 py-4">
            <div class="min-w-0">
                @if ($title !== null)
                    <h2 class="gm-h4 text-black-900">{{ $title }}</h2>
                @endif

                @if ($subtitle !== null)
                    <p class="mt-1 text-sm text-gray-500">{{ $subtitle }}</p>
                @endif

                {{ $header ?? '' }}
            </div>

            @isset($actions)
                <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </header>
    @endif

    <div class="{{ $padded ? 'px-5 py-4' : '' }}">
        {{ $slot }}
    </div>

    @isset($footer)
        <footer class="border-t border-gray-200/70 px-5 py-3 text-sm text-gray-500">{{ $footer }}</footer>
    @endisset
</section>
