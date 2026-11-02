@extends('layouts.app')

@section('title', 'Dukungan · Konsol operasional')

@section('content')
    <x-gm.page-header
        eyebrow="Konsol operasional"
        title="Antrean dukungan"
        subtitle="Tiket terbuka di atas, tiket ditutup di bawah. Balas, beri catatan internal, atau tutup.">
    </x-gm.page-header>

    @include('platform.admin._nav', ['active' => 'support'])

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div class="flex flex-wrap gap-2">
            @foreach ([null => 'Semua', 'open' => 'Terbuka', 'answered' => 'Dibalas', 'closed' => 'Ditutup'] as $value => $label)
                @php $isActive = $status === $value; @endphp
                <a href="{{ route('admin.support.index', array_filter(['status' => $value, 'q' => $search ?: null])) }}"
                   class="rounded-pill border px-3 py-1.5 text-xs font-semibold
                          {{ $isActive ? 'border-black-900 bg-black-900 text-white' : 'border-gray-300 bg-white text-gray-600 hover:text-black-900' }}">
                    {{ $label }}
                    @if ($value !== null && isset($counts[$value]))
                        ({{ $counts[$value] }})
                    @endif
                </a>
            @endforeach
        </div>

        <form method="get" action="{{ route('admin.support.index') }}" class="flex items-end gap-2">
            <label class="block">
                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Cari</span>
                <input type="text" name="q" value="{{ $search }}" placeholder="kode, judul, nama, email">
            </label>
            <x-gm.button type="submit" size="sm" variant="secondary">Cari</x-gm.button>
        </form>
    </div>

    @if ($tickets->isEmpty())
        <x-gm.empty title="Antrean kosong" description="Tidak ada tiket pada filter ini." />
    @else
        <x-gm.card :padded="false">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                            <th class="px-5 py-2">Tiket</th>
                            <th class="px-5 py-2">Pengguna</th>
                            <th class="px-5 py-2">Kategori</th>
                            <th class="px-5 py-2">Status</th>
                            <th class="px-5 py-2">Aktivitas</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($tickets as $ticket)
                            @php
                                $tone = match ($ticket->status) {
                                    'open' => 'warning',
                                    'answered' => 'info',
                                    'closed' => 'neutral',
                                    default => 'neutral',
                                };
                            @endphp
                            <tr>
                                <td class="px-5 py-3">
                                    <a href="{{ route('admin.support.show', $ticket) }}"
                                       class="font-semibold text-black-900 hover:underline">{{ $ticket->subject }}</a>
                                    <p class="text-xs text-gray-600">
                                        {{ $ticket->code }}
                                        @if ($ticket->priority === 'high')
                                            · <span class="font-semibold text-error">mendesak</span>
                                        @endif
                                    </p>
                                </td>
                                <td class="px-5 py-3 text-gray-600">
                                    {{ $ticket->user?->name ?? '—' }}
                                    <span class="block text-xs">{{ $ticket->user?->email }}</span>
                                </td>
                                <td class="px-5 py-3 text-gray-600">{{ $ticket->category }}</td>
                                <td class="px-5 py-3"><x-gm.badge :tone="$tone">{{ $ticket->status }}</x-gm.badge></td>
                                <td class="px-5 py-3 text-xs text-gray-600">
                                    {{ $ticket->messages_count }} pesan<br>
                                    {{ $ticket->last_message_at?->format('d M Y H:i') ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-gm.card>

        <div class="mt-4">{{ $tickets->links() }}</div>
    @endif
@endsection
