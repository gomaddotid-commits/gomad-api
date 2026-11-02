@extends('layouts.app')

@section('title', $ticket->code.' · Dukungan')

@section('content')
    <x-gm.page-header
        eyebrow="Konsol operasional"
        :title="$ticket->subject"
        :subtitle="$ticket->code.' · '.$ticket->user?->name.' ('.$ticket->user?->email.') · '.$ticket->category">
        <x-slot:actions>
            <a href="{{ route('admin.support.index') }}">
                <x-gm.button type="button" variant="secondary">Antrean</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @include('platform.admin._nav', ['active' => 'support'])

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-gm.card>
                @include('shared.support._thread', ['ticket' => $ticket, 'isStaff' => $isStaff])
            </x-gm.card>

            <x-gm.card title="Balas">
                <form method="post" action="{{ route('admin.support.reply', $ticket) }}" class="space-y-3">
                    @csrf
                    <label class="block">
                        <span class="sr-only">Balasan</span>
                        <textarea name="body" rows="4" required maxlength="4000"
                                  placeholder="Tulis balasan untuk pengguna, atau catatan internal.">{{ old('body') }}</textarea>
                    </label>
                    <label class="flex items-center gap-2 text-sm text-gray-600">
                        <input type="checkbox" name="internal" value="1" @checked(old('internal'))>
                        Catatan internal (tidak terlihat oleh pengguna)
                    </label>
                    <x-gm.button type="submit" variant="brand">Simpan</x-gm.button>
                </form>
            </x-gm.card>
        </div>

        <aside class="space-y-4">
            <x-gm.card title="Ringkasan">
                <dl class="space-y-2 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-600">Status</dt>
                        <dd class="font-semibold text-black-900">{{ $ticket->status }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-600">Prioritas</dt>
                        <dd class="font-semibold {{ $ticket->priority === 'high' ? 'text-error' : 'text-black-900' }}">
                            {{ $ticket->priority }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-600">Dibuat</dt>
                        <dd class="text-gray-700">{{ $ticket->created_at->format('d M Y H:i') }}</dd>
                    </div>
                    @if ($ticket->related)
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-gray-600">Terkait</dt>
                            <dd class="font-semibold text-black-900">
                                {{ $ticket->related->code ?? class_basename($ticket->related) }}
                            </dd>
                        </div>
                    @endif
                    @if ($ticket->closed_at)
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-gray-600">Ditutup</dt>
                            <dd class="text-gray-700">
                                {{ $ticket->closed_at->format('d M Y H:i') }}
                                @if ($ticket->closer)
                                    · {{ $ticket->closer->name }}
                                @endif
                            </dd>
                        </div>
                    @endif
                </dl>

                @if (! $ticket->isClosed())
                    <form method="post" action="{{ route('admin.support.close', $ticket) }}" class="mt-3"
                          onsubmit="return confirm('Tutup tiket ini?')">
                        @csrf
                        <x-gm.button type="submit" variant="secondary" class="w-full">Tutup tiket</x-gm.button>
                    </form>
                @else
                    <p class="mt-3 text-xs text-gray-500">
                        Tiket ditutup. Balasan publik dari staf akan membukanya kembali.
                    </p>
                @endif
            </x-gm.card>
        </aside>
    </div>
@endsection
