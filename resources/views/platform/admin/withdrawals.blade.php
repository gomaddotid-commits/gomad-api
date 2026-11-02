@extends('layouts.app')

@section('title', 'Pencairan · Konsol operasional')

@section('content')
    <x-gm.page-header
        eyebrow="Konsol operasional"
        title="Pencairan mitra"
        subtitle="Setujui, tandai sudah dibayar dengan referensi transfer, atau gagalkan dengan alasan.">
        <x-slot:actions>
            <a href="{{ route('admin.reconciliation') }}">
                <x-gm.button type="button" variant="secondary">Cek rekonsiliasi</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @include('platform.admin._nav', ['active' => 'withdrawals'])

    @php
        $tabs = [
            null => 'Semua',
            'pending' => 'Menunggu',
            'approved' => 'Disetujui',
            'paid' => 'Dibayar',
            'failed' => 'Gagal',
            'rejected' => 'Ditolak',
        ];
    @endphp

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach ($tabs as $value => $label)
            @php $isActive = $status === $value; @endphp
            <a href="{{ route('admin.withdrawals', array_filter(['status' => $value])) }}"
               class="rounded-pill border px-3 py-1.5 text-xs font-semibold
                      {{ $isActive ? 'border-black-900 bg-black-900 text-white' : 'border-gray-300 bg-white text-gray-600 hover:text-black-900' }}">
                {{ $label }}
                @if ($value !== null && isset($counts[$value]))
                    ({{ $counts[$value] }})
                @endif
            </a>
        @endforeach
    </div>

    @if ($withdrawals->isEmpty())
        <x-gm.empty title="Tidak ada pencairan di filter ini" description="Antrean kosong — tidak ada yang menunggu keputusan." />
    @else
        <div class="space-y-3">
            @foreach ($withdrawals as $withdrawal)
                @php
                    $tone = match ($withdrawal->status) {
                        'paid' => 'success',
                        'pending', 'approved' => 'warning',
                        'failed', 'rejected' => 'error',
                        default => 'neutral',
                    };
                @endphp
                <x-gm.card :padded="false">
                    <div class="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
                        <div>
                            <p class="text-sm font-semibold text-black-900">
                                {{ $withdrawal->code }} · {{ $withdrawal->business?->name ?? '—' }}
                                <span class="font-normal text-gray-600">({{ $withdrawal->business?->type }})</span>
                            </p>
                            <p class="mt-1 text-xs text-gray-600">
                                Diajukan {{ $withdrawal->created_at->format('d M Y H:i') }}
                                @if ($withdrawal->approved_at) · disetujui {{ $withdrawal->approved_at->format('d M H:i') }} @endif
                                @if ($withdrawal->paid_at) · dibayar {{ $withdrawal->paid_at->format('d M H:i') }} @endif
                            </p>
                            <p class="mt-1 text-xs text-gray-600">
                                {{ strtoupper((string) $withdrawal->bank_code) }}
                                ····{{ substr((string) $withdrawal->bank_account_number, -4) }}
                                · biaya <x-gm.money :amount="$withdrawal->fee_amount" />
                            </p>
                            @if ($withdrawal->failure_message)
                                <p class="mt-2 text-xs text-error">
                                    {{ $withdrawal->failure_code }}: {{ $withdrawal->failure_message }}
                                </p>
                            @endif
                        </div>
                        <div class="text-right">
                            <p class="text-base font-bold text-black-900"><x-gm.money :amount="$withdrawal->net_amount" /></p>
                            <p class="text-xs text-gray-500">dari <x-gm.money :amount="$withdrawal->amount" /></p>
                            <p class="mt-1"><x-gm.badge :tone="$tone">{{ ucfirst((string) $withdrawal->status) }}</x-gm.badge></p>
                        </div>
                    </div>

                    @if (in_array($withdrawal->status, ['pending', 'approved'], true))
                        <div class="flex flex-wrap items-start gap-3 border-t border-gray-200 px-5 py-3">
                            @if ($withdrawal->status === 'pending')
                                <form method="post" action="{{ route('admin.withdrawals.approve', $withdrawal) }}">
                                    @csrf
                                    <x-gm.button type="submit" size="sm" variant="brand">Setujui</x-gm.button>
                                </form>
                            @endif

                            @if ($withdrawal->status === 'approved')
                                <form method="post" action="{{ route('admin.withdrawals.complete', $withdrawal) }}"
                                      class="flex items-end gap-2">
                                    @csrf
                                    <label class="block">
                                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Referensi transfer</span>
                                        <input type="text" name="transfer_reference" maxlength="100" required placeholder="TRX-BANK-123">
                                    </label>
                                    <x-gm.button type="submit" size="sm" variant="brand">Tandai dibayar</x-gm.button>
                                </form>
                            @endif

                            <form method="post" action="{{ route('admin.withdrawals.fail', $withdrawal) }}"
                                  class="flex flex-wrap items-end gap-2">
                                @csrf
                                <label class="block">
                                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Kode gagal</span>
                                    <input type="text" name="failure_code" maxlength="80" required placeholder="BANK_REJECTED">
                                </label>
                                <label class="block">
                                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Pesan</span>
                                    <input type="text" name="failure_message" maxlength="500" required placeholder="Rekening tidak aktif">
                                </label>
                                <x-gm.button type="submit" size="sm" variant="danger">Gagalkan</x-gm.button>
                            </form>
                        </div>
                    @endif
                </x-gm.card>
            @endforeach
        </div>

        <div class="mt-4">{{ $withdrawals->links() }}</div>
    @endif
@endsection
