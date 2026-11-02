@props(['variant' => 'primary', 'size' => 'md', 'type' => 'submit'])

@php
    /*
    | Satu tombol utama per layar; sisanya sekunder atau ghost (DS v1.0 §9.2).
    |
    | `primary` memakai Deep Green pekat: kontras 12:1 dengan teks putih, jadi aman
    | untuk aksi apa pun di kedua tema. `brand` memakai Electric Lime dengan teks
    | Deep Olive (8:1) — dipakai sebagai aksen, mis. "Daftar", bukan untuk setiap
    | tombol (§4.4 komposisi warna).
    */
    $variants = [
        'primary' => 'bg-gomad-green-deep text-white hover:bg-green-700',
        'brand' => 'bg-gomad-lime text-gomad-green-deep hover:bg-lime-400',
        'secondary' => 'border border-gray-300 bg-white text-black-900 hover:bg-gray-50',
        'ghost' => 'text-black-900 hover:bg-gray-100',
        'danger' => 'bg-error-600 text-white hover:opacity-90',
    ];

    $sizes = [
        'sm' => 'px-3 py-1.5 text-sm',
        'md' => 'px-4 py-2.5 text-sm',
        'lg' => 'px-5 py-3 text-base',
    ];
@endphp

<button
    type="{{ $type }}"
    {{ $attributes->class([
        'inline-flex items-center justify-center gap-2 rounded-md font-semibold transition-colors duration-150 disabled:cursor-not-allowed disabled:opacity-50',
        $variants[$variant] ?? $variants['primary'],
        $sizes[$size] ?? $sizes['md'],
    ]) }}
>
    {{ $slot }}
</button>
