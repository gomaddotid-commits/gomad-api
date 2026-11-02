@extends('layouts.app')

@section('title', 'Dompet '.$business->name)

@section('content')
    <x-gm.page-header
        eyebrow="Ruang kerja agency"
        title="Dompet & saldo"
        :subtitle="$business->name.' · saldo tersedia, jaminan, dan kewajiban'">
        <x-slot:actions>
            <a href="{{ route('mad-trans.agency.withdrawals', $business) }}">
                <x-gm.button type="button" variant="brand">Ajukan pencairan</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @include('madtrans.travel._nav', ['business' => $business, 'active' => 'wallet'])

    <div class="mb-5 grid gap-4 sm:grid-cols-3">
        <x-gm.stat label="Saldo tersedia" :value="'Rp '.number_format((float) $availableBalance, 0, ',', '.')"
            hint="Bisa dicairkan ke rekening bank" tone="success" />
        <x-gm.stat label="Jaminan (collateral)" :value="'Rp '.number_format((float) $collateralBalance, 0, ',', '.')"
            hint="Ditahan platform atas jadwal yang berjalan" tone="warning" />
        <x-gm.stat label="Kewajiban (payable)" :value="'Rp '.number_format((float) $payableBalance, 0, ',', '.')"
            hint="Hak mitra lain yang belum diselesaikan" />
    </div>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-gm.card title="Mutasi terakhir" subtitle="50 transaksi terbaru pada akun usaha ini." :padded="false">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                                <th class="px-5 py-2">Waktu</th>
                                <th class="px-5 py-2">Akun</th>
                                <th class="px-5 py-2">Keterangan</th>
                                <th class="px-5 py-2 text-right">Jumlah</th>
                                <th class="px-5 py-2 text-right">Saldo</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($mutations as $entry)
                                @php
                                    $code = $accounts[$entry->account_id] ?? '';
                                    $label = match (true) {
                                        str_contains((string) $code, 'AVAILABLE') => 'Tersedia',
                                        str_contains((string) $code, 'COLLATERAL') => 'Jaminan',
                                        str_contains((string) $code, 'PAYABLE') => 'Kewajiban',
                                        default => (string) $code,
                                    };
                                    $isDebit = $entry->direction === 'debit';
                                @endphp
                                <tr>
                                    <td class="whitespace-nowrap px-5 py-2.5 text-gray-600">
                                        {{ $entry->transaction?->occurred_at?->format('d M Y H:i') ?? $entry->created_at->format('d M Y H:i') }}
                                    </td>
                                    <td class="px-5 py-2.5">
                                        <x-gm.badge tone="neutral">{{ $label }}</x-gm.badge>
                                    </td>
                                    <td class="px-5 py-2.5">
                                        <span class="font-medium text-black-900">
                                            {{ $entry->transaction?->description ?? $entry->transaction?->category ?? '—' }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-2.5 text-right font-semibold {{ $isDebit ? 'text-error' : 'text-success' }}">
                                        {{ $isDebit ? '−' : '+' }}<x-gm.money :amount="$entry->amount" />
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-2.5 text-right text-gray-600">
                                        <x-gm.money :amount="$entry->balance_after" />
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-5 py-6 text-center text-gray-600">
                                        Belum ada mutasi saldo.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-gm.card>
        </div>

        <aside class="space-y-5">
            <x-gm.card title="Dana tertahan" :subtitle="'Total Rp '.number_format((float) $holdsTotal, 0, ',', '.')" :padded="false">
                <div class="divide-y divide-gray-100">
                    @forelse ($holds as $hold)
                        <div class="px-5 py-3">
                            <p class="text-sm font-semibold text-black-900"><x-gm.money :amount="$hold->amount" /></p>
                            <p class="text-xs text-gray-600">{{ $hold->reason ?? 'penahanan saldo' }}</p>
                            @if ($hold->expires_at)
                                <p class="mt-1 text-xs text-gray-500">
                                    Berakhir {{ \Illuminate\Support\Carbon::parse($hold->expires_at)->format('d M Y H:i') }}
                                </p>
                            @endif
                        </div>
                    @empty
                        <div class="px-5 py-5 text-sm text-gray-600">Tidak ada dana tertahan.</div>
                    @endforelse
                </div>
            </x-gm.card>

            <x-gm.card title="Butuh saldo jaminan?">
                <p class="text-sm text-gray-600">
                    Tambah jaminan untuk membuka jadwal tambahan. Jaminan dikembalikan setelah
                    seluruh perjalanan selesai tanpa keluhan.
                </p>
                <form method="post" action="{{ route('mad-trans.agency.collateral.store', $business) }}" class="mt-3 space-y-2">
                    @csrf
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Nominal</span>
                        <input type="number" name="amount" min="10000" step="10000" required placeholder="100000">
                    </label>
                    <x-gm.button type="submit" variant="secondary" class="w-full">Tambah jaminan</x-gm.button>
                </form>
            </x-gm.card>
        </aside>
    </div>
@endsection
