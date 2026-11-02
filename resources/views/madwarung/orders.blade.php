@extends('layouts.app')

@section('title', 'Pesanan '.$business->name)

@section('content')
    <x-gm.page-header
        eyebrow="Ruang kerja Mad Warung"
        :title="$isGrosir ? 'Pesanan masuk' : 'Pesanan warung'"
        :subtitle="$business->name.' · '.$orders->total().' pesanan'">
        <x-slot:actions>
            <a href="{{ route('warung.catalog', $business) }}">
                <x-gm.button type="button" variant="secondary">{{ $isGrosir ? 'Katalog & harga' : 'Pesan stok' }}</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @include('madwarung._nav', ['business' => $business, 'active' => 'orders', 'canReport' => $canReport, 'canManageShifts' => $canApprove])

    @php
        $tabs = [
            null => 'Semua',
            'pending_approval' => 'Menunggu persetujuan',
            'pending_payment' => 'Menunggu bayar',
            'paid' => 'Dibayar',
            'preparing' => 'Disiapkan',
            'ready' => 'Siap',
            'in_delivery' => 'Dikirim',
            'ready_for_pickup' => 'Siap diambil',
            'received' => 'Diterima',
            'completed' => 'Selesai',
            'rejected' => 'Ditolak',
        ];
    @endphp

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach ($tabs as $value => $label)
            @php $isActive = $status === $value; @endphp
            <a href="{{ route('warung.orders.index', array_filter(['business' => $business, 'status' => $value])) }}"
               class="rounded-pill border px-3 py-1.5 text-xs font-semibold
                      {{ $isActive ? 'border-black-900 bg-black-900 text-white' : 'border-gray-300 bg-white text-gray-600 hover:text-black-900' }}">
                {{ $label }}
                @if ($value !== null && isset($counts[$value]))
                    ({{ $counts[$value] }})
                @endif
            </a>
        @endforeach
    </div>

    @if ($orders->isEmpty())
        <x-gm.empty title="Belum ada pesanan di filter ini"
            :description="$isGrosir ? 'Pesanan dari warung akan muncul di sini.' : 'Pesan stok dari grosir untuk membuat pesanan pertama.'" />
    @else
        <div class="space-y-3">
            @foreach ($orders as $order)
                @php
                    $tone = match ($order->status) {
                        'completed', 'received' => 'success',
                        'pending_approval', 'pending_payment' => 'warning',
                        'rejected', 'cancelled', 'expired' => 'error',
                        default => 'info',
                    };
                    $label = match ($order->status) {
                        'pending_approval' => 'Menunggu persetujuan',
                        'pending_payment' => 'Menunggu bayar',
                        'paid' => 'Dibayar',
                        'preparing' => 'Disiapkan',
                        'ready' => 'Siap',
                        'in_delivery' => 'Dikirim',
                        'ready_for_pickup' => 'Siap diambil',
                        'received' => 'Diterima',
                        'completed' => 'Selesai',
                        'rejected' => 'Ditolak',
                        'cancelled' => 'Dibatalkan',
                        'expired' => 'Kedaluwarsa',
                        default => ucfirst((string) $order->status),
                    };
                @endphp

                <x-gm.card :padded="false">
                    <div class="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-black-900">
                                {{ $order->code }} ·
                                {{ $isGrosir ? ($order->business?->name ?? '—') : ($order->wholesaler?->name ?? '—') }}
                            </p>
                            <p class="mt-0.5 text-xs text-gray-600">
                                {{ $order->creator?->name ?? '—' }}
                                · {{ $order->created_at->format('d M Y H:i') }}
                                · {{ $order->delivery_method === 'antar_grosir' ? 'diantar grosir' : 'ambil sendiri' }}
                            </p>
                            <ul class="mt-2 space-y-0.5 text-xs text-gray-600">
                                @foreach ($order->items as $item)
                                    <li>
                                        {{ $item->product_name }} — {{ $item->quantity }} {{ $item->unit }}
                                        × <x-gm.money :amount="$item->unit_price" />
                                    </li>
                                @endforeach
                            </ul>
                            @if ($order->status === 'rejected' && $order->approvals->isNotEmpty())
                                <p class="mt-2 text-xs text-error">
                                    Ditolak: {{ $order->approvals->last()->reason ?? 'tanpa alasan tertulis' }}
                                </p>
                            @endif
                        </div>

                        <div class="text-right">
                            <p class="text-base font-bold text-black-900"><x-gm.money :amount="$order->total_amount" /></p>
                            <p class="mt-1"><x-gm.badge :tone="$tone">{{ $label }}</x-gm.badge></p>
                        </div>
                    </div>

                    @php
                        $actions = [];
                        if (! $isGrosir && $canApprove && $order->status === 'pending_approval') {
                            $actions[] = 'decide';
                        }
                        if (! $isGrosir && $canPay && $order->status === 'pending_payment') {
                            $actions[] = 'pay';
                        }
                        if ($isGrosir && $canFulfill && in_array($order->status, ['paid', 'preparing', 'ready'], true)) {
                            $actions[] = 'fulfill';
                        }
                        if (! $isGrosir && $canReceive && in_array($order->status, ['ready_for_pickup', 'in_delivery', 'ready'], true)) {
                            $actions[] = 'receive';
                        }
                        if (! $isGrosir && $canApprove && $order->status === 'received') {
                            $actions[] = 'complete';
                        }
                    @endphp

                    @if ($actions !== [])
                        <div class="flex flex-wrap items-center gap-2 border-t border-gray-200 px-5 py-3">
                            @if (in_array('decide', $actions, true))
                                <form method="post" action="{{ route('warung.orders.decide', $order) }}">
                                    @csrf
                                    <input type="hidden" name="decision" value="approved">
                                    <x-gm.button type="submit" size="sm" variant="brand">Setujui</x-gm.button>
                                </form>
                                <form method="post" action="{{ route('warung.orders.decide', $order) }}" class="flex items-end gap-2">
                                    @csrf
                                    <input type="hidden" name="decision" value="rejected">
                                    <label class="block">
                                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Alasan penolakan</span>
                                        <input type="text" name="reason" maxlength="500" required placeholder="stok habis">
                                    </label>
                                    <x-gm.button type="submit" size="sm" variant="danger">Tolak</x-gm.button>
                                </form>
                            @endif

                            @if (in_array('pay', $actions, true))
                                <form method="post" action="{{ route('warung.orders.pay', $order) }}">
                                    @csrf
                                    <x-gm.button type="submit" size="sm" variant="brand">Bayar</x-gm.button>
                                </form>
                            @endif

                            @if (in_array('fulfill', $actions, true))
                                @php
                                    $next = match ($order->status) {
                                        'paid' => ['prepare', 'Mulai menyiapkan'],
                                        'preparing' => ['ready', 'Tandai siap'],
                                        'ready' => ['dispatch', 'Tandai dikirim'],
                                        default => null,
                                    };
                                @endphp
                                @if ($next !== null)
                                    <form method="post" action="{{ route('warung.orders.fulfill', $order) }}">
                                        @csrf
                                        <input type="hidden" name="action" value="{{ $next[0] }}">
                                        <x-gm.button type="submit" size="sm" variant="secondary">{{ $next[1] }}</x-gm.button>
                                    </form>
                                @endif
                            @endif

                            @if (in_array('receive', $actions, true))
                                <form method="post" action="{{ route('warung.orders.receive', $order) }}">
                                    @csrf
                                    <x-gm.button type="submit" size="sm" variant="brand">Konfirmasi diterima</x-gm.button>
                                </form>
                            @endif

                            @if (in_array('complete', $actions, true))
                                <form method="post" action="{{ route('warung.orders.complete', $order) }}">
                                    @csrf
                                    <x-gm.button type="submit" size="sm" variant="secondary">Selesaikan pesanan</x-gm.button>
                                </form>
                            @endif
                        </div>
                    @endif
                </x-gm.card>
            @endforeach
        </div>

        <div class="mt-4">{{ $orders->links() }}</div>
    @endif
@endsection
