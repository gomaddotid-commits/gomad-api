@extends('layouts.app')

@section('title', 'Notifikasi')

@section('content')
    <x-gm.page-header
        eyebrow="Kotak masuk"
        title="Notifikasi"
        :subtitle="$unreadCount > 0
            ? $unreadCount.' notifikasi belum dibaca.'
            : 'Semua notifikasi sudah dibaca.'">
        <x-slot:actions>
            @if ($unreadCount > 0)
                <form method="post" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <x-gm.button type="submit" variant="secondary" size="sm">Tandai semua dibaca</x-gm.button>
                </form>
            @endif
        </x-slot:actions>
    </x-gm.page-header>

    @if ($notifications->isEmpty())
        <x-gm.empty
            title="Belum ada notifikasi"
            description="Pemberitahuan pembayaran, perubahan jadwal, dan persetujuan pesanan muncul di sini." />
    @else
        <ul class="space-y-3">
            @foreach ($notifications as $notification)
                @php
                    $data = $notification->data ?? [];
                    $title = $data['title'] ?? 'Pemberitahuan';
                    $body = $data['message'] ?? $data['body'] ?? null;
                    $unread = $notification->read_at === null;
                @endphp

                <li class="rounded-lg border {{ $unread ? 'border-gomad-green/40 bg-white' : 'border-gray-200 bg-white' }} px-5 py-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="flex items-center gap-2 text-sm font-semibold text-black-900">
                                {{ $title }}
                                @if ($unread)
                                    <x-gm.badge tone="brand">Baru</x-gm.badge>
                                @endif
                            </p>
                            @if ($body)
                                <p class="mt-1 text-sm text-gray-600">{{ $body }}</p>
                            @endif
                            <p class="mt-2 text-xs text-gray-500">
                                {{ $notification->created_at?->diffForHumans() }}
                            </p>
                        </div>

                        <form method="post" action="{{ route('notifications.read', $notification->id) }}">
                            @csrf
                            <x-gm.button type="submit" size="sm" variant="{{ $unread ? 'primary' : 'ghost' }}">
                                {{ $unread ? 'Buka' : 'Lihat' }}
                            </x-gm.button>
                        </form>
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="mt-6">{{ $notifications->links() }}</div>
    @endif
@endsection
