@extends('layouts.app')

@section('title', 'Promo agency — '.$business->name)

@section('content')
    <div class="card">
        <h1>Promo agency</h1>
        <p class="muted">{{ $business->name }} · {{ $business->code }}</p>
        <p class="muted">
            Promo di sini <strong>ditanggung agency</strong>: bagian agency berkurang sebesar diskon.
            Promo yang ditanggung platform dibuat admin GoMad dan tidak memotong agency.
        </p>
        <p>
            <a href="{{ route('mad-trans.agency.show', $business) }}">← Kembali ke portal agency</a>
        </p>
    </div>

    <div class="card">
        <h2>Buat promo agency</h2>
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

            <button type="submit">Buat promo</button>
        </form>
    </div>

    <div class="card">
        <h2>Promo agency</h2>
        @forelse ($promos as $promo)
            <div class="row" style="border-top:1px solid #e6edee;padding:10px 0">
                <div>
                    <strong>{{ $promo->code }}</strong> · {{ $promo->name }}<br>
                    <span class="muted">
                        {{ $promo->discount_type === 'percent'
                            ? rtrim(rtrim((string) $promo->discount_value, '0'), '.').'%'
                            : 'Rp '.number_format((float) $promo->discount_value, 0, ',', '.') }}
                        · {{ $promo->applies_to }}
                        · dipakai {{ $promo->used_count }}{{ $promo->usage_limit !== null ? '/'.$promo->usage_limit : '' }}
                    </span>
                </div>
                <div><span class="muted">{{ $promo->status }}</span></div>
            </div>
        @empty
            <p class="muted">Belum ada promo.</p>
        @endforelse
    </div>

    <div class="card">
        <h2>Rincian diskon</h2>
        <p class="muted">
            Total diskon dipakai pelanggan: <strong>Rp {{ number_format((float) $totalDiscount, 0, ',', '.') }}</strong>
            · ditanggung agency: <strong>Rp {{ number_format((float) $borneByBusiness, 0, ',', '.') }}</strong>
        </p>
        @forelse ($redemptions as $redemption)
            <div class="row" style="border-top:1px solid #e6edee;padding:8px 0">
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
            <p class="muted">Belum ada pemakaian promo.</p>
        @endforelse
    </div>
@endsection
