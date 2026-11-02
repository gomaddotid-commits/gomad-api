@extends('layouts.app')

@section('title', 'Kasir '.$business->name)

@section('content')
    {{--
        KASIR (POS) — DS v1.0 §7.4 "Penjaga Warung Retail".

        Syarat yang dipegang: target sentuh besar, pemilihan produk cepat,
        langkah sedikit, ringkasan keranjang jelas, dan satu tindakan checkout
        yang menonjol. Karena itu tidak ada tabel: yang ada adalah tombol produk
        dan satu panel keranjang.

        Tata letak: dua kolom di tablet ke atas (produk | keranjang), satu kolom
        di ponsel dengan keranjang di bawah. Kasir di warung hampir selalu tablet.
    --}}
    <x-gm.page-header
        eyebrow="Kasir warung"
        :title="$business->name"
        :subtitle="'Penjualan hari ini: '.\App\Support\Rupiah::format($todayTotals['amount']).' · '.$todayTotals['count'].' transaksi'">
        <x-slot:actions>
            <x-gm.badge tone="brand">Kasir</x-gm.badge>
        </x-slot:actions>
    </x-gm.page-header>

    @include('madwarung._nav', ['business' => $business, 'active' => 'pos', 'canReport' => false])

    @if ($listings->isEmpty())
        <x-gm.empty
            title="Belum ada barang untuk dijual"
            description="Barang otomatis masuk ke kasir setelah pesanan dari grosir Anda terima. Terima pesanan lebih dulu, lalu kembali ke halaman ini.">
            <x-slot:action>
                <a href="{{ route('warung.orders.index', $business) }}">
                    <x-gm.button type="button" variant="brand">Buka pesanan</x-gm.button>
                </a>
            </x-slot:action>
        </x-gm.empty>
    @else
        <form method="post" action="{{ route('warung.pos.store', $business) }}" id="pos-form">
            @csrf

            @foreach ($listings as $listing)
                <input type="hidden" name="items[{{ $listing->product_id }}]" value="0" data-cart-input="{{ $listing->product_id }}">
            @endforeach

            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
                {{-- Panel produk: satu tombol besar per barang. --}}
                <div>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        @foreach ($listings as $listing)
                            <button type="button"
                                    data-add="{{ $listing->product_id }}"
                                    data-name="{{ $listing->product?->name }}"
                                    data-price="{{ (float) $listing->price }}"
                                    data-unit="{{ $listing->product?->unit }}"
                                    class="relative flex min-h-[88px] flex-col justify-between rounded-lg border border-gray-200 bg-white px-4 py-3 text-left transition-colors duration-150
                                           hover:border-green-400 hover:bg-gray-50 focus-visible:border-green-500">
                                <span class="text-sm font-semibold leading-snug text-black-900">
                                    {{ $listing->product?->name }}
                                </span>
                                <span class="mt-2 text-sm font-bold text-gomad-green">
                                    <x-gm.money :amount="$listing->price" />
                                    <span class="text-xs font-normal text-gray-500">/ {{ $listing->product?->unit }}</span>
                                </span>
                                <span data-count-for="{{ $listing->product_id }}"
                                      class="absolute right-2 top-2 hidden min-w-[24px] items-center justify-center rounded-pill bg-gomad-green-deep px-1.5 py-0.5 text-xs font-bold text-white"></span>
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Panel keranjang. --}}
                <aside class="lg:sticky lg:top-24 lg:self-start">
                    <div class="rounded-lg border border-gray-200 bg-white">
                        <div class="flex items-center justify-between border-b border-gray-200 px-5 py-3">
                            <p class="text-sm font-semibold text-black-900">Keranjang</p>
                            <button type="button" id="cart-clear"
                                    class="text-xs font-semibold text-gray-500 hover:text-error-600">
                                Kosongkan
                            </button>
                        </div>

                        <div id="cart-lines" class="divide-y divide-gray-100 px-5">
                            <p id="cart-empty" class="py-6 text-center text-sm text-gray-500">
                                Ketuk barang untuk menambah.
                            </p>
                        </div>

                        <div class="border-t border-gray-200 px-5 py-4">
                            <div class="flex items-baseline justify-between">
                                <span class="text-sm text-gray-500">Total</span>
                                <span id="cart-total" class="text-2xl font-bold tracking-tight text-gomad-green-deep">
                                    Rp 0
                                </span>
                            </div>

                            <label for="paid_amount" class="mt-3 block">
                                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Uang diterima</span>
                                <input id="paid_amount" name="paid_amount" type="number" min="0" step="500"
                                       inputmode="numeric" placeholder="kosongkan bila uang pas"
                                       autocomplete="off"
                                       class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm">
                            </label>

                            <p class="mt-2 flex items-baseline justify-between text-sm">
                                <span class="text-gray-500">Kembalian</span>
                                <span id="cart-change" class="font-semibold text-black-900">Rp 0</span>
                            </p>

                            <label for="note" class="mt-3 block">
                                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Catatan (opsional)</span>
                                <input id="note" name="note" maxlength="500" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm">
                            </label>

                            {{-- Satu tindakan utama, tinggi 56px. --}}
                            <x-gm.button type="submit" id="cart-submit" variant="brand" size="lg"
                                         class="mt-3 min-h-[56px] w-full text-base" disabled>
                                Simpan penjualan
                            </x-gm.button>
                        </div>
                    </div>

                    @if ($recentSales->isNotEmpty())
                        <div class="mt-4 rounded-lg border border-gray-200 bg-white px-5 py-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Transaksi terakhir saya</p>
                            <ul class="mt-2 space-y-1.5 text-sm">
                                @foreach ($recentSales as $sale)
                                    <li class="flex items-center justify-between gap-2">
                                        <span class="truncate font-mono text-xs text-gray-500">{{ $sale->code }}</span>
                                        <span class="shrink-0 font-semibold text-black-900">
                                            <x-gm.money :amount="$sale->total_amount" />
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </aside>
            </div>
        </form>

        <script>
            /*
            | Keranjang kasir.
            |
            | Yang dihitung di browser hanya **tampilan** jumlah baris dan total.
            | Nominal yang disimpan tetap dihitung `RecordRetailSale` dari harga di
            | `retail_listings`; harga yang dikirim klien tidak pernah dipercaya.
            */
            (() => {
                const form = document.getElementById('pos-form');
                if (! form) return;

                const rupiah = (value) => 'Rp ' + Math.round(value).toLocaleString('id-ID');
                const cart = new Map();
                let currentTotal = 0;

                const lines = document.getElementById('cart-lines');
                const empty = document.getElementById('cart-empty');
                const totalEl = document.getElementById('cart-total');
                const changeEl = document.getElementById('cart-change');
                const paidEl = document.getElementById('paid_amount');
                const submit = document.getElementById('cart-submit');
                const clear = document.getElementById('cart-clear');

                const findButton = (id) => form.querySelector('[data-add="' + id + '"]');
                const findInput = (id) => form.querySelector('[data-cart-input="' + id + '"]');

                function render() {
                    lines.querySelectorAll('[data-line]').forEach((node) => node.remove());

                    let total = 0;

                    cart.forEach((entry, id) => {
                        const lineTotal = entry.price * entry.quantity;
                        total += lineTotal;

                        const row = document.createElement('div');
                        row.dataset.line = id;
                        row.className = 'flex items-center justify-between gap-3 py-3';
                        row.innerHTML = `
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-black-900"></p>
                                <p class="text-xs text-gray-500"></p>
                            </div>
                            <div class="flex shrink-0 items-center gap-1"></div>
                        `;

                        row.querySelector('p').textContent = entry.name;
                        row.children[0].lastElementChild.textContent =
                            entry.quantity + ' × ' + rupiah(entry.price);

                        const controls = row.children[1];

                        const less = document.createElement('button');
                        less.type = 'button';
                        less.className = 'flex h-11 w-11 items-center justify-center rounded-md border border-gray-300 text-lg font-bold text-black-900';
                        less.textContent = '−';
                        less.setAttribute('aria-label', 'Kurangi ' + entry.name);

                        const qty = document.createElement('span');
                        qty.className = 'w-8 text-center text-sm font-semibold text-black-900';
                        qty.textContent = String(entry.quantity);

                        const more = document.createElement('button');
                        more.type = 'button';
                        more.className = 'flex h-11 w-11 items-center justify-center rounded-md border border-gray-300 text-lg font-bold text-black-900';
                        more.textContent = '+';
                        more.setAttribute('aria-label', 'Tambah ' + entry.name);

                        less.addEventListener('click', () => {
                            const next = entry.quantity - 1;
                            if (next <= 0) { cart.delete(id); } else { entry.quantity = next; }
                            render();
                        });

                        more.addEventListener('click', () => {
                            entry.quantity += 1;
                            render();
                        });

                        controls.append(less, qty, more);
                        lines.append(row);
                    });

                    currentTotal = total;
                    empty.hidden = cart.size > 0;
                    totalEl.textContent = rupiah(total);
                    submit.disabled = cart.size === 0;

                    // Sinkronkan nilai yang akan dikirim.
                    form.querySelectorAll('[data-cart-input]').forEach((input) => {
                        const entry = cart.get(input.dataset.cartInput);
                        input.value = entry ? String(entry.quantity) : '0';
                    });

                    // Badge jumlah pada tombol produk.
                    form.querySelectorAll('[data-add]').forEach((button) => {
                        const badge = form.querySelector('[data-count-for="' + button.dataset.add + '"]');
                        const entry = cart.get(button.dataset.add);

                        if (! badge) return;

                        badge.textContent = entry ? String(entry.quantity) : '';
                        badge.classList.toggle('hidden', ! entry);
                        button.classList.toggle('border-green-500', Boolean(entry));
                        button.classList.toggle('bg-lime-50', Boolean(entry));
                    });

                    renderChange(currentTotal);
                }

                function renderChange(total) {
                    const paid = Number(paidEl.value);
                    const change = Number.isFinite(paid) && paid > 0 ? paid - total : 0;

                    changeEl.textContent = rupiah(Math.max(0, change));
                    changeEl.classList.toggle('text-error-600', paid > 0 && paid < total);
                    changeEl.classList.toggle('text-black-900', ! (paid > 0 && paid < total));
                }

                form.querySelectorAll('[data-add]').forEach((button) => {
                    button.addEventListener('click', () => {
                        const id = button.dataset.add;
                        const existing = cart.get(id);

                        if (existing) {
                            existing.quantity += 1;
                        } else {
                            cart.set(id, {
                                name: button.dataset.name,
                                price: Number(button.dataset.price) || 0,
                                unit: button.dataset.unit || '',
                                quantity: 1,
                            });
                        }

                        render();
                    });
                });

                paidEl.addEventListener('input', () => renderChange(currentTotal));
                clear.addEventListener('click', () => { cart.clear(); render(); });

                render();
            })();
        </script>
    @endif
@endsection
