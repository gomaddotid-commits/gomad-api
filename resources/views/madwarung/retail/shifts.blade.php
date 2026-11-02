@extends('layouts.app')

@section('title', 'Shift '.$business->name)

@section('content')
    <x-gm.page-header
        eyebrow="Ruang kerja Mad Warung"
        title="Jadwal shift penjaga"
        :subtitle="$business->name.' · siapa bertugas kapan'">
        <x-slot:actions>
            <a href="{{ route('warung.guards', $business) }}">
                <x-gm.button type="button" variant="secondary">Penjaga &amp; plafon</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @include('madwarung._nav', ['business' => $business, 'active' => 'shifts', 'canReport' => $canManage])

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-gm.card title="Riwayat & jadwal" :padded="false">
                <div class="divide-y divide-gray-100">
                    @forelse ($shifts as $shift)
                        @php
                            $tone = match ($shift->status) {
                                'active' => 'success',
                                'scheduled' => 'warning',
                                'completed' => 'neutral',
                                default => 'neutral',
                            };
                            $label = match ($shift->status) {
                                'scheduled' => 'Dijadwalkan',
                                'active' => 'Berjalan',
                                'completed' => 'Selesai',
                                'missed' => 'Terlewat',
                                default => ucfirst((string) $shift->status),
                            };
                        @endphp
                        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                            <div>
                                <p class="text-sm font-semibold text-black-900">{{ $shift->user?->name ?? '—' }}</p>
                                <p class="text-xs text-gray-600">
                                    {{ $shift->scheduled_start_at?->format('d M Y H:i') ?? 'tanpa jadwal' }}
                                    – {{ $shift->scheduled_end_at?->format('d M H:i') ?? '—' }}
                                    @if ($shift->started_at)
                                        · mulai {{ $shift->started_at->format('H:i') }}
                                    @endif
                                    @if ($shift->ended_at)
                                        · selesai {{ $shift->ended_at->format('H:i') }}
                                    @endif
                                </p>
                            </div>

                            <div class="flex items-center gap-2">
                                <x-gm.badge :tone="$tone">{{ $label }}</x-gm.badge>

                                @if ((int) $shift->user_id === (int) auth()->id())
                                    @if ($shift->status === 'scheduled')
                                        <form method="post" action="{{ route('warung.shifts.clock-in', $shift) }}">
                                            @csrf
                                            <x-gm.button type="submit" size="sm" variant="brand">Mulai shift</x-gm.button>
                                        </form>
                                    @elseif ($shift->status === 'active')
                                        <form method="post" action="{{ route('warung.shifts.clock-out', $shift) }}">
                                            @csrf
                                            <x-gm.button type="submit" size="sm" variant="secondary">Akhiri shift</x-gm.button>
                                        </form>
                                    @endif
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="px-5 py-6 text-sm text-gray-600">Belum ada shift terjadwal.</div>
                    @endforelse
                </div>
            </x-gm.card>
        </div>

        <aside>
            @if ($canManage)
                <x-gm.card title="Jadwalkan shift">
                    @if ($guards->isEmpty())
                        <p class="text-sm text-gray-600">
                            Undang penjaga lebih dulu di halaman anggota tim sebelum menyusun shift.
                        </p>
                    @else
                        <form method="post" action="{{ route('warung.shifts.schedule', $business) }}" class="space-y-3">
                            @csrf
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Penjaga</span>
                                <select name="user_id" required>
                                    @foreach ($guards as $guard)
                                        <option value="{{ $guard->id }}">{{ $guard->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Mulai</span>
                                <input type="datetime-local" name="starts_at" required value="{{ old('starts_at') }}">
                            </label>
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Selesai</span>
                                <input type="datetime-local" name="ends_at" required value="{{ old('ends_at') }}">
                            </label>
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Catatan</span>
                                <textarea name="notes" rows="2">{{ old('notes') }}</textarea>
                            </label>
                            <x-gm.button type="submit" variant="brand" class="w-full">Jadwalkan</x-gm.button>
                        </form>
                        <p class="mt-3 text-xs text-gray-500">
                            Shift tidak boleh bertumpang tindih dengan shift aktif penjaga yang sama.
                        </p>
                    @endif
                </x-gm.card>
            @else
                <x-gm.card title="Shift Anda">
                    <p class="text-sm text-gray-600">
                        Hanya penjaga yang bisa memulai atau mengakhiri shift-nya sendiri.
                        Jadwal disusun pemilik warung.
                    </p>
                </x-gm.card>
            @endif
        </aside>
    </div>
@endsection
