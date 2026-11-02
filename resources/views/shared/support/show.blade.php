@extends('layouts.app')

@section('title', $ticket->code.' · Bantuan')

@section('content')
    <x-gm.page-header
        eyebrow="Bantuan"
        :title="$ticket->subject"
        :subtitle="$ticket->code.' · '.$ticket->category.' · dibuat '.$ticket->created_at->format('d M Y H:i')">
        <x-slot:actions>
            <a href="{{ route('support.index') }}">
                <x-gm.button type="button" variant="secondary">Semua tiket</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @php
        $tone = match ($ticket->status) {
            'open' => 'warning',
            'answered' => 'info',
            'closed' => 'neutral',
            default => 'neutral',
        };
        $label = match ($ticket->status) {
            'open' => 'Menunggu balasan tim',
            'answered' => 'Sudah dibalas tim',
            'closed' => 'Ditutup',
            default => $ticket->status,
        };
    @endphp

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-gm.card>
                @include('shared.support._thread', ['ticket' => $ticket, 'isStaff' => $isStaff])
            </x-gm.card>

            @if (! $ticket->isClosed())
                <x-gm.card title="Balas">
                    <form method="post" action="{{ route('support.reply', $ticket) }}" class="space-y-3">
                        @csrf
                        <label class="block">
                            <span class="sr-only">Balasan</span>
                            <textarea name="body" rows="4" required maxlength="4000"
                                      placeholder="Tambahkan informasi atau jawab pertanyaan tim.">{{ old('body') }}</textarea>
                        </label>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <x-gm.button type="submit" variant="brand">Kirim balasan</x-gm.button>
                            <span class="text-xs text-gray-500">Jangan kirim kata sandi atau data kartu.</span>
                        </div>
                    </form>
                </x-gm.card>
            @else
                <x-gm.alert tone="info">
                    Tiket ini sudah ditutup. Bila masalahnya berlanjut, buat tiket baru agar tercatat terpisah.
                </x-gm.alert>
            @endif
        </div>

        <aside class="space-y-4">
            <x-gm.card title="Status tiket">
                <dl class="space-y-2 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-600">Status</dt>
                        <dd><x-gm.badge :tone="$tone">{{ $label }}</x-gm.badge></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-600">Prioritas</dt>
                        <dd class="font-semibold text-black-900">{{ $ticket->priority === 'high' ? 'Mendesak' : 'Normal' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-600">Pesan</dt>
                        <dd class="font-semibold text-black-900">{{ $ticket->messages->count() }}</dd>
                    </div>
                    @if ($ticket->related)
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-gray-600">Terkait</dt>
                            <dd class="font-semibold text-black-900">
                                {{ $ticket->related->code ?? class_basename($ticket->related) }}
                            </dd>
                        </div>
                    @endif
                </dl>

                @if (! $ticket->isClosed())
                    <form method="post" action="{{ route('support.close', $ticket) }}" class="mt-3"
                          onsubmit="return confirm('Tutup tiket ini?')">
                        @csrf
                        <x-gm.button type="submit" variant="ghost" class="w-full">Tutup tiket</x-gm.button>
                    </form>
                @endif
            </x-gm.card>

            <x-gm.card title="Waktu tanggap">
                <p class="text-sm text-gray-600">
                    Tim membalas pada jam kerja. Tiket berprioritas mendesak didahulukan, terutama bila
                    menyangkut perjalanan hari ini.
                </p>
            </x-gm.card>
        </aside>
    </div>
@endsection
