@extends('layouts.app')

@section('title', 'Rekonsiliasi · Konsol operasional')

@section('content')
    <x-gm.page-header
        eyebrow="Konsol operasional"
        title="Rekonsiliasi ledger"
        :subtitle="'Diperiksa '.$checkedAt->format('d M Y H:i').' — saldo tercatat dibandingkan ulang dari entri.'">
        <x-slot:actions>
            <a href="{{ route('admin.reconciliation') }}">
                <x-gm.button type="button" variant="brand">Periksa ulang</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @include('platform.admin._nav', ['active' => 'reconciliation'])

    <div class="mb-5 grid gap-4 sm:grid-cols-3">
        <x-gm.stat label="Akun diperiksa" :value="$accounts->count()" />
        <x-gm.stat label="Akun tidak cocok" :value="$mismatchCount"
            :tone="$mismatchCount > 0 ? 'error' : 'success'" hint="Harus nol" />
        <x-gm.stat label="Transaksi tidak balance" :value="count($unbalanced)"
            :tone="count($unbalanced) > 0 ? 'error' : 'success'" hint="Debit ≠ kredit" />
    </div>

    @if ($mismatchCount > 0 || count($unbalanced) > 0)
        <div class="mb-5">
            <x-gm.alert tone="error">
                Ada selisih. Hentikan pencairan baru dan periksa transaksi yang ditandai sebelum menjalankan
                tindakan uang berikutnya.
            </x-gm.alert>
        </div>
    @endif

    <x-gm.card title="Semua akun" :padded="false">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                        <th class="px-5 py-2">Akun</th>
                        <th class="px-5 py-2 text-right">Saldo tercatat</th>
                        <th class="px-5 py-2 text-right">Hasil hitung ulang</th>
                        <th class="px-5 py-2">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($accounts as $row)
                        <tr>
                            <td class="px-5 py-2.5 font-medium text-black-900">{{ $row['account_code'] }}</td>
                            <td class="whitespace-nowrap px-5 py-2.5 text-right"><x-gm.money :amount="$row['cached_balance']" /></td>
                            <td class="whitespace-nowrap px-5 py-2.5 text-right text-gray-600">
                                <x-gm.money :amount="$row['reconstructed_balance']" />
                            </td>
                            <td class="px-5 py-2.5">
                                <x-gm.badge :tone="$row['matches'] ? 'success' : 'error'">
                                    {{ $row['matches'] ? 'Cocok' : 'Tidak cocok' }}
                                </x-gm.badge>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-gm.card>

    @if (count($unbalanced) > 0)
        <div class="mt-5">
            <x-gm.card title="Transaksi tidak balance" subtitle="Debit dan kredit pada transaksi ini tidak sama." :padded="false">
                <div class="flex flex-wrap gap-2 px-5 py-4">
                    @foreach ($unbalanced as $transactionId)
                        <span class="rounded-sm bg-error/10 px-2 py-1 text-xs font-semibold text-error">
                            #{{ $transactionId }}
                        </span>
                    @endforeach
                </div>
            </x-gm.card>
        </div>
    @endif
@endsection
