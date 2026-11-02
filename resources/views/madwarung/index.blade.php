@extends('layouts.app')

@section('title', 'Mad Warung | GoMad')

@section('content')
<p><a href="{{ route('dashboard') }}">← Dashboard</a></p>
<h1>Mad Warung</h1>
<p class="muted">Kelola pembelian warung, katalog grosir, plafon penjaga, dan jadwal shift.</p>

@forelse ($businesses as $business)
    <section class="card row">
        <div>
            <h2><a href="{{ route('warung.show', $business) }}">{{ $business->name }}</a></h2>
            <p class="muted">{{ $business->type === 'warung' ? 'Warung' : 'Grosir' }} · {{ $business->status }}</p>
        </div>
        <a href="{{ route('warung.show', $business) }}">Buka dashboard →</a>
    </section>
@empty
    <section class="card">
        <p>Belum ada usaha warung atau grosir yang dapat diakses.</p>
        <a href="{{ route('dashboard') }}">Buat atau gabung ke usaha terlebih dahulu.</a>
    </section>
@endforelse
@endsection
