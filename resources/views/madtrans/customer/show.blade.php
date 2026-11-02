@extends('layouts.app')

@section('title', 'Pilih kursi '.$schedule->route->origin_city.' → '.$schedule->route->destination_city)

@section('content')
    <x-gm.page-header
        eyebrow="Mad Trans"
        :title="$schedule->route->origin_city.' → '.$schedule->route->destination_city"
        :subtitle="$schedule->business->name.' · '.$schedule->departure_date->toDateString().' '.substr((string) $schedule->departure_time, 0, 5).' · '.$schedule->vehicle->name">
        <x-slot:actions>
            <a href="{{ route('mad-trans.index') }}">
                <x-gm.button type="button" variant="secondary">Cari jadwal lain</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    <form method="post" action="{{ route('mad-trans.bookings.store', $schedule) }}" class="grid gap-5 lg:grid-cols-3">
        @csrf
        <input type="hidden" name="booking_token" value="{{ $bookingToken }}">

        <div class="space-y-5 lg:col-span-2">
            <x-gm.card title="Denah kursi" subtitle="Kursi bertanda coret sudah terisi dan tidak bisa dipilih.">
                @php
                    $rows = collect($layout)
                        ->groupBy(fn (string $seat): string => preg_replace('/\d+/', '', $seat))
                        ->sortKeys();
                @endphp

                <div class="space-y-2">
                    @if ($vehicle?->has_driver_seat)
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="w-5 text-xs font-bold uppercase text-gray-500">S</span>
                            <span class="inline-flex h-9 items-center justify-center rounded-md border border-dashed border-gray-400 bg-gray-100 px-3 text-xs font-semibold text-gray-500">
                                Kursi supir
                            </span>
                        </div>
                    @endif

                    @foreach ($rows as $rowLabel => $rowSeats)
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="w-5 text-xs font-bold uppercase text-gray-500">{{ $rowLabel }}</span>

                            @foreach ($rowSeats->sortBy(fn (string $seat): int => (int) preg_replace('/\D+/', '', $seat)) as $seat)
                                @php $isTaken = isset($taken[$seat]); @endphp
                                <label class="gm-seat {{ $isTaken ? 'gm-seat--taken' : '' }}">
                                    <input type="checkbox" name="seat_pick[]" value="{{ $seat }}" @disabled($isTaken)>
                                    <span>{{ $seat }}</span>
                                </label>
                            @endforeach
                        </div>
                    @endforeach

                    @if (count($layout) === 0)
                        <p class="text-sm text-gray-600">Armada ini belum memiliki denah kursi.</p>
                    @endif
                </div>
            </x-gm.card>

            <x-gm.card title="Rute &amp; pembayaran">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label>
                        <span class="text-sm font-semibold text-black-900">Naik di</span>
                        <select id="origin_stop_id" name="origin_stop_id" required>
                            @foreach ($stops as $stop)
                                <option value="{{ $stop->route_stop_id }}" data-order="{{ $stop->stop_order }}">
                                    {{ $stop->routeStop->name }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label>
                        <span class="text-sm font-semibold text-black-900">Turun di</span>
                        <select id="destination_stop_id" name="destination_stop_id" required>
                            @foreach ($stops as $stop)
                                <option value="{{ $stop->route_stop_id }}" data-order="{{ $stop->stop_order }}" @selected($loop->last)>
                                    {{ $stop->routeStop->name }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <div class="sm:col-span-2">
                        <p class="rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-600" id="segment-fare">
                            Pilih titik naik dan turun untuk melihat tarif segmen ini.
                        </p>
                    </div>

                    <label>
                        <span class="text-sm font-semibold text-black-900">Metode pembayaran</span>
                        <select id="payment_method" name="payment_method" required>
                            <option value="midtrans">Bayar online (Midtrans)</option>
                            @if ($schedule->allow_cod)
                                <option value="cod">Bayar tunai ke supir (COD)</option>
                            @endif
                        </select>
                    </label>

                    <label>
                        <span class="text-sm font-semibold text-black-900">Kode promo</span>
                        <input id="promo_code" name="promo_code" maxlength="40" value="{{ old('promo_code') }}"
                               placeholder="Opsional, untuk pembayaran online">
                    </label>
                </div>
            </x-gm.card>

            {{--
                Door to door (opsional).

                Titik kumpul tetap titik berat perjalanan; pelanggan boleh minta
                dijemput di luar itu. Sampai radius tertentu gratis, selebihnya
                dihitung per kilometer — angkanya **selalu** dari server
                (`pickup-quote`), halaman ini hanya menampilkan.

                Peta memakai OpenStreetMap lewat tautan biasa: tidak ada biaya
                peta, dan pelanggan memakai aplikasi peta yang sudah ia punya.
            --}}
            @php
                $pickupStop = $stops->first();
                $pickupStopPoint = $pickupStop?->routeStop?->point();
            @endphp

            <x-gm.card
                title="Jemput di luar titik kumpul"
                subtitle="Opsional. Gratis di dalam radius agen; selebihnya dihitung per kilometer.">
                <div class="grid gap-4">
                    <p class="rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-600">
                        Titik kumpul:
                        <span class="font-semibold text-black-900">{{ $pickupStop?->routeStop?->name }}</span>
                        @if ($pickupStopPoint !== null)
                            ·
                            <a href="{{ $pickupStopPoint->openStreetMapUrl() }}"
                               target="_blank" rel="noopener"
                               class="font-semibold text-gomad-green hover:underline">lihat peta</a>
                        @endif
                    </p>

                    <div>
                        <label for="pickup_point">Titik jemput</label>
                        <div class="flex flex-wrap gap-2">
                            <input id="pickup_point" name="pickup_point" maxlength="255"
                                   value="{{ old('pickup_point') }}"
                                   placeholder="Tautan Google Maps / OpenStreetMap, atau -6.30, 106.87"
                                   class="min-w-0 flex-1">
                            <x-gm.button type="button" variant="secondary" id="pickup-locate">Lokasi saya</x-gm.button>
                            <a id="pickup-open-map" href="https://www.openstreetmap.org" target="_blank" rel="noopener">
                                <x-gm.button type="button" variant="ghost">Buka peta</x-gm.button>
                            </a>
                        </div>
                        <p class="muted mt-1">Tempel tautan lokasi dari aplikasi peta, atau tekan “Lokasi saya”.</p>
                    </div>

                    <div>
                        <label for="pickup_address">Patokan alamat</label>
                        <input id="pickup_address" name="pickup_address" maxlength="1000"
                               value="{{ old('pickup_address') }}"
                               placeholder="mis. Jl. Melati 12, dekat masjid">
                    </div>

                    <p id="pickup-quote" class="rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-600">
                        Isi titik jemput untuk melihat biaya tambahan.
                    </p>
                </div>
            </x-gm.card>

            <x-gm.card title="Penumpang" subtitle="Isi nama sesuai identitas yang dibawa saat berangkat.">
                <div id="passenger-fields">
                    <p class="text-sm text-gray-600">Pilih kursi terlebih dahulu.</p>
                </div>
            </x-gm.card>
        </div>

        <aside class="space-y-5">
            <x-gm.card title="Ringkasan">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-600">Kursi dipilih</dt>
                        <dd class="font-semibold text-black-900" id="summary-seats">—</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-600">Tarif per kursi</dt>
                        <dd class="font-semibold text-black-900" id="summary-fare">—</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-600">Biaya jemput</dt>
                        <dd class="font-semibold text-black-900" id="summary-pickup">—</dd>
                    </div>
                </dl>

                <x-gm.alert tone="info" class="mt-4">
                    Total akhir beserta biaya layanan dan PPN dihitung server saat pesanan dibuat.
                </x-gm.alert>

                <x-gm.button type="submit" variant="brand" class="mt-4 w-full">Pesan sekarang</x-gm.button>
            </x-gm.card>
        </aside>
    </form>

    <style>
        .gm-seat {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 10px;
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            user-select: none;
        }
        .gm-seat input { width: auto; margin: 0; }
        .gm-seat:has(input:checked) {
            border-color: var(--color-gomad-green);
            background-color: color-mix(in srgb, var(--color-gomad-green) 14%, white);
        }
        .gm-seat--taken {
            background-color: var(--color-gray-100);
            color: var(--color-gray-400);
            border-style: dashed;
            cursor: not-allowed;
            text-decoration: line-through;
        }
    </style>

    <script>
        const seatBoxes = Array.from(document.querySelectorAll('input[name="seat_pick[]"]'));
        const passengerFields = document.getElementById('passenger-fields');
        const summarySeats = document.getElementById('summary-seats');
        const summaryFare = document.getElementById('summary-fare');
        const segmentPrices = @json($segmentPrices);
        const originSelect = document.getElementById('origin_stop_id');
        const destinationSelect = document.getElementById('destination_stop_id');
        const fareLabel = document.getElementById('segment-fare');

        const rupiah = (value) => 'Rp ' + Number(value).toLocaleString('id-ID');

        function currentFare() {
            return segmentPrices[originSelect.value + '-' + destinationSelect.value];
        }

        function renderPassengers() {
            const seats = seatBoxes.filter((box) => box.checked);

            summarySeats.textContent = seats.length === 0 ? '—' : seats.map((box) => box.value).join(', ');
            const fare = currentFare();
            summaryFare.textContent = fare ? rupiah(fare) : '—';

            if (seats.length === 0) {
                passengerFields.innerHTML = '<p class="text-sm text-gray-600">Pilih kursi terlebih dahulu.</p>';
                return;
            }

            passengerFields.innerHTML = seats.map((box, index) => `
                <div class="mt-3 rounded-md border border-gray-200 px-4 py-3">
                    <p class="text-sm font-semibold text-black-900">Kursi ${box.value}</p>
                    <input type="hidden" name="seats[${index}][seat_number]" value="${box.value}">
                    <label class="mt-2 block">
                        <span class="text-sm font-semibold text-black-900">Nama penumpang</span>
                        <input name="seats[${index}][name]" required maxlength="160">
                    </label>
                    <label class="mt-2 block">
                        <span class="text-sm font-semibold text-black-900">Nomor HP (opsional)</span>
                        <input name="seats[${index}][phone]" maxlength="30">
                    </label>
                </div>
            `).join('');
        }

        seatBoxes.forEach((box) => box.addEventListener('change', renderPassengers));

        function syncStops() {
            const originOrder = Number(originSelect.selectedOptions[0]?.dataset.order ?? 0);

            Array.from(destinationSelect.options).forEach((option) => {
                option.disabled = Number(option.dataset.order) <= originOrder;
            });

            if (Number(destinationSelect.selectedOptions[0]?.dataset.order ?? 0) <= originOrder) {
                const firstValid = Array.from(destinationSelect.options).find((option) => !option.disabled);
                if (firstValid) destinationSelect.value = firstValid.value;
            }

            const fare = currentFare();
            fareLabel.textContent = fare
                ? 'Tarif segmen ini: ' + rupiah(fare)
                : 'Tarif untuk segmen ini belum tersedia.';

            renderPassengers();
        }

        const seatsUrl = @json(route('mad-trans.schedules.seats', $schedule));

        async function refreshSeats() {
            const params = new URLSearchParams({
                origin_stop_id: originSelect.value,
                destination_stop_id: destinationSelect.value,
            });

            try {
                const response = await fetch(seatsUrl + '?' + params.toString(), {
                    headers: { Accept: 'application/json' },
                });
                if (!response.ok) return;

                const taken = new Set((await response.json()).taken ?? []);
                seatBoxes.forEach((box) => {
                    const isTaken = taken.has(box.value);
                    box.disabled = isTaken;
                    box.closest('.gm-seat').classList.toggle('gm-seat--taken', isTaken);
                    if (isTaken) box.checked = false;
                });
                renderPassengers();
            } catch (error) {
                // Biarkan denah apa adanya bila jaringan sedang bermasalah.
            }
        }

        originSelect.addEventListener('change', () => { syncStops(); refreshSeats(); });
        destinationSelect.addEventListener('change', () => { syncStops(); refreshSeats(); });
        syncStops();

        /*
        | Titik jemput (door to door).
        |
        | Biaya tidak pernah dihitung di browser: setiap perubahan dikirim ke
        | `pickup-quote`, lalu hasil server ditampilkan apa adanya. Dengan begitu
        | kebijakan radius agency tetap satu-satunya aturan yang berlaku.
        */
        const csrfToken = @json(csrf_token());
        const pickupInput = document.getElementById('pickup_point');
        const pickupQuoteUrl = @json(route('mad-trans.schedules.pickup-quote', $schedule));
        const pickupQuoteBox = document.getElementById('pickup-quote');
        const pickupOpenMap = document.getElementById('pickup-open-map');
        const pickupLocate = document.getElementById('pickup-locate');
        const summaryPickup = document.getElementById('summary-pickup');

        function mapUrlFor(raw) {
            const trimmed = raw.trim();

            if (/^https?:\/\//i.test(trimmed)) return trimmed;

            const coords = trimmed.match(/^(-?\d+(?:\.\d+)?)\s*[, ]\s*(-?\d+(?:\.\d+)?)$/);
            if (coords) {
                return 'https://www.openstreetmap.org/?mlat=' + coords[1] + '&mlon=' + coords[2]
                    + '#map=16/' + coords[1] + '/' + coords[2];
            }

            return 'https://www.openstreetmap.org';
        }

        function refreshMapLink() {
            pickupOpenMap.href = mapUrlFor(pickupInput.value);
        }

        let pickupTimer = null;

        async function refreshPickupQuote() {
            const raw = pickupInput.value.trim();

            if (raw === '') {
                pickupQuoteBox.textContent = 'Isi titik jemput untuk melihat biaya tambahan.';
                summaryPickup.textContent = '—';
                return;
            }

            const body = new URLSearchParams({
                origin_stop_id: originSelect.value,
                pickup_point: raw,
            });

            try {
                const response = await fetch(pickupQuoteUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body,
                });

                const payload = await response.json();

                if (!response.ok) {
                    pickupQuoteBox.textContent = payload.message ?? 'Titik jemput tidak dikenali.';
                    summaryPickup.textContent = '—';
                    return;
                }

                pickupQuoteBox.textContent = payload.data.explanation;
                summaryPickup.textContent = rupiah(payload.data.fee);
            } catch (error) {
                pickupQuoteBox.textContent = 'Estimasi biaya jemput gagal dimuat.';
            }
        }

        pickupInput.addEventListener('input', () => {
            refreshMapLink();
            clearTimeout(pickupTimer);
            pickupTimer = setTimeout(refreshPickupQuote, 400);
        });

        pickupLocate.addEventListener('click', () => {
            if (!navigator.geolocation) {
                pickupQuoteBox.textContent = 'Perangkat ini tidak mendukung lokasi. Tempel tautan peta secara manual.';
                return;
            }

            pickupLocate.disabled = true;

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    pickupInput.value = position.coords.latitude.toFixed(6)
                        + ', ' + position.coords.longitude.toFixed(6);
                    pickupLocate.disabled = false;
                    refreshMapLink();
                    refreshPickupQuote();
                },
                () => {
                    pickupLocate.disabled = false;
                    pickupQuoteBox.textContent = 'Lokasi tidak bisa dibaca. Tempel tautan peta secara manual.';
                },
                { enableHighAccuracy: true, timeout: 8000 },
            );
        });

        refreshMapLink();
    </script>
@endsection
