@extends('layouts.app')

@section('title', 'Terima undangan | GoMad')

@section('content')
    {{--
        Penerimaan undangan. Halaman ini dibuka dari tautan di WhatsApp/SMS, sering
        di ponsel, dan sering oleh orang yang belum punya akun — jadi formulirnya
        satu kolom dan penjelasannya mendahului isian.
    --}}
    <div class="mx-auto w-full max-w-xl py-6 sm:py-10">
        <div class="rounded-2xl border border-gray-200 bg-white px-6 py-8 shadow-sm sm:px-8">
            <p class="gm-label uppercase tracking-wide text-gomad-green">Undangan tim</p>
            <h1 class="gm-h2 mt-1 text-gomad-green-deep">Terima undangan</h1>

            <p class="mt-2 text-sm text-gray-500">
                Sudah punya akun dengan nomor yang diundang?
                <a href="{{ route('login') }}" class="font-semibold text-gomad-green hover:underline">Masuk dulu</a>,
                lalu buka kembali tautan undangan ini.
            </p>

            <form method="post" action="{{ route('invitations.accept') }}" class="mt-6 grid gap-4">
                @csrf
                <input type="hidden" name="token" value="{{ old('token') }}" required>

                @guest
                    <div>
                        <label for="name">Nama</label>
                        <input id="name" name="name" value="{{ old('name') }}" autocomplete="name" required
                               class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm">
                    </div>

                    <div>
                        <label for="email">Email (opsional)</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email"
                               class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm">
                    </div>

                    <div>
                        <label for="password">Kata sandi (minimal 12 karakter)</label>
                        <input id="password" name="password" type="password" autocomplete="new-password" required
                               class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm">
                    </div>

                    <div>
                        <label for="password_confirmation">Ulangi kata sandi</label>
                        <input id="password_confirmation" name="password_confirmation" type="password"
                               autocomplete="new-password" required
                               class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm">
                    </div>
                @endguest

                <x-gm.button type="submit" variant="brand" size="lg" class="min-h-[52px] w-full rounded-pill">
                    Terima undangan
                </x-gm.button>
            </form>

            @auth
                <form method="post" action="{{ route('invitations.logout') }}" class="mt-4 border-t border-gray-200 pt-4">
                    @csrf
                    <input type="hidden" name="token" value="{{ old('token') }}">
                    <p class="text-xs text-gray-500">
                        Anda sedang masuk sebagai {{ auth()->user()->name }}. Bila undangan ini untuk akun lain,
                        keluar dulu.
                    </p>
                    <x-gm.button type="submit" variant="secondary" class="mt-2 w-full">
                        Keluar dan lanjutkan dengan akun staff
                    </x-gm.button>
                </form>
            @endauth
        </div>
    </div>

    <script>
        // Token dikirim di belakang tanda `#` supaya tidak pernah masuk log server.
        const invitationToken = location.hash.slice(1);
        if (/^[a-f0-9]{64}$/i.test(invitationToken)) {
            document.querySelectorAll('input[name="token"]').forEach((field) => {
                if (!field.value) field.value = invitationToken;
            });
        }
    </script>
@endsection
