@extends('layouts.app')

@section('title', 'Katalog '.$business->name)

@section('content')
    <x-gm.page-header
        eyebrow="Ruang kerja Mad Warung"
        :title="$isGrosir ? 'Katalog & harga' : 'Pesan stok'"
        :subtitle="$isGrosir
            ? $business->name.' · harga, minimum order, dan stok yang Anda jual ke warung'
            : $business->name.' · katalog grosir yang bisa dipesan'">
        <x-slot:actions>
            <a href="{{ route('warung.orders.index', $business) }}">
                <x-gm.button type="button" variant="secondary">{{ $isGrosir ? 'Pesanan masuk' : 'Pesanan saya' }}</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @include('madwarung._nav', ['business' => $business, 'active' => 'catalog', 'canReport' => $canReport])

    @if ($isGrosir)
        <x-gm.card title="Harga & stok per produk"
            subtitle="Stok yang sedang direservasi tidak dapat dikurangi di bawah angka reservasi."
            :padded="false">
            <div class="divide-y divide-gray-100">
                @forelse ($products as $product)
                    @php $listing = $product->prices->first(); @endphp
                    <div class="px-5 py-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <p class="text-sm font-semibold text-black-900">
                                    {{ $product->name }}
                                    <span class="font-normal text-gray-600">· {{ $product->sku }} · {{ $product->unit }}</span>
                                </p>
                                <p class="text-xs text-gray-600">{{ $product->category ?? 'tanpa kategori' }}</p>
                            </div>
                            <p class="text-xs text-gray-600">
                                @if ($listing !== null)
                                    Siap dijual {{ max(0, (int) $listing->stock_available - (int) $listing->stock_reserved) }}
                                    {{ $product->unit }} · reservasi {{ (int) $listing->stock_reserved }}
                                @else
                                    Belum dijual di grosir ini
                                @endif
                            </p>
                        </div>

                        <form method="post" action="{{ route('warung.products.price', [$business, $product]) }}"
                              class="mt-3 grid gap-3 sm:grid-cols-4">
                            @csrf
                            @method('PUT')
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Harga grosir</span>
                                <input type="number" name="price" min="1" step="100" required
                                       value="{{ old('price', (int) ($listing->price ?? 0)) }}">
                            </label>
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Minimum order</span>
                                <input type="number" name="min_order_quantity" min="1" max="10000" required
                                       value="{{ old('min_order_quantity', (int) ($listing->min_order_quantity ?? 1)) }}">
                            </label>
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Stok tersedia</span>
                                <input type="number" name="stock_available" min="{{ (int) ($listing->stock_reserved ?? 0) }}"
                                       max="10000000" required
                                       value="{{ old('stock_available', (int) ($listing->stock_available ?? 0)) }}">
                            </label>
                            <div class="flex items-end">
                                <x-gm.button type="submit" variant="secondary" class="w-full">Simpan</x-gm.button>
                            </div>
                        </form>
                    </div>
                @empty
                    <div class="px-5 py-6 text-sm text-gray-600">
                        Belum ada produk aktif di katalog platform. Minta admin menambahkan produk lebih dulu.
                    </div>
                @endforelse
            </div>
        </x-gm.card>
    @elseif ($canOrder && $wholesalers->isNotEmpty())
        {{--
            Ruang belanja penjaga (DS v1.0 §7.4 "Penjaga Warung Retail").

            Plafon ditampilkan **sebelum** mengetik jumlah, bukan setelah pesanan
            ditolak atau masuk antrean persetujuan. Angkanya berasal dari tabel yang
            sama dengan yang dipakai CreateWarungOrder, jadi tidak ada aturan kedua.
        --}}
        @if ($spendingLimit !== null && $spendingLimit['role'] !== 'pemilik')
            @php
                $cap = $spendingLimit['daily_limit'];
                $remaining = $spendingLimit['remaining'];
                $usedRatio = $cap > 0 ? min(1, $spendingLimit['spent_today'] / $cap) : 0;
            @endphp

            <div class="rounded-lg border border-gray-200 bg-white px-5 py-4">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                            Sisa plafon hari ini
                        </p>
                        <p class="mt-1 text-3xl font-bold leading-none tracking-tight {{ $remaining <= 0 && $cap > 0 ? 'text-error-600' : 'text-black-900' }}">
                            <x-gm.money :amount="$remaining" />
                        </p>
                    </div>

                    <p class="text-sm text-gray-500">
                        terpakai <x-gm.money :amount="$spendingLimit['spent_today']" />
                        @if ($cap > 0)
                            dari <x-gm.money :amount="$cap" />
                        @else
                            · plafon belum diset
                        @endif
                    </p>
                </div>

                @if ($cap > 0)
                    <div class="mt-3 h-2 w-full overflow-hidden rounded-pill bg-gray-100"
                         role="img"
                         aria-label="Pemakaian plafon {{ number_format($usedRatio * 100, 0) }} persen">
                        <div class="h-full rounded-pill {{ $usedRatio >= 0.9 ? 'bg-error-600' : ($usedRatio >= 0.7 ? 'bg-warning-600' : 'bg-success-600') }}"
                             style="width: {{ round($usedRatio * 100) }}%"></div>
                    </div>
                @endif

                <ul class="mt-3 space-y-1 text-xs text-gray-500">
                    @if ($spendingLimit['per_order'] > 0)
                        <li>Maksimal per pesanan: <x-gm.money :amount="$spendingLimit['per_order']" /></li>
                    @endif
                    @if ($spendingLimit['approval_above'] > 0)
                        <li>
                            Pesanan di atas <x-gm.money :amount="$spendingLimit['approval_above']" />
                            perlu persetujuan pemilik.
                        </li>
                    @endif
                    @if ($cap <= 0)
                        <li class="font-medium text-warning-600">
                            Plafon belum diset — setiap pesanan akan menunggu persetujuan pemilik.
                        </li>
                    @endif
                </ul>
            </div>
        @endif

        <div class="mt-5 space-y-5">
            @foreach ($wholesalers as $wholesaler)
                <x-gm.card :title="$wholesaler->name" subtitle="Isi jumlah yang ingin dipesan; nol berarti tidak dipesan."
                    :padded="false">
                    <form method="post" action="{{ route('warung.orders.store', $business) }}"
                          class="ja-order-form">
                        @csrf
                        <input type="hidden" name="wholesaler_id" value="{{ $wholesaler->id }}">

                        <div class="divide-y divide-gray-100">
                            @foreach ($wholesaler->productPrices as $index => $listing)
                                <input type="hidden" name="items[{{ $index }}][product_id]" value="{{ $listing->product->id }}">
                                <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-black-900">
                                            {{ $listing->product->name }}
                                            <span class="font-normal text-gray-600">· {{ $listing->product->unit }}</span>
                                        </p>
                                        <p class="text-xs text-gray-600">
                                            <x-gm.money :amount="$listing->price" /> / unit
                                            · minimum {{ $listing->min_order_quantity }}
                                            · stok {{ max(0, (int) $listing->stock_available - (int) $listing->stock_reserved) }}
                                        </p>
                                    </div>
                                    <label class="w-32">
                                        <span class="sr-only">Jumlah {{ $listing->product->name }}</span>
                                        <input type="number" min="0" max="10000" name="items[{{ $index }}][quantity]"
                                               data-unit-price="{{ (int) $listing->price }}"
                                               value="{{ old('items.'.$index.'.quantity', 0) }}">
                                    </label>
                                </div>
                            @endforeach
                        </div>

                        <div class="grid gap-3 border-t border-gray-200 px-5 py-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Metode pengiriman</span>
                                <select name="delivery_method" required>
                                    <option value="antar_grosir">Diantar grosir</option>
                                    <option value="ambil_sendiri">Ambil sendiri</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Alamat pengantaran</span>
                                <input type="text" name="delivery_address" maxlength="1000"
                                       value="{{ old('delivery_address') }}" placeholder="wajib jika diantar">
                            </label>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 px-5 py-3">
                            <p class="text-sm text-gray-500">
                                Subtotal barang
                                <span class="ja-subtotal ml-1 text-base font-bold text-black-900">Rp 0</span>
                                <span class="block text-xs text-gray-500">
                                    Biaya platform dan PPN ditambahkan server saat pesanan dibuat.
                                </span>
                            </p>

                            {{-- Nama grosir tetap disebut di tombol: formulirnya panjang,
                                 dan tombol di dasar halaman harus tetap menjelaskan ke siapa
                                 pesanan ini diajukan. --}}
                            <x-gm.button type="submit" variant="brand">Ajukan pesanan ke {{ $wholesaler->name }}</x-gm.button>
                        </div>
                    </form>
                </x-gm.card>
            @endforeach
        </div>

        <script>
            /*
            | Subtotal barang yang berubah saat jumlah diketik.
            |
            | Yang dijumlahkan hanya harga × jumlah. Biaya platform dan PPN sengaja
            | **tidak** dihitung di browser: rumusnya milik CreateWarungOrder, dan
            | salinan kedua di JavaScript adalah cara paling mudah membuat dua angka
            | berbeda untuk pesanan yang sama.
            */
            (() => {
                const rupiah = (value) => 'Rp ' + Math.round(value).toLocaleString('id-ID');

                document.querySelectorAll('.ja-order-form').forEach((form) => {
                    const inputs = Array.from(form.querySelectorAll('input[data-unit-price]'));
                    const target = form.querySelector('.ja-subtotal');

                    if (inputs.length === 0 || ! target) return;

                    const render = () => {
                        const total = inputs.reduce((sum, input) => {
                            const quantity = Number(input.value) || 0;
                            const price = Number(input.dataset.unitPrice) || 0;

                            return sum + Math.max(0, quantity) * price;
                        }, 0);

                        target.textContent = rupiah(total);
                    };

                    inputs.forEach((input) => input.addEventListener('input', render));
                    render();
                });
            })();
        </script>
    @elseif ($canOrder)
        <x-gm.empty title="Belum ada katalog grosir"
            description="Belum ada grosir aktif yang memasang harga. Coba lagi setelah grosir membuka katalog." />
    @else
        <x-gm.alert tone="warning">
            Usaha ini belum aktif, jadi pesanan belum bisa dibuat. Hubungi admin bila status usaha masih menunggu verifikasi.
        </x-gm.alert>
    @endif
@endsection
