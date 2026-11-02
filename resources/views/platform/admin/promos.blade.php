@extends('layouts.app')

@section('title', 'Promo platform')

@section('content')
    @include('platform.admin._nav', ['active' => 'promos'])

    <div class="card">
        <h1>Promo platform</h1>
        <p class="muted">
            Promo di sini <strong>ditanggung platform</strong>: pendapatan platform berkurang, tetapi
            agency tetap menerima bagian penuhnya. Hanya admin GoMad yang dapat membuatnya.
        </p>
        <p><a href="{{ route('dashboard') }}">← Kembali</a></p>
    </div>

    <div class="card">
        <h2>Buat promo</h2>
        <form method="post" action="{{ route('admin.promos.store') }}">
            @csrf
            <label for="code">Kode promo</label>
            <input id="code" name="code" value="{{ old('code') }}" required maxlength="40" placeholder="HEMAT10">

            <label for="name">Nama promo</label>
            <input id="name" name="name" value="{{ old('name') }}" required maxlength="120">

            <label for="discount_type">Jenis diskon</label>
            <select id="discount_type" name="discount_type">
                <option value="percent">Persentase (%)</option>
                <option value="amount">Nominal (Rp)</option>
            </select>

            <label for="discount_value">Nilai diskon</label>
            <input id="discount_value" name="discount_value" type="number" step="0.01" min="0.01" required>

            <label for="max_discount">Batas maksimal diskon (Rp, opsional untuk persentase)</label>
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

            <button type="submit">Buat promo platform</button>
        </form>
    </div>

    <div class="card">
        <h2>Daftar promo platform</h2>
        @forelse ($promos as $promo)
            <div class="row" style="border-top:1px solid #e6edee;padding:10px 0">
                <div>
                    <strong>{{ $promo->code }}</strong> · {{ $promo->name }}<br>
                    <span class="muted">
                        {{ $promo->discount_type === 'percent'
                            ? rtrim(rtrim((string) $promo->discount_value, '0'), '.').'%'
                            : 'Rp '.number_format((float) $promo->discount_value, 0, ',', '.') }}
                        @if ($promo->max_discount !== null && (float) $promo->max_discount > 0)
                            (maks Rp {{ number_format((float) $promo->max_discount, 0, ',', '.') }})
                        @endif
                        · {{ $promo->applies_to }} · ditanggung {{ $promo->cost_bearer }}
                        · dipakai {{ $promo->used_count }}{{ $promo->usage_limit !== null ? '/'.$promo->usage_limit : '' }}
                    </span>
                </div>
                <div><span class="muted">{{ $promo->status }}</span></div>
            </div>
        @empty
            <p class="muted">Belum ada promo platform.</p>
        @endforelse
    </div>

    <div class="card">
        <h2>Beban promo platform</h2>
        <p class="muted">
            Total diskon yang ditanggung platform: <strong>Rp {{ number_format((float) $totalDiscount, 0, ',', '.') }}</strong>
        </p>
        @forelse ($redemptions as $redemption)
            <div class="row" style="border-top:1px solid #e6edee;padding:8px 0">
                <div>
                    <strong>{{ $redemption->promo?->code }}</strong>
                    · {{ $redemption->reference_type }} #{{ $redemption->reference_id }}
                </div>
                <div class="muted">Rp {{ number_format((float) $redemption->discount_amount, 0, ',', '.') }}</div>
            </div>
        @empty
            <p class="muted">Belum ada pemakaian.</p>
        @endforelse
    </div>
@endsection
