@extends('layouts.app')

@section('title', 'Riwayat sewa')

@section('content')
    <x-gm.page-header
        eyebrow="Akun"
        title="Riwayat sewa"
        subtitle="Semua penyewaan kendaraan Anda, dari semua agency."
        :actions="null">
        <x-slot:actions>
            <a href="{{ route('account.show') }}">
                <x-gm.button type="button" variant="secondary">Kembali ke profil</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @if ($rentals->isEmpty())
        <x-gm.empty
            title="Belum ada penyewaan"
            description="Sewa kendaraan dengan supir, lepas kunci, atau charter muncul di sini setelah dipesan." />
    @else
        <div class="space-y-3">
            @foreach ($rentals as $rental)
                <div class="rounded-lg border border-gray-200 bg-white px-5 py-4">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-base font-semibold text-black-900">
                                {{ $rental->vehicle?->name ?? 'Kendaraan' }}
                                <span class="text-sm font-normal text-gray-600">
                                    {{ $rental->vehicle?->plate_number }}
                                </span>
                            </p>
                            <p class="mt-1 text-sm text-gray-600">
                                {{ $rental->business?->name }} ·
                                {{ $rental->start_at?->toDateString() }} → {{ $rental->end_at?->toDateString() }}
                                ({{ (int) $rental->days }} hari)
                            </p>
                            <p class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                <x-gm.badge :tone="$rental->status === 'completed' ? 'success' : ($rental->status === 'cancelled' ? 'error' : 'info')">
                                    {{ ucfirst((string) $rental->status) }}
                                </x-gm.badge>
                                <x-gm.badge tone="neutral">{{ str_replace('_', ' ', (string) $rental->rental_type) }}</x-gm.badge>
                                @if ((float) $rental->deposit_amount > 0)
                                    <span class="text-gray-500">
                                        Deposit <x-gm.money :amount="$rental->deposit_amount" />
                                        · {{ $rental->deposit_status }}
                                    </span>
                                @endif
                            </p>
                        </div>

                        <div class="text-right">
                            <p class="text-lg font-bold text-black-900"><x-gm.money :amount="$rental->total_price" /></p>
                            <p class="text-xs text-gray-500">{{ $rental->code }}</p>

                            <a href="{{ route('rental.show', $rental->business_id) }}" class="mt-3 inline-block">
                                <x-gm.button type="button" size="sm" variant="secondary">Sewa lagi</x-gm.button>
                            </a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-6">{{ $rentals->links() }}</div>
    @endif
@endsection
