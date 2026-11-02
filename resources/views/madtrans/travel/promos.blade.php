@extends('layouts.app')

@section('title', 'Promo agency — '.$business->name)

@section('content')
    <div class="gm-surface p-5 mb-4">
        <h1 class="gm-h2 text-black-900 mb-1">Promo agency</h1>
        <p class="text-sm text-gray-500">{{ $business->name }} · {{ $business->code }}</p>
        <p class="text-sm text-gray-500">
            Promo di sini <strong>ditanggung agency</strong>: bagian agency berkurang sebesar diskon.
            Promo yang ditanggung platform dibuat admin GoMad dan tidak memotong agency.
        </p>
        <p>
            <a href="{{ route('mad-trans.agency.show', $business) }}">← Kembali ke portal agency</a>
        </p>
    </div>

    <div class="gm-surface p-5 mb-4">
        <h2 class="gm-h4 text-black-900 mb-2">Buat promo agency</h2>
        <form method="post" action="{{ route('mad-trans.agency.promos.store', $business) }}">
            @csrf
            <label for="code">Kode promo</label>
            <input id="code" name="code" value="{{ old('code') }}" required maxlength="40" placeholder="AGENCY10">

            <label for="name">Nama promo</label>
            <input id="name" name="name" value="{{ old('name') }}" required maxlength="120">

            <label for="discount_type">Jenis diskon</label>
            <select id="discount_type" name="discount_type">
                <option value="percent">Persentase (%)</option>
                <option value="amount">Nominal (Rp)</option>
            </select>

            <label for="discount_value">Nilai diskon</label>
            <input id="discount_value" name="discount_value" type="number" step="0.01" min="0.01" required>

            <label for="max_discount">Batas maksimal diskon (Rp, opsional)</label>
            <input id="max_discount" name="max_discount" type="number" step="1" min="0">

            <label for="min_spend">Minimal transaksi (Rp)</label>
            <input id="min_spend" name="min_spend" type="number" step="1" min="0" value="0">

            <label for="applies_to">Berlaku untuk</label>
            <select id="applies_to" name="applies_to">
                <option value="all">Semua moda</option>
                <option value="travel">Tiket travel</option>
                <option value="rental">Sewa kendaraan</option>
            </select>

            <label for="starts_at">Mulai (opsional)</label>
            <input id="starts_at" name="starts_at" type="datetime-local">

            <label for="ends_at">Berakhir (opsional)</label>
            <input id="ends_at" name="ends_at" type="datetime-local">

            <label for="usage_limit">Kuota pemakaian (opsional)</label>
            <input id="usage_limit" name="usage_limit" type="number" min="1">

            <label for="per_user_limit">Batas per pengguna</label>
            <input id="per_user_limit" name="per_user_limit" type="number" min="1" max="100" value="1">

            <x-gm.button type="submit">Buat promo</x-gm.button>
        </form>
    </div>

    <div class="gm-surface p-5 mb-4">
        <h2 class="gm-h4 text-black-900 mb-2">Promo agency</h2>
        @forelse ($promos as $promo)
            <div class="gm-list-row">
                <div>
                    <strong>{{ $promo->code }}</strong> · {{ $promo->name }}<br>
                    <span class="text-sm text-gray-500">
                        {{ $promo->discount_type === 'percent'
                            ? rtrim(rtrim((string) $promo->discount_value, '0'), '.').'%'
                            : 'Rp '.number_format((float) $promo->discount_value, 0, ',', '.') }}
                        · {{ $promo->applies_to }}
                        · dipakai {{ $promo->used_count }}{{ $promo->usage_limit !== null ? '/'.$promo->usage_limit : '' }}
                    </span>
                </div>
                <div><span class="text-sm text-gray-500">{{ $promo->status }}</span></div>
            </div>
        @empty
            <p class="text-sm text-gray-500">Belum ada promo.</p>
        @endforelse
    </div>

    <div class="gm-surface p-5 mb-4">
        <h2 class="gm-h4 text-black-900 mb-2">Rincian diskon</h2>
        <p class="text-sm text-gray-500">
            Total diskon dipakai pelanggan: <strong>Rp {{ number_format((float) $totalDiscount, 0, ',', '.') }}</strong>
            · ditanggung agency: <strong>Rp {{ number_format((float) $borneByBusiness, 0, ',', '.') }}</strong>
        </p>
        @forelse ($redemptions as $redemption)
            <div class="gm-list-row">
                <div>
                    <strong>{{ $redemption->promo?->code }}</strong>
                    · {{ $redemption->reference_type }} #{{ $redemption->reference_id }}
                </div>
                <div class="muted">
                    −Rp {{ number_format((float) $redemption->discount_amount, 0, ',', '.') }}
                    · ditanggung {{ $redemption->cost_bearer }}
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500">Belum ada pemakaian promo.</p>
        @endforelse
    </div>
@endsection
