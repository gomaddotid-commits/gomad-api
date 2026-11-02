@extends('layouts.app')

@section('title', 'Penjaga & plafon '.$business->name)

@section('content')
    <x-gm.page-header
        eyebrow="Ruang kerja Mad Warung"
        title="Penjaga & plafon"
        :subtitle="$business->name.' · batas belanja dan persetujuan'">
        <x-slot:actions>
            <a href="{{ route('businesses.show', $business) }}">
                <x-gm.button type="button" variant="secondary">Kelola anggota tim</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @include('madwarung._nav', ['business' => $business, 'active' => 'guards', 'canReport' => true])

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-gm.card title="Plafon per penjaga"
                subtitle="Plafon harian yang terlampaui menolak pesanan; batas per pesanan atau ambang persetujuan memicu approval Anda."
                :padded="false">
                <div class="divide-y divide-gray-100">
                    @forelse ($guards as $guard)
                        @php
                            $limit = $limits->get($guard->id);
                            $spent = (float) ($spentToday[$guard->id] ?? 0);
                            $cap = (float) ($limit->daily_amount_limit ?? 0);
                            $percent = $cap > 0 ? min(100, (int) round($spent / $cap * 100)) : 0;
                        @endphp
                        <div class="px-5 py-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <p class="text-sm font-semibold text-black-900">{{ $guard->name }}</p>
                                    <p class="text-xs text-gray-600">{{ $guard->email ?? $guard->phone ?? 'tanpa kontak' }}</p>
                                </div>
                                <p class="text-xs text-gray-600">
                                    Hari ini <x-gm.money :amount="$spent" />
                                    @if ($cap > 0)
                                        / <x-gm.money :amount="$cap" />
                                    @else
                                        · plafon belum diatur
                                    @endif
                                </p>
                            </div>

                            @if ($cap > 0)
                                <div class="mt-2 h-1.5 overflow-hidden rounded-pill bg-gray-100">
                                    <div class="h-full rounded-pill {{ $percent >= 80 ? 'bg-error' : 'bg-gomad-green' }}"
                                         style="width: {{ max(2, $percent) }}%"></div>
                                </div>
                            @endif

                            <form method="post" action="{{ route('warung.members.limit', [$business, $guard]) }}"
                                  class="mt-3 grid gap-3 sm:grid-cols-4">
                                @csrf
                                @method('PUT')
                                <label class="block">
                                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Plafon harian</span>
                                    <input type="number" name="daily_amount_limit" min="0" step="1000" required
                                           value="{{ old('daily_amount_limit', (int) ($limit->daily_amount_limit ?? 0)) }}">
                                </label>
                                <label class="block">
                                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Per pesanan</span>
                                    <input type="number" name="per_order_limit" min="0" step="1000" required
                                           value="{{ old('per_order_limit', (int) ($limit->per_order_limit ?? 0)) }}">
                                </label>
                                <label class="block">
                                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Perlu approval di atas</span>
                                    <input type="number" name="requires_approval_above" min="0" step="1000" required
                                           value="{{ old('requires_approval_above', (int) ($limit->requires_approval_above ?? 0)) }}">
                                </label>
                                <div class="flex items-end">
                                    <x-gm.button type="submit" variant="secondary" class="w-full">Simpan</x-gm.button>
                                </div>
                            </form>
                        </div>
                    @empty
                        <div class="px-5 py-6 text-sm text-gray-600">
                            Belum ada penjaga. Undang lewat halaman anggota tim, lalu tetapkan perannya sebagai penjaga.
                        </div>
                    @endforelse
                </div>
            </x-gm.card>
        </div>

        <aside>
            <x-gm.card title="Menunggu persetujuan" :padded="false">
                <div class="divide-y divide-gray-100">
                    @forelse ($pendingApproval as $order)
                        <div class="px-5 py-4">
                            <p class="text-sm font-semibold text-black-900">{{ $order->code }}</p>
                            <p class="mt-0.5 text-xs text-gray-600">
                                {{ $order->creator?->name ?? '—' }} · {{ $order->wholesaler?->name ?? '—' }}
                            </p>
                            <p class="mt-1 text-sm font-semibold"><x-gm.money :amount="$order->total_amount" /></p>
                            <p class="mt-1 text-xs text-gray-600">
                                {{ $order->items->sum('quantity') }} unit · {{ $order->created_at->format('d M H:i') }}
                            </p>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <form method="post" action="{{ route('warung.orders.decide', $order) }}">
                                    @csrf
                                    <input type="hidden" name="decision" value="approved">
                                    <x-gm.button type="submit" size="sm" variant="brand">Setujui</x-gm.button>
                                </form>
                                <form method="post" action="{{ route('warung.orders.decide', $order) }}" class="flex items-end gap-2">
                                    @csrf
                                    <input type="hidden" name="decision" value="rejected">
                                    <label class="block">
                                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Alasan</span>
                                        <input type="text" name="reason" maxlength="500" required placeholder="stok habis">
                                    </label>
                                    <x-gm.button type="submit" size="sm" variant="danger">Tolak</x-gm.button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <div class="px-5 py-6 text-sm text-gray-600">Tidak ada pesanan yang menunggu keputusan.</div>
                    @endforelse
                </div>
            </x-gm.card>
        </aside>
    </div>
@endsection
