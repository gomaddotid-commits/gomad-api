@extends('layouts.app')

@section('title', 'Sewa kendaraan — '.$business->name)

@section('content')
    <div class="card">
        <h1>Sewa kendaraan</h1>
        <p class="muted">{{ $business->name }} · {{ $business->code }}</p>
        <p class="muted">
            Sewa <strong>dengan supir</strong> atau <strong>lepas kunci</strong> (butuh KTP, SIM, dan selfie
            yang sudah diverifikasi admin GoMad).
        </p>
        <p><a href="{{ route('rental-documents.index') }}">Kelola dokumen lepas kunci →</a></p>
        <p><a href="{{ route('mad-trans.index') }}">← Kembali ke tiket travel</a></p>
    </div>

    @if ($vehicles->isEmpty())
        <div class="card">
            <p class="muted">Belum ada kendaraan dengan tarif sewa. Hubungi agency.</p>
        </div>
    @else
        <div class="card">
            <h2>Armada</h2>
            @foreach ($vehicles as $vehicle)
                <div class="row" style="border-top:1px solid #e6edee;padding:12px 0">
                    <div>
                        <strong>{{ $vehicle->name }}</strong><br>
                        <span class="muted">{{ $vehicle->plate_number }} · {{ $vehicle->capacity }} kursi</span>
                    </div>
                    <div>
                        <span class="muted">
                            Rp {{ number_format((float) $vehicle->rental_daily_price, 0, ',', '.') }}/hari
                            + supir Rp {{ number_format((float) ($vehicle->rental_driver_daily_fee ?? 0), 0, ',', '.') }}/hari
                            @if ((float) ($vehicle->rental_deposit ?? 0) > 0)
                                · deposit Rp {{ number_format((float) $vehicle->rental_deposit, 0, ',', '.') }} (dikembalikan)
                            @endif
                        </span><br>
                        <a href="{{ route('rental.calendar', [$business, $vehicle]) }}">Lihat kalender ketersediaan →</a>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="card">
            <h2>Ajukan sewa</h2>
            <form method="post" action="{{ route('rental.store', $business) }}">
                @csrf

                <label for="rental_type">Jenis sewa</label>
                <select id="rental_type" name="rental_type">
                    <option value="with_driver">Dengan supir</option>
                    @if ($selfDriveReady)
                        <option value="self_drive">Lepas kunci</option>
                    @endif
                </select>

                <label for="vehicle_id">Kendaraan</label>
                <select id="vehicle_id" name="vehicle_id" required>
                    @foreach ($vehicles as $vehicle)
                        <option value="{{ $vehicle->getKey() }}">
                            {{ $vehicle->name }} — Rp {{ number_format((float) $vehicle->rental_daily_price, 0, ',', '.') }}/hari
                        </option>
                    @endforeach
                </select>

                <label for="driver_membership_id">Supir</label>
                <select id="driver_membership_id" name="driver_membership_id">
                    <option value="">Tanpa supir (lepas kunci)</option>
                    @foreach ($drivers as $driver)
                        <option value="{{ $driver->pivot->id }}">{{ $driver->name }}</option>
                    @endforeach
                </select>

                <label for="start_at">Mulai</label>
                <input id="start_at" type="datetime-local" name="start_at"
                       value="{{ old('start_at', now()->addDay()->setTime(8, 0)->format('Y-m-d\TH:i')) }}" required>

                <label for="end_at">Selesai</label>
                <input id="end_at" type="datetime-local" name="end_at"
                       value="{{ old('end_at', now()->addDays(2)->setTime(8, 0)->format('Y-m-d\TH:i')) }}" required>

                <label for="payment_mode">Cara bayar</label>
                <select id="payment_mode" name="payment_mode">
                    <option value="online">Bayar online</option>
                    <option value="ots">Bayar di tempat (OTS)</option>
                </select>

                <label for="promo_code">Kode promo (opsional, hanya untuk pembayaran online)</label>
                <input id="promo_code" name="promo_code" maxlength="40" value="{{ old('promo_code') }}"
                       placeholder="Contoh: HEMAT10">

                <button type="submit">Ajukan sewa</button>
            </form>
        </div>

        <div class="card">
            <h2>Ajukan charter</h2>
            <p class="muted">
                Charter memakai armada penuh dengan supir untuk tujuan bebas. Tuliskan rencana
                perjalanan agar agency bisa menyiapkan armada yang sesuai.
            </p>
            <form method="post" action="{{ route('rental.store', $business) }}">
                @csrf
                <input type="hidden" name="rental_type" value="charter">

                <label for="charter_vehicle_id">Kendaraan</label>
                <select id="charter_vehicle_id" name="vehicle_id" required>
                    @foreach ($vehicles as $vehicle)
                        <option value="{{ $vehicle->getKey() }}">
                            {{ $vehicle->name }} — Rp {{ number_format((float) $vehicle->rental_daily_price, 0, ',', '.') }}/hari
                        </option>
                    @endforeach
                </select>

                <label for="charter_driver_membership_id">Supir</label>
                <select id="charter_driver_membership_id" name="driver_membership_id" required>
                    @foreach ($drivers as $driver)
                        <option value="{{ $driver->pivot->id }}">{{ $driver->name }}</option>
                    @endforeach
                </select>

                <label for="itinerary">Tujuan / rencana perjalanan</label>
                <input id="itinerary" name="itinerary" value="{{ old('itinerary') }}" required maxlength="500"
                       placeholder="Contoh: Surabaya – Malang – Batu, 2 hari">

                <label for="charter_start_at">Mulai</label>
                <input id="charter_start_at" type="datetime-local" name="start_at"
                       value="{{ old('start_at', now()->addDay()->setTime(8, 0)->format('Y-m-d\TH:i')) }}" required>

                <label for="charter_end_at">Selesai</label>
                <input id="charter_end_at" type="datetime-local" name="end_at"
                       value="{{ old('end_at', now()->addDays(2)->setTime(8, 0)->format('Y-m-d\TH:i')) }}" required>

                <button type="submit">Ajukan charter</button>
            </form>
        </div>
    @endif

    <div class="card">
        <h2>Sewa saya</h2>
        @forelse ($rentals as $rental)
            <div class="row" style="border-top:1px solid #e6edee;padding:12px 0">
                <div>
                    <strong>{{ $rental->code }}</strong><br>
                    <span class="muted">
                        {{ $rental->rental_type === 'charter' ? 'Charter' : 'Sewa' }} ·
                        {{ $rental->vehicle->name }} ·
                        {{ $rental->start_at->toDateTimeString() }} → {{ $rental->end_at->toDateTimeString() }} ·
                        {{ $rental->days }} hari
                    </span>
                    @if ($rental->itinerary)
                        <br><span class="muted">{{ $rental->itinerary }}</span>
                    @endif
                </div>
                <div>
                    <strong>{{ $rental->status }}</strong><br>
                    <span class="muted">Rp {{ number_format((float) $rental->total_price, 0, ',', '.') }}</span>
                    @if ((float) $rental->deposit_amount > 0)
                        <br><span class="muted">deposit {{ $rental->deposit_status }}</span>
                        @if ((float) $rental->deposit_deducted_amount > 0)
                            <br>
                            <span class="muted">
                                dipotong Rp {{ number_format((float) $rental->deposit_deducted_amount, 0, ',', '.') }}
                                · dikembalikan Rp {{ number_format((float) $rental->deposit_refunded_amount, 0, ',', '.') }}
                                @if ($rental->deposit_deduction_reason)
                                    · {{ $rental->deposit_deduction_reason }}
                                @endif
                            </span>
                        @endif
                    @endif

                    @if ($rental->status === 'pending' && $rental->payment_mode === 'online')
                        <form class="inline" method="post" action="{{ route('rental.pay', [$business, $rental]) }}">
                            @csrf
                            <button type="submit">Bayar online</button>
                        </form>
                    @elseif ($rental->status === 'pending')
                        <br><span class="muted">bayar di tempat</span>
                    @endif
                </div>
            </div>
        @empty
            <p class="muted">Belum ada sewa.</p>
        @endforelse
    </div>
@endsection
