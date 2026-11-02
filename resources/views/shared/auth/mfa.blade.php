@extends('layouts.app')

@section('title', 'Verifikasi dua langkah')

@section('content')
    <div class="mx-auto w-full max-w-md">
        <x-gm.page-header
            eyebrow="Keamanan akun"
            title="Masukkan kode verifikasi"
            :subtitle="'Kami mengirim 6 angka ke '.($email ?? 'email Anda').'. Kode berlaku 10 menit.'" />

        <x-gm.card>
            <form method="post" action="{{ route('mfa.verify') }}" class="space-y-4">
                @csrf

                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Kode 6 angka</span>
                    <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                           pattern="[0-9]{6}" maxlength="6" required autofocus
                           class="tracking-[0.4em] text-center text-lg">
                </label>

                <x-gm.button type="submit" variant="brand" class="w-full">Verifikasi</x-gm.button>
            </form>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 pt-4">
                <form method="post" action="{{ route('mfa.resend') }}">
                    @csrf
                    <x-gm.button type="submit" size="sm" variant="ghost">Kirim kode baru</x-gm.button>
                </form>

                <form method="post" action="{{ route('mfa.cancel') }}">
                    @csrf
                    <x-gm.button type="submit" size="sm" variant="ghost">Batal, kembali ke masuk</x-gm.button>
                </form>
            </div>
        </x-gm.card>

        <p class="mt-4 text-center text-xs text-gray-500">
            Tidak menerima email? Periksa folder spam, atau kirim kode baru.
            Kode lama otomatis tidak berlaku saat kode baru dikirim.
        </p>
    </div>
@endsection
