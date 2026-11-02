@props(['title', 'description' => null, 'icon' => null])

{{-- Keadaan kosong dirancang, bukan dibiarkan — selalu ada langkah berikutnya (§9.2). --}}
@php($isConsumer = \App\Support\DesignTheme::current()->allowsGlass())

<div {{ $attributes->class([
    'rounded-lg px-6 py-10 text-center',
    $isConsumer ? 'gm-glass' : 'border border-dashed border-gray-300 bg-white',
]) }}>
    @if ($icon !== null)
        <div class="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-pill bg-lime-100 text-gomad-green-deep">
            {!! $icon !!}
        </div>
    @endif

    <p class="text-base font-semibold text-black-900">{{ $title }}</p>

    @if ($description !== null)
        <p class="mx-auto mt-1 max-w-md text-sm text-gray-500">{{ $description }}</p>
    @endif

    @isset($action)
        <div class="mt-4 flex justify-center">{{ $action }}</div>
    @endisset
</div>
