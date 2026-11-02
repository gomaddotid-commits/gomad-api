@extends('layouts.app')

@section('title', 'Usaha saya | GoMad')

@section('content')
@php
    $accountType = auth()->user()->accountType();
    $businessType = $accountType->businessType();
    $drafts = $businesses->where('status', 'draft');
@endphp

<x-gm.page-header
    title="Usaha saya"
    subtitle="Akun {{ $accountType->label() }} hanya mengelola usaha bertipe {{ $businessType }}." />

@if (session('status'))
    <x-gm.alert tone="success">{{ session('status') }}</x-gm.alert>
@endif

@if ($drafts->isNotEmpty())
    <x-gm.alert tone="info">
        {{ $drafts->count() }} usaha menunggu verifikasi. Ruang kerja dan halaman publik baru
        aktif setelah tim GoMad menyetujui profil usaha.
    </x-gm.alert>
@endif

@foreach ($businesses as $business)
    <x-gm.card
        :title="$business->name"
        :subtitle="$business->type.' · '.$business->code">
        <x-slot:actions>
            @if ($business->status === 'draft')
                <x-gm.badge tone="warning">Menunggu verifikasi</x-gm.badge>
            @elseif ($business->status === 'active')
                <x-gm.badge tone="success">Aktif</x-gm.badge>
            @else
                <x-gm.badge tone="neutral">{{ ucfirst($business->status) }}</x-gm.badge>
            @endif
        </x-slot:actions>

        @if ($business->status === 'draft')
            <p class="muted">
                Usaha ini dibuat otomatis saat Anda mendaftar. Lengkapi namanya, lalu tim GoMad
                memverifikasi sebelum ruang kerja bisa dibuka.
            </p>

            <form method="post" action="{{ route('businesses.update', $business) }}" class="mt-4 grid gap-3 sm:max-w-md">
                @csrf
                @method('patch')
                <div>
                    <label for="name-{{ $business->id }}">Nama usaha</label>
                    <input
                        id="name-{{ $business->id }}"
                        name="name"
                        value="{{ old('name', $business->name) }}"
                        required
                        minlength="2"
                        maxlength="150">
                    @error('name')
                        <p class="mt-1 text-sm font-medium text-error-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <x-gm.button type="submit">Lengkapi profil usaha</x-gm.button>
                </div>
            </form>

            <p class="muted mt-3">
                Perlu menambah anggota sebelum verifikasi?
                <a href="{{ route('businesses.show', $business) }}" class="font-semibold hover:underline">Kelola anggota &amp; undangan →</a>
            </p>
        @else
            <div class="mt-3 flex flex-wrap gap-3 text-sm font-semibold">
                @if ($business->type === 'agency')
                    <a href="{{ route('mad-trans.agency.show', $business) }}" class="hover:underline">Buka ruang kerja travel &amp; rental →</a>
                @else
                    <a href="{{ route('warung.show', $business) }}" class="hover:underline">Buka ruang kerja warung →</a>
                @endif
                <a href="{{ route('businesses.show', $business) }}" class="hover:underline">Anggota &amp; undangan →</a>
            </div>
        @endif
    </x-gm.card>
@endforeach

@if ($businesses->isEmpty())
    <x-gm.card title="Buat usaha">
        <p class="muted">
            Akun mitra biasanya sudah mendapat usaha otomatis saat mendaftar. Jika belum ada,
            buat sekarang — jenis usaha mengikuti fungsi akun Anda.
        </p>

        <form method="post" action="{{ route('businesses.store') }}" class="mt-4 grid gap-3 sm:max-w-md">
            @csrf
            <div>
                <label for="name">Nama usaha</label>
                <input id="name" name="name" value="{{ old('name') }}" required minlength="2" maxlength="150">
                @error('name')
                    <p class="mt-1 text-sm font-medium text-error-600">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label for="type">Jenis usaha</label>
                <select id="type" name="type" required>
                    <option value="{{ $businessType }}">{{ ucfirst((string) $businessType) }}</option>
                </select>
                <p class="muted">Jenis usaha mengikuti fungsi akun dan tidak bisa dipilih lain.</p>
            </div>
            <div>
                <x-gm.button type="submit">Buat usaha</x-gm.button>
            </div>
        </form>
    </x-gm.card>
@endif
@endsection
