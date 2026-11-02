@extends('layouts.app')

@section('title', 'Pesanan '.$business->name)

@section('content')
    <x-gm.page-header
        eyebrow="Ruang kerja agency"
        title="Pesanan & penumpang"
        :subtitle="$business->name.' · semua pemesanan untuk jadwal Anda'">
        <x-slot:actions>
            <a href="{{ route('mad-trans.agency.reports', $business) }}">
                <x-gm.button type="button" variant="secondary">Laporan</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @include('madtrans.travel._nav', ['business' => $business, 'active' => 'bookings'])

    @php
        $tabs = [
            null => 'Semua',
            'pending' => 'Menunggu bayar',
            'paid' => 'Dibayar',
            'confirmed' => 'Terkonfirmasi',
            'on_going' => 'Berjalan',
            'completed' => 'Selesai',
            'cancelled' => 'Dibatalkan',
        ];
    @endphp

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach ($tabs as $value => $label)
            @php $isActive = $status === $value; @endphp
            <a href="{{ route('mad-trans.agency.bookings', array_filter(['business' => $business, 'status' => $value])) }}"
               class="rounded-pill border px-3 py-1.5 text-xs font-semibold
                      {{ $isActive ? 'border-black-900 bg-black-900 text-white' : 'border-gray-300 bg-white text-gray-600 hover:text-black-900' }}">
                {{ $label }}
                @if ($value !== null && isset($counts[$value]))
                    ({{ $counts[$value] }})
                @endif
            </a>
        @endforeach
    </div>

    @if ($bookings->isEmpty())
        <x-gm.empty title="Belum ada pesanan"
            description="Pesanan muncul setelah penumpang memesan jadwal yang Anda terbitkan." />
    @else
        <div class="space-y-3">
            @foreach ($bookings as $booking)
                @php
                    $tone = match ($booking->status) {
                        'paid', 'confirmed' => 'success',
                        'pending' => 'warning',
                        'cancelled' => 'error',
                        'on_going' => 'info',
                        default => 'neutral',
                    };
                    $statusLabel = match ($booking->status) {
                        'pending' => 'Menunggu bayar',
                        'paid' => 'Dibayar',
                        'confirmed' => 'Terkonfirmasi',
                        'on_going' => 'Berjalan',
                        'completed' => 'Selesai',
                        'cancelled' => 'Dibatalkan',
                        default => ucfirst((string) $booking->status),
                    };
                @endphp

                <x-gm.card :padded="false">
                    <div class="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-black-900">
                                {{ $booking->code }} ·
                                {{ $booking->schedule?->route?->origin_city ?? '—' }} → {{ $booking->schedule?->route?->destination_city ?? '—' }}
                            </p>
                            <p class="mt-1 text-xs text-gray-600">
                                {{ $booking->schedule?->departure_date?->toDateString() ?? '—' }}
                                {{ substr((string) $booking->schedule?->departure_time, 0, 5) }}
                                · {{ $booking->payment_method === 'cod' ? 'COD' : 'Online' }}
                                · {{ $booking->passengers->count() }} penumpang
                            </p>
                            <p class="mt-2 flex flex-wrap gap-2 text-xs">
                                <x-gm.badge :tone="$tone">{{ $statusLabel }}</x-gm.badge>
                                @foreach ($booking->passengers as $passenger)
                                    <span class="rounded-sm bg-gray-100 px-2 py-0.5 text-gray-600">
                                        {{ $passenger->name }} · {{ $passenger->seat_snapshot ?? '—' }}
                                        @if ($passenger->boarded_at !== null) ✓ @endif
                                    </span>
                                @endforeach
                            </p>
                        </div>

                        <div class="text-right">
                            <p class="text-base font-bold text-black-900"><x-gm.money :amount="$booking->total_price" /></p>
                        </div>
                    </div>

                    @if (in_array($booking->status, ['paid', 'confirmed'], true))
                        <div class="border-t border-gray-200 px-5 py-3">
                            <form method="post"
                                  action="{{ route('mad-trans.agency.bookings.transfer', [$business, $booking]) }}"
                                  class="flex flex-wrap items-end gap-3">
                                @csrf
                                <label class="min-w-[16rem] flex-1">
                                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        Pindahkan ke jadwal lain
                                    </span>
                                    <select name="to_schedule_id" required>
                                        @foreach ($transferTargets as $candidate)
                                            @continue($candidate->id === $booking->schedule_id)
                                            <option value="{{ $candidate->id }}">
                                                {{ $candidate->departure_date->toDateString() }}
                                                {{ substr((string) $candidate->departure_time, 0, 5) }}
                                            </option>
                                        @endforeach
                                    </select>
                                </label>
                                <x-gm.button type="submit" size="sm" variant="secondary">Pindahkan</x-gm.button>
                            </form>
                        </div>
                    @endif
                </x-gm.card>
            @endforeach
        </div>

        <div class="mt-4">{{ $bookings->links() }}</div>
    @endif
@endsection
