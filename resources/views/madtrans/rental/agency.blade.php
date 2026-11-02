@extends('layouts.app')

@section('title', 'Sewa kendaraan '.$business->name)

@section('content')
    <x-gm.page-header
        eyebrow="Ruang kerja agency"
        title="Sewa kendaraan"
        :subtitle="$business->name.' · pesanan sewa lepas dengan atau tanpa supir'">
        <x-slot:actions>
            <a href="{{ route('mad-trans.agency.fleet', $business) }}">
                <x-gm.button type="button" variant="secondary">Atur tarif armada</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @include('madtrans.travel._nav', ['business' => $business, 'active' => 'rental'])

    @if ($rentableVehicles->isEmpty())
        <div class="mb-5">
            <x-gm.alert tone="warning">
                Belum ada kendaraan dengan tarif sewa harian. Tetapkan tarif di halaman armada
                agar penumpang bisa menyewa kendaraan Anda.
            </x-gm.alert>
        </div>
    @endif

    @if ($rentals->isEmpty())
        <x-gm.empty title="Belum ada pesanan sewa"
            description="Pesanan sewa muncul setelah pelanggan memesan kendaraan Anda." />
    @else
        <div class="space-y-3">
            @foreach ($rentals as $rental)
                @php
                    $tone = match ($rental->status) {
                        'paid', 'active' => 'success',
                        'pending' => 'warning',
                        'cancelled' => 'error',
                        'completed' => 'info',
                        default => 'neutral',
                    };
                @endphp

                <x-gm.card :padded="false">
                    <div class="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
                        <div>
                            <p class="text-sm font-semibold text-black-900">
                                {{ $rental->code }} · {{ $rental->vehicle?->name ?? 'Kendaraan' }}
                                <span class="font-normal text-gray-600">({{ $rental->vehicle?->plate_number }})</span>
                            </p>
                            <p class="mt-1 text-xs text-gray-600">
                                {{ $rental->start_at?->format('d M Y H:i') }} → {{ $rental->end_at?->format('d M Y H:i') }}
                                @if ($rental->with_driver) · dengan supir @endif
                            </p>
                            <p class="mt-1 text-xs text-gray-600">
                                Pelanggan: {{ $rental->customer?->name ?? '—' }}
                                @if ($rental->driverMembership?->user) · Supir: {{ $rental->driverMembership->user->name }} @endif
                            </p>
                            <p class="mt-2 flex flex-wrap items-center gap-2">
                                <x-gm.badge :tone="$tone">{{ ucfirst((string) $rental->status) }}</x-gm.badge>
                                <span class="text-xs text-gray-500">Deposit {{ $rental->deposit_status }}</span>
                            </p>
                        </div>

                        <div class="text-right">
                            <p class="text-base font-bold text-black-900"><x-gm.money :amount="$rental->price" /></p>
                            @if ((float) $rental->deposit_amount > 0)
                                <p class="text-xs text-gray-600">+ deposit <x-gm.money :amount="$rental->deposit_amount" /></p>
                            @endif
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 border-t border-gray-200 px-5 py-3">
                        <a href="{{ route('mad-trans.agency.rentals.documents', [$business, $rental]) }}">
                            <x-gm.button type="button" size="sm" variant="ghost">Dokumen</x-gm.button>
                        </a>

                        @if ($rental->status === 'pending')
                            <form method="post" action="{{ route('mad-trans.agency.rentals.paid', [$business, $rental]) }}">
                                @csrf
                                <x-gm.button type="submit" size="sm" variant="secondary">Tandai dibayar</x-gm.button>
                            </form>
                            <form method="post" action="{{ route('mad-trans.agency.rentals.cancel', [$business, $rental]) }}"
                                  onsubmit="return confirm('Batalkan pesanan sewa ini?')">
                                @csrf
                                <x-gm.button type="submit" size="sm" variant="danger">Batalkan</x-gm.button>
                            </form>
                        @endif

                        @if ($rental->status === 'paid')
                            <form method="post" action="{{ route('mad-trans.agency.rentals.start', [$business, $rental]) }}">
                                @csrf
                                <x-gm.button type="submit" size="sm" variant="brand">Mulai sewa</x-gm.button>
                            </form>
                        @endif

                        @if ($rental->status === 'active')
                            <form method="post" action="{{ route('mad-trans.agency.rentals.return', [$business, $rental]) }}">
                                @csrf
                                <x-gm.button type="submit" size="sm" variant="secondary">Kendaraan dikembalikan</x-gm.button>
                            </form>
                        @endif

                        @if ($rental->status === 'returned')
                            <form method="post" action="{{ route('mad-trans.agency.rentals.complete', [$business, $rental]) }}">
                                @csrf
                                <x-gm.button type="submit" size="sm" variant="brand">Selesaikan &amp; lepas deposit</x-gm.button>
                            </form>
                        @endif
                    </div>
                </x-gm.card>
            @endforeach
        </div>

        <div class="mt-4">{{ $rentals->links() }}</div>
    @endif
@endsection
