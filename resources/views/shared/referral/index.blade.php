@extends('layouts.app')

@section('title', 'Referral | GoMad')

@section('content')
    <div class="card">
        <h1>Referral</h1>
        <p class="muted">
            Bagikan kode Anda. Hadiah referral <strong>ditanggung platform</strong> — tidak ada bagian
            agency yang berkurang karenanya.
        </p>
        <div class="row">
            <span>Kode Anda</span>
            <strong>{{ $code }}</strong>
        </div>
        <div class="row">
            <span>Tautan pendaftaran</span>
            <span class="muted">{{ $link }}</span>
        </div>
        <p><a href="{{ route('dashboard') }}">← Kembali</a></p>
    </div>

    <div class="card">
        <h2>Undangan Anda</h2>
        @forelse ($invites as $invite)
            <div class="row" style="border-top:1px solid #e6edee;padding:10px 0">
                <div>
                    <strong>{{ $invite->referee?->name ?? 'Pengguna' }}</strong><br>
                    <span class="muted">bergabung {{ $invite->created_at?->toDateTimeString() }}</span>
                </div>
                <div>
                    <span class="muted">{{ $invite->status }}</span>
                    @if ($invite->referrerPromo)
                        <br><span class="muted">hadiah: {{ $invite->referrerPromo->code }}</span>
                    @endif
                </div>
            </div>
        @empty
            <p class="muted">Belum ada yang bergabung lewat kode Anda.</p>
        @endforelse
    </div>

    <div class="card">
        <h2>Hadiah Anda</h2>
        @forelse ($rewards as $reward)
            <div class="row" style="border-top:1px solid #e6edee;padding:10px 0">
                <div>
                    <strong>{{ $reward->code }}</strong><br>
                    <span class="muted">
                        {{ $reward->discount_type === 'percent'
                            ? rtrim(rtrim((string) $reward->discount_value, '0'), '.').'%'
                            : 'Rp '.number_format((float) $reward->discount_value, 0, ',', '.') }}
                        · ditanggung {{ $reward->cost_bearer }}
                    </span>
                </div>
                <div><span class="muted">dipakai {{ $reward->used_count }}×</span></div>
            </div>
        @empty
            <p class="muted">
                Hadiah muncul setelah pengundang menyelesaikan transaksi berbayar pertamanya.
            </p>
        @endforelse
    </div>

    @if ($welcome?->refereePromo)
        <div class="card">
            <h2>Diskon selamat datang Anda</h2>
            <p>
                Kode <strong>{{ $welcome->refereePromo->code }}</strong> —
                {{ $welcome->refereePromo->discount_type === 'percent'
                    ? rtrim(rtrim((string) $welcome->refereePromo->discount_value, '0'), '.').'%'
                    : 'Rp '.number_format((float) $welcome->refereePromo->discount_value, 0, ',', '.') }}
                @if ((float) $welcome->refereePromo->max_discount > 0)
                    (maks Rp {{ number_format((float) $welcome->refereePromo->max_discount, 0, ',', '.') }})
                @endif
                · ditanggung {{ $welcome->refereePromo->cost_bearer }}
                · sudah dipakai {{ $welcome->refereePromo->used_count }}×
            </p>
        </div>
    @endif
@endsection
