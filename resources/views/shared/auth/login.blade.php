@extends('layouts.app')

@section('title', 'Masuk | GoMad')

@section('content')
    {{--
        Gerbang masuk (DS v1.0 §8.5 "registration and login entry points").
        Tema consumer: satu kartu kaca di tengah, satu tindakan utama, dan tautan
        ke pendaftaran. Tidak ada sidebar — pengunjung belum punya ruang kerja.
    --}}
    <div class="mx-auto w-full max-w-md py-6 sm:py-10">
        <div class="gm-glass-strong rounded-2xl px-6 py-8 sm:px-8">
            <p class="gm-label uppercase tracking-wide text-gomad-green">GoMad</p>
            <h1 class="gm-h2 mt-1 text-gomad-green-deep">Masuk</h1>
            <p class="mt-2 text-sm text-gray-500">
                Satu akun untuk memesan travel, menyewa kendaraan, dan berbelanja.
            </p>

            <form method="post" action="{{ route('login') }}" class="mt-6 grid gap-4">
                @csrf

                <div>
                    <label for="identity">Nomor telepon atau email</label>
                    <input id="identity" name="identity" value="{{ old('identity') }}"
                           autocomplete="username" required autofocus
                           class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm">
                </div>

                <div>
                    <label for="password">Kata sandi</label>
                    <input id="password" name="password" type="password"
                           autocomplete="current-password" required
                           class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm">
                </div>

                <x-gm.button type="submit" variant="brand" size="lg" class="w-full rounded-pill">
                    Masuk
                </x-gm.button>
            </form>

            <p class="mt-5 text-center text-sm text-gray-500">
                Belum memiliki akun?
                <a href="{{ route('register') }}" class="font-semibold text-gomad-green hover:underline">Daftar</a>
            </p>
        </div>
    </div>
@endsection
