@extends('layouts.app')

@section('title', 'Pencairan '.$business->name)

@section('content')
    <x-gm.page-header
        eyebrow="Ruang kerja agency"
        title="Pencairan saldo"
        :subtitle="$business->name.' · saldo tersedia Rp '.number_format((float) $availableBalance, 0, ',', '.')">
        <x-slot:actions>
            <a href="{{ route('mad-trans.agency.wallet', $business) }}">
                <x-gm.button type="button" variant="secondary">Lihat mutasi</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @include('madtrans.travel._nav', ['business' => $business, 'active' => 'withdrawals'])

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-gm.card title="Riwayat pencairan" :padded="false">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                                <th class="px-5 py-2">Kode</th>
                                <th class="px-5 py-2">Diajukan</th>
                                <th class="px-5 py-2">Bank</th>
                                <th class="px-5 py-2 text-right">Nominal</th>
                                <th class="px-5 py-2 text-right">Diterima</th>
                                <th class="px-5 py-2">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($withdrawals as $withdrawal)
                                @php
                                    $tone = match ($withdrawal->status) {
                                        'paid' => 'success',
                                        'pending', 'approved', 'processing' => 'warning',
                                        'failed', 'rejected' => 'error',
                                        default => 'neutral',
                                    };
                                @endphp
                                <tr>
                                    <td class="whitespace-nowrap px-5 py-2.5 font-semibold text-black-900">{{ $withdrawal->code }}</td>
                                    <td class="whitespace-nowrap px-5 py-2.5 text-gray-600">{{ $withdrawal->created_at->format('d M Y H:i') }}</td>
                                    <td class="px-5 py-2.5 text-gray-600">
                                        {{ strtoupper((string) $withdrawal->bank_code) }}
                                        ····{{ substr((string) $withdrawal->bank_account_number, -4) }}
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-2.5 text-right"><x-gm.money :amount="$withdrawal->amount" /></td>
                                    <td class="whitespace-nowrap px-5 py-2.5 text-right text-gray-600"><x-gm.money :amount="$withdrawal->net_amount" /></td>
                                    <td class="px-5 py-2.5"><x-gm.badge :tone="$tone">{{ ucfirst((string) $withdrawal->status) }}</x-gm.badge></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-6 text-center text-gray-600">Belum ada pencairan.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-5 py-3">{{ $withdrawals->links() }}</div>
            </x-gm.card>
        </div>

        <aside>
            <x-gm.card title="Ajukan pencairan">
                <dl class="mb-4 space-y-1 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Saldo tersedia</dt>
                        <dd class="font-semibold text-black-900"><x-gm.money :amount="$availableBalance" /></dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Minimum</dt>
                        <dd class="font-semibold text-black-900"><x-gm.money :amount="$minimum" /></dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Biaya admin</dt>
                        <dd class="font-semibold text-black-900"><x-gm.money :amount="$fee" /></dd>
                    </div>
                </dl>

                <form method="post" action="{{ route('mad-trans.agency.withdrawals.store', $business) }}" class="space-y-3">
                    @csrf
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Nominal</span>
                        <input type="number" name="amount" min="{{ (int) $minimum }}" step="1000" required
                               value="{{ old('amount') }}" placeholder="{{ (int) $minimum }}">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Kode bank</span>
                        <input type="text" name="bank_code" maxlength="30" required placeholder="BCA"
                               value="{{ old('bank_code') }}">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Nomor rekening</span>
                        <input type="text" name="bank_account_number" maxlength="34" required
                               value="{{ old('bank_account_number') }}">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Nama pemilik</span>
                        <input type="text" name="bank_account_name" maxlength="120" required
                               value="{{ old('bank_account_name') }}">
                    </label>

                    <x-gm.button type="submit" variant="brand" class="w-full">Kirim permintaan</x-gm.button>
                </form>

                <p class="mt-3 text-xs text-gray-500">
                    Permintaan ditinjau tim keuangan. Dana dikirim ke rekening yang terdaftar
                    setelah disetujui.
                </p>
            </x-gm.card>
        </aside>
    </div>
@endsection
