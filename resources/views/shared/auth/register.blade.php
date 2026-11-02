@extends('layouts.app')

@section('title', 'Daftar | GoMad')

@section('content')
    {{--
        Pendaftaran (DS v1.0 §8.5).

        Pilihan fungsi akun ditampilkan sebagai kartu pilihan, bukan radio polos:
        ini keputusan yang tidak bisa diubah sendiri setelahnya, jadi ia harus
        terbaca sebagai keputusan, bukan sebagai detail formulir.

        Permukaannya **padat**, bukan kaca: §8.4 meminta kaca tidak dipakai pada
        "complex forms". Gradien halaman tetap memberi karakter consumer, tetapi
        teks formulir tidak pernah duduk di atas latar tembus pandang.
    --}}
    <div class="mx-auto w-full max-w-2xl py-6 sm:py-10">
        <div class="rounded-2xl border border-gray-200 bg-white px-6 py-8 shadow-sm sm:px-8">
            <p class="gm-label uppercase tracking-wide text-gomad-green">GoMad</p>
            <h1 class="gm-h2 mt-1 text-gomad-green-deep">Buat akun</h1>

            <form method="post" action="{{ route('register') }}" class="mt-6 grid gap-4">
                @csrf

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="name">Nama</label>
                        <input id="name" name="name" value="{{ old('name') }}"
                               autocomplete="name" required
                               class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm">
                    </div>

                    <div>
                        <label for="phone">Nomor telepon Indonesia</label>
                        <input id="phone" name="phone" value="{{ old('phone') }}"
                               autocomplete="tel" placeholder="08..." required
                               class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm">
                    </div>
                </div>

                <div>
                    <label for="email">Email (opsional)</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}"
                           autocomplete="email"
                           class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm">
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="password">Kata sandi (minimal 12 karakter)</label>
                        <input id="password" name="password" type="password"
                               autocomplete="new-password" required
                               class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm">
                    </div>

                    <div>
                        <label for="password_confirmation">Ulangi kata sandi</label>
                        <input id="password_confirmation" name="password_confirmation" type="password"
                               autocomplete="new-password" required
                               class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm">
                    </div>
                </div>

                <fieldset class="mt-2">
                    <legend class="mb-1 text-sm font-semibold text-black-900">Daftar sebagai</legend>
                    <p class="mb-3 text-sm text-gray-500">
                        Satu akun hanya untuk satu fungsi. Ingin berjualan sekaligus memesan?
                        Buat dua akun terpisah.
                    </p>

                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach (($accountTypes ?? []) as $type)
                            <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 bg-white/70 px-3.5 py-3
                                          has-checked:border-gomad-lime has-checked:bg-lime-50">
                                <input type="radio" name="account_type" value="{{ $type->value }}"
                                       class="mt-1 shrink-0"
                                       @checked(old('account_type', 'customer') === $type->value)>
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold text-black-900">{{ $type->label() }}</span>
                                    <span class="mt-0.5 block text-xs text-gray-500">
                                        @switch($type->value)
                                            @case('customer')
                                                Pesan travel, sewa kendaraan, dan belanja kebutuhan.
                                                @break
                                            @case('warung_owner')
                                                Kelola warung retail: katalog, pesanan, penjaga, shift.
                                                @break
                                            @case('grosir_owner')
                                                Kelola grosir: harga grosir, pesanan masuk, pasokan warung.
                                                @break
                                            @case('agency_owner')
                                                Kelola travel &amp; rental: rute, jadwal, armada, supir.
                                                @break
                                        @endswitch
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    @error('account_type')
                        <p class="mt-2 text-sm font-medium text-error-600">{{ $message }}</p>
                    @enderror
                </fieldset>

                <div>
                    <label for="ref">Kode referral (opsional)</label>
                    <input id="ref" name="ref" value="{{ old('ref', $referralCode ?? '') }}"
                           maxlength="20" placeholder="GMXXXXXX"
                           class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm">
                    <p class="mt-1 text-xs text-gray-500">
                        Memakai kode referral membuat Anda mendapat diskon platform pada transaksi pertama,
                        dan pengundang mendapat hadiah dari platform (bukan potongan agency).
                    </p>
                </div>

                <x-gm.button type="submit" variant="brand" size="lg" class="w-full rounded-pill">
                    Daftar
                </x-gm.button>
            </form>

            <p class="mt-5 text-center text-sm text-gray-500">
                Sudah memiliki akun?
                <a href="{{ route('login') }}" class="font-semibold text-gomad-green hover:underline">Masuk</a>
            </p>
        </div>
    </div>
@endsection
