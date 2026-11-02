@props(['title', 'subtitle' => null, 'eyebrow' => null])

{{--
    Kepala halaman: satu judul, satu kalimat konteks, aksi di kanan (§6.3).

    Ukuran judul 30px (H2 pada skala §5.2). H1 36px dipakai di halaman publik dan
    hero, bukan di setiap halaman kerja — kepadatan informasi operasional lebih
    penting daripada judul besar.
--}}
<div {{ $attributes->class(['mb-6 flex flex-wrap items-start justify-between gap-4']) }}>
    <div class="min-w-0">
        @if ($eyebrow !== null)
            <p class="text-xs font-semibold uppercase tracking-wide text-gomad-green">{{ $eyebrow }}</p>
        @endif

        <h1 class="gm-h2 mt-1 text-black-900">{{ $title }}</h1>

        @if ($subtitle !== null)
            <p class="mt-1.5 max-w-2xl text-sm text-gray-500">{{ $subtitle }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
