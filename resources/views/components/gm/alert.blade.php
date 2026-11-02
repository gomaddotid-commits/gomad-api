@props(['tone' => 'info', 'dismissible' => false])

@php
    $tones = [
        'info' => 'border-info-600/20 bg-info-600/5 text-info-600',
        'success' => 'border-success-600/20 bg-success-600/5 text-success-600',
        'warning' => 'border-warning-600/20 bg-warning-600/5 text-warning-600',
        'error' => 'border-error-600/20 bg-error-600/5 text-error-600',
    ];
@endphp

<div
    role="{{ $tone === 'error' ? 'alert' : 'status' }}"
    {{ $attributes->class(['flex items-start gap-3 rounded-md border px-4 py-3 text-sm font-medium', $tones[$tone] ?? $tones['info']]) }}
>
    <span class="flex-1">{{ $slot }}</span>
</div>
