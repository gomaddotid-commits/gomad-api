@extends('layouts.app')

@section('title', 'Konsol operasional')

@section('content')
    <x-gm.page-header
        eyebrow="Konsol operasional"
        title="Dasbor operasional"
        subtitle="Antrean yang menunggu keputusan manusia, kesehatan ledger, dan konfigurasi yang belum lengkap.">
        <x-slot:actions>
            <a href="{{ route('admin.withdrawals') }}">
                <x-gm.button type="button" variant="brand">Tinjau pencairan</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @include('platform.admin._nav', ['active' => 'dashboard'])

    @if ($warnings !== [])
        <div class="mb-5 space-y-2">
            @foreach ($warnings as $warning)
                <x-gm.alert tone="warning">{{ $warning }}</x-gm.alert>
            @endforeach
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-gm.stat label="Pencairan menunggu" :value="$queues['withdrawals']" tone="warning"
            hint="Perlu persetujuan atau penolakan" />
        <x-gm.stat label="Siap ditransfer" :value="$queues['withdrawalApproved']"
            hint="Sudah disetujui, belum dibayar" />
        <x-gm.stat label="Dokumen sewa" :value="$queues['documents']" hint="Menunggu verifikasi KTP/SIM/selfie" />
        <x-gm.stat label="Mitra belum terverifikasi" :value="$queues['unverifiedPartners']"
            hint="Belum boleh melayani transaksi" />
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-gm.stat label="Pesanan warung menunggu" :value="$queues['pendingApproval']"
            hint="Menunggu persetujuan pemilik warung" />
        <x-gm.stat label="Tiket dukungan" :value="$queues['supportOpen']"
            hint="Terbuka atau menunggu pengguna" tone="warning" />
        <x-gm.stat label="Pembayaran macet" :value="$queues['stalePayments']"
            hint="Pending lebih dari 2 jam" tone="warning" />
        <x-gm.stat label="Akun tidak cocok" :value="$ledgerMismatchCount"
            :tone="$ledgerMismatchCount > 0 ? 'error' : 'success'" hint="Hasil rekonsiliasi ledger" />
        <x-gm.stat label="Transaksi tidak balance" :value="$unbalancedCount"
            :tone="$unbalancedCount > 0 ? 'error' : 'success'" hint="Debit ≠ kredit" />
    </div>

    <div class="mt-5 grid gap-5 lg:grid-cols-2">
        <x-gm.card title="Pencairan terbaru" :padded="false">
            <div class="divide-y divide-gray-100">
                @forelse ($recentWithdrawals as $withdrawal)
                    @php
                        $tone = match ($withdrawal->status) {
                            'paid' => 'success',
                            'pending', 'approved' => 'warning',
                            'failed', 'rejected' => 'error',
                            default => 'neutral',
                        };
                    @endphp
                    <div class="flex items-center justify-between gap-3 px-5 py-3 text-sm">
                        <div>
                            <p class="font-semibold text-black-900">{{ $withdrawal->code }}</p>
                            <p class="text-xs text-gray-600">
                                {{ $withdrawal->business?->name ?? '—' }} ·
                                {{ $withdrawal->created_at->format('d M Y H:i') }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-gm.badge :tone="$tone">{{ ucfirst((string) $withdrawal->status) }}</x-gm.badge>
                            <x-gm.money :amount="$withdrawal->amount" />
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-6 text-sm text-gray-600">Tidak ada pencairan.</div>
                @endforelse
            </div>
            <div class="border-t border-gray-200 px-5 py-3">
                <a href="{{ route('admin.withdrawals') }}" class="text-sm font-semibold text-black-900 hover:underline">
                    Semua pencairan →
                </a>
            </div>
        </x-gm.card>

        <x-gm.card title="Dokumen menunggu verifikasi" :padded="false">
            <div class="divide-y divide-gray-100">
                @forelse ($recentDocuments as $document)
                    <div class="flex items-center justify-between gap-3 px-5 py-3 text-sm">
                        <div>
                            <p class="font-semibold text-black-900">{{ $document->label() }} · {{ $document->user?->name ?? '—' }}</p>
                            <p class="text-xs text-gray-600">{{ $document->created_at->format('d M Y H:i') }}</p>
                        </div>
                        <x-gm.badge tone="warning">Menunggu</x-gm.badge>
                    </div>
                @empty
                    <div class="px-5 py-6 text-sm text-gray-600">Tidak ada dokumen menunggu.</div>
                @endforelse
            </div>
            <div class="border-t border-gray-200 px-5 py-3">
                <a href="{{ route('admin.rental-documents.index') }}"
                   class="text-sm font-semibold text-black-900 hover:underline">Buka verifikasi dokumen →</a>
            </div>
        </x-gm.card>

        @if ($ledgerMismatchCount > 0 || $unbalancedCount > 0)
            <x-gm.card title="Selisih ledger" subtitle="Angka ini harus nol. Bila tidak, hentikan pencairan baru." :padded="false"
                class="lg:col-span-2">
                <div class="divide-y divide-gray-100">
                    @foreach ($ledgerMismatches as $row)
                        <div class="flex items-center justify-between gap-3 px-5 py-3 text-sm">
                            <span class="font-medium text-black-900">{{ $row['account_code'] }}</span>
                            <span class="text-xs text-error">
                                tercatat {{ $row['cached_balance'] }} · seharusnya {{ $row['reconstructed_balance'] }}
                            </span>
                        </div>
                    @endforeach
                </div>
                <div class="border-t border-gray-200 px-5 py-3">
                    <a href="{{ route('admin.reconciliation') }}" class="text-sm font-semibold text-black-900 hover:underline">
                        Buka rekonsiliasi →
                    </a>
                </div>
            </x-gm.card>
        @endif
    </div>
@endsection
