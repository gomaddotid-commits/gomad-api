@props(['label', 'value', 'hint' => null, 'tone' => 'neutral'])

{{-- Kartu KPI — label kecil, angka besar, keterangan pendek (§9.3). --}}
@php
    $tones = [
        'neutral' => 'text-black-900',
        'brand' => 'text-gomad-green',
        'success' => 'text-success-600',
        'warning' => 'text-warning-600',
        'error' => 'text-error-600',
    ];

    $isConsumer = \App\Support\DesignTheme::current()->allowsGlass();
@endphp

<div {{ $attributes->class([
    'rounded-lg px-5 py-4',
    $isConsumer ? 'gm-glass' : 'border border-gray-200 bg-white',
]) }}>
    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $label }}</p>
    <p class="mt-1 text-2xl font-bold tracking-tight {{ $tones[$tone] ?? $tones['neutral'] }}">{{ $value }}</p>

    @if ($hint !== null)
        <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
    @endif
</div>
