@extends('layouts.app')

@section('title', 'Settlement · Konsol operasional')

@section('content')
    <x-gm.page-header
        eyebrow="Konsol operasional"
        title="Settlement mitra"
        subtitle="Memindahkan saldo kewajiban (payable) menjadi saldo tersedia. Hanya agency dan grosir.">
        <x-slot:actions>
            <a href="{{ route('admin.reconciliation') }}">
                <x-gm.button type="button" variant="secondary">Rekonsiliasi</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @include('platform.admin._nav', ['active' => 'settlement'])

    @if ($payables->isEmpty())
        <x-gm.empty title="Tidak ada saldo kewajiban"
            description="Semua saldo payable sudah diselesaikan, atau belum ada transaksi yang perlu di-settle." />
    @else
        <div class="space-y-3">
            @foreach ($payables as $row)
                <x-gm.card :padded="false">
                    <div class="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
                        <div>
                            <p class="text-sm font-semibold text-black-900">
                                {{ $row['business']->name }}
                                <span class="font-normal text-gray-600">({{ $row['business']->type }} · {{ $row['business']->code }})</span>
                            </p>
                            <p class="mt-1 text-xs text-gray-600">
                                Saldo tersedia sekarang <x-gm.money :amount="$row['available']" />
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs uppercase tracking-wide text-gray-500">Kewajiban</p>
                            <p class="text-base font-bold text-black-900"><x-gm.money :amount="$row['payable']" /></p>
                        </div>
                    </div>

                    <form method="post" action="{{ route('admin.settlement.store', $row['business']) }}"
                          class="flex flex-wrap items-end gap-3 border-t border-gray-200 px-5 py-3">
                        @csrf
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Nominal settlement</span>
                            <input type="number" name="amount" min="1" step="0.01" required
                                   value="{{ (int) $row['payable'] }}">
                        </label>
                        <x-gm.button type="submit" size="sm" variant="brand">Posting settlement</x-gm.button>
                        <p class="text-xs text-gray-500">
                            Tidak boleh melebihi saldo payable — ledger akan menolak overdraw.
                        </p>
                    </form>
                </x-gm.card>
            @endforeach
        </div>
    @endif

    <div class="mt-6">
        <x-gm.card title="Pencairan terakhir yang sudah dibayar" :padded="false">
            <div class="divide-y divide-gray-100">
                @forelse ($recent as $withdrawal)
                    <div class="flex items-center justify-between gap-3 px-5 py-3 text-sm">
                        <div>
                            <p class="font-semibold text-black-900">{{ $withdrawal->code }}</p>
                            <p class="text-xs text-gray-600">
                                {{ $withdrawal->business?->name ?? '—' }}
                                · {{ $withdrawal->paid_at?->format('d M Y H:i') ?? '—' }}
                            </p>
                        </div>
                        <x-gm.money :amount="$withdrawal->net_amount" />
                    </div>
                @empty
                    <div class="px-5 py-6 text-sm text-gray-600">Belum ada pencairan yang dibayar.</div>
                @endforelse
            </div>
        </x-gm.card>
    </div>
@endsection
