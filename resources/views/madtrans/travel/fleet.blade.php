@extends('layouts.app')

@section('title', 'Armada '.$business->name)

@section('content')
    <x-gm.page-header
        eyebrow="Ruang kerja agency"
        title="Armada"
        :subtitle="$business->name.' · '.$vehicles->count().' kendaraan, '.$activeSchedules.' jadwal aktif'">
        <x-slot:actions>
            <a href="{{ route('mad-trans.agency.routes', $business) }}">
                <x-gm.button type="button" variant="secondary">Kelola rute</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @include('madtrans.travel._nav', ['business' => $business, 'active' => 'fleet'])

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-3">
            @forelse ($vehicles as $vehicle)
                @php
                    $layout = is_array($vehicle->seat_layout) ? array_values($vehicle->seat_layout) : [];
                    $formation = \App\Core\MadTrans\Domain\SeatLayout::summary($layout);
                @endphp
                <x-gm.card :title="$vehicle->name" :subtitle="$vehicle->plate_number.' · '.$vehicle->capacity.' kursi · formasi '.$formation">
                    <x-slot:actions>
                        <x-gm.badge :tone="$vehicle->status === 'active' ? 'success' : 'neutral'">
                            {{ ucfirst((string) $vehicle->status) }}
                        </x-gm.badge>
                    </x-slot:actions>

                    {{--
                        Denah ditampilkan **per baris**, bukan sebagai deretan chip
                        datar. Formasi 2-3-3 yang dipilih owner harus terlihat
                        sebagai 2-3-3 di sini juga — kalau tidak, ia tidak bisa
                        memeriksa hasil pilihannya sendiri.
                    --}}
                    @php $seatRows = \App\Core\MadTrans\Domain\SeatLayout::rows($layout); @endphp

                    <div class="space-y-1.5">
                        @if ($vehicle->has_driver_seat)
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="w-4 text-[11px] font-bold uppercase text-gray-500">S</span>
                                <span class="inline-flex h-8 items-center justify-center rounded-md border border-dashed border-gray-400 bg-gray-50 px-2.5 text-[11px] font-semibold text-gray-500">
                                    Kursi supir
                                </span>
                            </div>
                        @endif

                        @foreach ($seatRows as $rowLetter => $seatCount)
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="w-4 text-[11px] font-bold uppercase text-gray-500">{{ $rowLetter }}</span>
                                @for ($seatNumber = 1; $seatNumber <= $seatCount; $seatNumber++)
                                    <span class="inline-flex h-8 w-9 items-center justify-center rounded-md border border-gray-300 bg-white text-xs font-medium text-gray-600">
                                        {{ $rowLetter }}{{ $seatNumber }}
                                    </span>
                                @endfor
                            </div>
                        @endforeach

                        @if ($layout === [])
                            <span class="text-sm text-gray-500">Denah kursi belum diisi.</span>
                        @endif
                    </div>

                    <dl class="mt-4 grid gap-3 sm:grid-cols-3">
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Harga sewa/hari</dt>
                            <dd class="mt-1 text-sm font-semibold text-black-900">
                                @if ($vehicle->rental_daily_price !== null)
                                    <x-gm.money :amount="$vehicle->rental_daily_price" />
                                @else
                                    <span class="text-gray-500">Belum diset</span>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Tarif supir/hari</dt>
                            <dd class="mt-1 text-sm font-semibold text-black-900">
                                @if ($vehicle->rental_driver_daily_fee !== null)
                                    <x-gm.money :amount="$vehicle->rental_driver_daily_fee" />
                                @else
                                    <span class="font-normal text-gray-500">Belum diset</span>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Deposit</dt>
                            <dd class="mt-1 text-sm font-semibold text-black-900">
                                @if ($vehicle->rental_deposit !== null)
                                    <x-gm.money :amount="$vehicle->rental_deposit" />
                                @else
                                    <span class="font-normal text-gray-500">Belum diset</span>
                                @endif
                            </dd>
                        </div>
                    </dl>

                    <form method="post" action="{{ route('mad-trans.agency.rental-rate.store', [$business, $vehicle]) }}"
                          class="mt-4 grid gap-3 sm:grid-cols-4">
                        @csrf
                        <label class="sm:col-span-1">
                            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Sewa/hari</span>
                            <input type="number" name="rental_daily_price" min="0" step="1000"
                                   value="{{ $vehicle->rental_daily_price }}">
                        </label>
                        <label class="sm:col-span-1">
                            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Supir/hari</span>
                            <input type="number" name="rental_driver_daily_fee" min="0" step="1000"
                                   value="{{ $vehicle->rental_driver_daily_fee }}">
                        </label>
                        <label class="sm:col-span-1">
                            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Deposit</span>
                            <input type="number" name="rental_deposit" min="0" step="1000"
                                   value="{{ $vehicle->rental_deposit }}">
                        </label>
                        <div class="self-end sm:col-span-1">
                            <x-gm.button type="submit" variant="secondary" size="sm" class="w-full">Simpan tarif</x-gm.button>
                        </div>
                    </form>
                </x-gm.card>
            @empty
                <x-gm.empty title="Belum ada kendaraan"
                    description="Tambahkan kendaraan pertama dengan denah kursi agar jadwal bisa diterbitkan." />
            @endforelse
        </div>

        <aside class="space-y-5">
            <x-gm.card title="Tambah kendaraan">
                <form method="post" action="{{ route('mad-trans.agency.vehicles.store', $business) }}" class="space-y-3"
                      id="vehicle-form">
                    @csrf

                    <label>
                        <span class="text-sm font-semibold text-black-900">Nama kendaraan</span>
                        <input type="text" name="name" required maxlength="120" placeholder="mis. Hiace Premio">
                    </label>

                    <label>
                        <span class="text-sm font-semibold text-black-900">Nomor polisi</span>
                        <input type="text" name="plate_number" required maxlength="30" placeholder="B 1234 XYZ">
                    </label>

                    <fieldset>
                        <legend class="text-sm font-semibold text-black-900">Formasi kursi</legend>
                        <p class="mt-1 text-xs text-gray-500">
                            Pilih jumlah kursi untuk tiap baris. Contoh Hiace 2-3-3: baris A = 2, B = 3, C = 3.
                            Baris yang dibiarkan 0 tidak dipakai.
                        </p>

                        <div class="mt-2 space-y-2">
                            @foreach (range(0, 5) as $rowIndex)
                                @php $rowLetter = \App\Core\MadTrans\Domain\SeatLayout::rowLabel($rowIndex); @endphp
                                <label class="flex items-center justify-between gap-3">
                                    <span class="text-sm text-gray-600">Baris {{ $rowLetter }}</span>
                                    <select name="seat_rows[{{ $rowIndex }}]" class="w-24">
                                        @foreach (range(0, \App\Core\MadTrans\Domain\SeatLayout::MAX_SEATS_PER_ROW) as $count)
                                            <option value="{{ $count }}" @selected(old('seat_rows.'.$rowIndex, $rowIndex === 0 ? 2 : ($rowIndex === 1 ? 3 : ($rowIndex === 2 ? 3 : 0))) == $count)>
                                                {{ $count === 0 ? '—' : $count.' kursi' }}
                                            </option>
                                        @endforeach
                                    </select>
                                </label>
                            @endforeach
                        </div>

                        <label class="mt-3 flex items-start gap-2">
                            <input type="checkbox" name="has_driver_seat" value="1" @checked(old('has_driver_seat', true))>
                            <span class="text-sm text-gray-600">
                                Ada kursi supir di depan. Kursi supir ditampilkan di denah tetapi tidak pernah dijual.
                            </span>
                        </label>

                        {{--
                            Pratinjau formasi.

                            Owner memilih angka per baris, tetapi yang ia pikirkan adalah
                            "2-3-3". Menampilkan hasilnya saat itu juga membuat ia bisa
                            memeriksa pilihannya sebelum menyimpan, tanpa menghitung manual.
                        --}}
                        <p class="mt-3 rounded-md bg-gray-50 px-3 py-2 text-xs text-gray-500">
                            Formasi terpilih:
                            <span id="seat-formation" class="font-semibold text-black-900">2-3-3</span>
                            ·
                            <span id="seat-total" class="font-semibold text-black-900">8</span> kursi dijual
                        </p>
                    </fieldset>

                    <x-gm.button type="submit" variant="brand" class="w-full">Tambah</x-gm.button>
                </form>
            </x-gm.card>

            <x-gm.card title="Dokumen penyewa">
                <p class="text-sm text-gray-600">
                    KTP, SIM, dan selfie penyewa lepas kunci diverifikasi admin platform.
                    Agency hanya bisa melihat dokumen penyewa yang sewanya sudah dimulai.
                </p>
                <a href="{{ route('mad-trans.agency.rental', $business) }}" class="mt-3 block">
                    <x-gm.button type="button" variant="secondary" class="w-full">Buka rental</x-gm.button>
                </a>
            </x-gm.card>
        </aside>
    </div>

    <script>
        /*
        | Pratinjau formasi kursi di formulir tambah kendaraan.
        |
        | Hanya menghitung ulang yang sudah dipilih owner — tidak ada aturan baru di
        | sini. Aturan sebenarnya tetap di SeatLayout (server), karena formasi yang
        | tidak sah harus ditolak server, bukan dicegah oleh skrip halaman.
        */
        (() => {
            const form = document.getElementById('vehicle-form');
            const formation = document.getElementById('seat-formation');
            const total = document.getElementById('seat-total');

            if (! form || ! formation || ! total) return;

            const selects = Array.from(form.querySelectorAll('select[name^="seat_rows"]'));
            if (selects.length === 0) return;

            const render = () => {
                const counts = selects.map((select) => Number(select.value)).filter((count) => count > 0);

                formation.textContent = counts.length > 0 ? counts.join('-') : '—';
                total.textContent = String(counts.reduce((sum, count) => sum + count, 0));
            };

            selects.forEach((select) => select.addEventListener('change', render));
            render();
        })();
    </script>
@endsection
