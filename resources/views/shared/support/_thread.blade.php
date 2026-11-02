@props(['ticket', 'isStaff' => false])

{{-- Utas percakapan tiket. Catatan internal hanya dirender untuk staf. --}}
<div class="space-y-3">
    @foreach ($ticket->messages as $message)
        @continue($message->is_internal && ! $isStaff)

        @php
            $mine = (int) $message->user_id === (int) auth()->id();
            $internal = (bool) $message->is_internal;
        @endphp

        <div class="rounded-lg border px-4 py-3
                    {{ $internal
                        ? 'border-warning-600/30 bg-warning-600/5'
                        : ($mine ? 'border-gray-200 bg-white' : 'border-gray-200 bg-surface-dashboard') }}">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm font-semibold text-black-900">
                    {{ $message->user?->name ?? 'Pengguna' }}
                    @if ($mine && ! $internal)
                        <span class="text-xs font-normal text-gray-500">(Anda)</span>
                    @endif
                    @if ($internal)
                        <x-gm.badge tone="warning">Catatan internal</x-gm.badge>
                    @endif
                </p>
                <p class="text-xs text-gray-500">{{ $message->created_at->format('d M Y H:i') }}</p>
            </div>
            <p class="mt-2 whitespace-pre-line text-sm text-gray-700">{{ $message->body }}</p>
        </div>
    @endforeach
</div>
