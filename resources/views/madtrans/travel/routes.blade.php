@extends('layouts.app')

@section('title', 'Rute '.$business->name)

@section('content')
    <x-gm.page-header
        eyebrow="Ruang kerja agency"
        title="Rute & tarif"
        :subtitle="$business->name.' · '.$routes->count().' rute'">
        <x-slot:actions>
            <a href="{{ route('mad-trans.agency.fleet', $business) }}">
                <x-gm.button type="button" variant="secondary">Armada</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @include('madtrans.travel._nav', ['business' => $business, 'active' => 'routes'])

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-3">
            @forelse ($routes as $route)
                <x-gm.card :title="$route->origin_city.' → '.$route->destination_city"
                    :subtitle="$route->stops->count().' titik · '.$route->pricing->count().' tarif segmen'">
                    <x-slot:actions>
                        <x-gm.badge :tone="$route->status === 'active' ? 'success' : 'neutral'">
                            {{ ucfirst((string) $route->status) }}
                        </x-gm.badge>
                    </x-slot:actions>

                    <ol class="space-y-2">
                        @foreach ($route->stops->sortBy('stop_order') as $stop)
                            <li class="flex items-start gap-3">
                                <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-pill bg-gray-100 text-xs font-bold text-gray-600">
                                    {{ $stop->stop_order }}
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold text-black-900">{{ $stop->city_name }}</p>
                                    <p class="text-xs text-gray-600">{{ $stop->name }}</p>

                                    @if ($stop->latitude !== null && $stop->longitude !== null)
                                        <p class="mt-1 text-xs text-gray-500">
                                            Titik kumpul: {{ $stop->latitude }}, {{ $stop->longitude }}
                                            · <a class="font-semibold hover:underline" target="_blank" rel="noopener"
                                                  href="https://www.openstreetmap.org/?mlat={{ $stop->latitude }}&mlon={{ $stop->longitude }}#map=17/{{ $stop->latitude }}/{{ $stop->longitude }}">lihat peta</a>
                                        </p>
                                    @else
                                        <p class="mt-1 text-xs font-medium text-warning-600">Titik kumpul belum ditandai — jarak jemput tidak bisa dihitung.</p>
                                    @endif

                                    <form method="post" action="{{ route('mad-trans.agency.stops.pickup-point', [$business, $stop]) }}" class="mt-2 flex flex-wrap items-end gap-2">
                                        @csrf
                                        @method('PUT')
                                        <label class="min-w-0 flex-1">
                                            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Tempel tautan peta / koordinat</span>
                                            <input type="text" name="pickup_point" maxlength="255" placeholder="lat, lng atau tautan OpenStreetMap/Google Maps">
                                        </label>
                                        <x-gm.button type="submit" size="sm" variant="secondary">Simpan titik</x-gm.button>
                                    </form>
                                </div>
                            </li>
                        @endforeach
                    </ol>

                    @if ($route->pricing->isNotEmpty())
                        <div class="mt-4 overflow-x-auto">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Segmen</th>
                                        <th class="text-right">Tarif</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($route->pricing as $price)
                                        <tr>
                                            <td>
                                                {{ $stopNames[$price->origin_stop_id]?->city_name ?? '#'.$price->origin_stop_id }}
                                                →
                                                {{ $stopNames[$price->destination_stop_id]?->city_name ?? '#'.$price->destination_stop_id }}
                                            </td>
                                            <td class="text-right font-semibold"><x-gm.money :amount="$price->price" /></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-gm.card>
            @empty
                <x-gm.empty title="Belum ada rute"
                    description="Buat rute pertama dengan titik awal dan akhir beserta tarifnya, lalu terbitkan jadwal." />
            @endforelse
        </div>

        <aside class="space-y-5">
            <x-gm.card title="Buat rute baru">
                <form method="post" action="{{ route('mad-trans.agency.routes.store', $business) }}" class="space-y-3">
                    @csrf

                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Titik 1 — asal</p>
                    <label>
                        <span class="text-sm font-semibold text-black-900">Kota</span>
                        <input type="text" name="stops[0][city_name]" required maxlength="120" placeholder="Jakarta">
                    </label>
                    <label>
                        <span class="text-sm font-semibold text-black-900">Nama titik</span>
                        <input type="text" name="stops[0][name]" required maxlength="160" placeholder="Terminal Kampung Rambutan">
                    </label>

                    <p class="pt-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Titik 2 — tujuan</p>
                    <label>
                        <span class="text-sm font-semibold text-black-900">Kota</span>
                        <input type="text" name="stops[1][city_name]" required maxlength="120" placeholder="Bandung">
                    </label>
                    <label>
                        <span class="text-sm font-semibold text-black-900">Nama titik</span>
                        <input type="text" name="stops[1][name]" required maxlength="160" placeholder="Terminal Cicaheum">
                    </label>

                    <label>
                        <span class="text-sm font-semibold text-black-900">Tarif asal → tujuan</span>
                        <input type="number" name="leg_prices[0]" min="1000" step="1000" required placeholder="120000">
                    </label>

                    <x-gm.button type="submit" variant="brand" class="w-full">Buat rute</x-gm.button>

                    <p class="text-xs text-gray-500">
                        Rute dengan titik antara (multi-stop) dibuat lewat API; formulir ini
                        menangani rute langsung yang paling sering dipakai.
                    </p>
                </form>
            </x-gm.card>

            <x-gm.card title="Radius jemput (door to door)">
                <form method="post" action="{{ route('mad-trans.agency.pickup-policy', $business) }}" class="space-y-3">
                    @csrf
                    @method('PUT')

                    <label>
                        <span class="text-sm font-semibold text-black-900">Gratis sampai (km)</span>
                        <input type="number" name="free_radius_km" min="0" max="100" required
                               value="{{ old('free_radius_km', $pickupPolicy?->free_radius_km ?? 0) }}">
                    </label>
                    <label>
                        <span class="text-sm font-semibold text-black-900">Biaya per km di luar radius</span>
                        <input type="number" name="fee_per_km" min="0" step="500" max="1000000" required
                               value="{{ old('fee_per_km', (int) ($pickupPolicy?->fee_per_km ?? 0)) }}">
                    </label>

                    <x-gm.button type="submit" variant="brand" class="w-full">Simpan radius</x-gm.button>

                    <p class="text-xs text-gray-500">
                        Biaya jemput masuk ke pendapatan usaha (biaya angkut), bukan fee platform.
                        Isi 0 pada keduanya berarti tanpa biaya jemput.
                    </p>
                </form>
            </x-gm.card>
        </aside>
    </div>
@endsection
