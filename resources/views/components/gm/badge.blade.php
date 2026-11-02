@props(['tone' => 'neutral'])

@php
    /*
    | Warna status terpisah dari warna merek (§4.3). Badge tidak boleh memakai lime
    | untuk "sukses": lime adalah aksen merek, dan status harus tetap terbaca
    | sebagai status (§12.5 — status tidak boleh hanya bergantung pada warna, jadi
    | setiap badge selalu membawa teks).
    */
    $tones = [
        'neutral' => 'bg-gray-100 text-gray-600',
        'brand' => 'bg-lime-100 text-gomad-green-deep',
        'success' => 'bg-success-600/10 text-success-600',
        'warning' => 'bg-warning-600/10 text-warning-600',
        'error' => 'bg-error-600/10 text-error-600',
        'info' => 'bg-info-600/10 text-info-600',
    ];
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-pill px-2.5 py-0.5 text-xs font-semibold', $tones[$tone] ?? $tones['neutral']]) }}>
    {{ $slot }}
</span>
