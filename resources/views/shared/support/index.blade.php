@extends('layouts.app')

@section('title', 'Bantuan')

@section('content')
    <x-gm.page-header
        eyebrow="Bantuan"
        title="Tiket dukungan"
        subtitle="Satu tiket untuk satu masalah. Percakapan tersimpan, jadi tidak perlu menjelaskan ulang.">
        <x-slot:actions>
            <a href="{{ route('support.create') }}">
                <x-gm.button type="button" variant="brand">Buat tiket</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @php
        $tabs = [null => 'Semua', 'open' => 'Terbuka', 'answered' => 'Dibalas', 'closed' => 'Ditutup'];
    @endphp

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach ($tabs as $value => $label)
            @php $isActive = $status === $value; @endphp
            <a href="{{ route('support.index', array_filter(['status' => $value])) }}"
               class="rounded-pill border px-3 py-1.5 text-xs font-semibold
                      {{ $isActive ? 'border-black-900 bg-black-900 text-white' : 'border-gray-300 bg-white text-gray-600 hover:text-black-900' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    @if ($tickets->isEmpty())
        <x-gm.empty title="Belum ada tiket"
            description="Bila ada kendala pembayaran, perjalanan, atau pesanan, buka tiket dan tim kami akan membalas di sini." />
    @else
        <div class="space-y-3">
            @foreach ($tickets as $ticket)
                @php
                    $tone = match ($ticket->status) {
                        'open' => 'warning',
                        'answered' => 'info',
                        'closed' => 'neutral',
                        default => 'neutral',
                    };
                    $label = match ($ticket->status) {
                        'open' => 'Terbuka',
                        'answered' => 'Dibalas tim',
                        'closed' => 'Ditutup',
                        default => $ticket->status,
                    };
                @endphp
                <a href="{{ route('support.show', $ticket) }}" class="block">
                    <x-gm.card :padded="false">
                        <div class="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-black-900">{{ $ticket->subject }}</p>
                                <p class="mt-0.5 text-xs text-gray-600">
                                    {{ $ticket->code }} · {{ $ticket->category }}
                                    · {{ $ticket->messages_count }} pesan
                                    · {{ $ticket->last_message_at?->format('d M Y H:i') ?? $ticket->created_at->format('d M Y H:i') }}
                                </p>
                            </div>
                            <x-gm.badge :tone="$tone">{{ $label }}</x-gm.badge>
                        </div>
                    </x-gm.card>
                </a>
            @endforeach
        </div>

        <div class="mt-4">{{ $tickets->links() }}</div>
    @endif
@endsection
