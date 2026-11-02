@extends('layouts.app')

@section('title', 'Keamanan akun')

@section('content')
    <x-gm.page-header
        eyebrow="Profil"
        title="Keamanan akun"
        subtitle="Verifikasi dua langkah menambah kode sekali pakai saat masuk, sehingga kata sandi saja tidak cukup.">
        <x-slot:actions>
            <a href="{{ route('account.show') }}">
                <x-gm.button type="button" variant="secondary">Kembali ke profil</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-gm.card title="Verifikasi dua langkah">
                @php $enabled = $user->two_factor_enabled_at !== null; @endphp

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-black-900">
                            {{ $enabled ? 'Aktif' : 'Tidak aktif' }}
                        </p>
                        <p class="mt-1 text-xs text-gray-600">
                            @if ($enabled)
                                Kode dikirim ke {{ $user->email ?? 'email Anda' }} setiap kali masuk.
                                Aktif sejak {{ $user->two_factor_enabled_at->format('d M Y H:i') }}.
                            @else
                                Saat ini akun hanya dilindungi kata sandi.
                            @endif
                        </p>
                    </div>
                    <x-gm.badge :tone="$enabled ? 'success' : 'neutral'">
                        {{ $enabled ? 'Aktif' : 'Belum aktif' }}
                    </x-gm.badge>
                </div>

                @if (! $enabled)
                    @if ($pendingEnable)
                        <form method="post" action="{{ route('account.two-factor.confirm') }}" class="mt-4 space-y-3">
                            @csrf
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    Kode yang dikirim ke {{ $user->email }}
                                </span>
                                <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                                       required autofocus class="tracking-[0.3em]">
                            </label>
                            <x-gm.button type="submit" variant="brand">Aktifkan sekarang</x-gm.button>
                        </form>
                    @else
                        <form method="post" action="{{ route('account.two-factor.start') }}" class="mt-4">
                            @csrf
                            <x-gm.button type="submit" variant="brand">Aktifkan verifikasi dua langkah</x-gm.button>
                        </form>
                    @endif
                @else
                    <form method="post" action="{{ route('account.two-factor.disable') }}"
                          class="mt-4 space-y-3"
                          onsubmit="return confirm('Matikan verifikasi dua langkah?')">
                        @csrf
                        @method('DELETE')
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                Kata sandi (wajib untuk mematikan)
                            </span>
                            <input type="password" name="password" required autocomplete="current-password">
                        </label>
                        <x-gm.button type="submit" variant="danger">Matikan</x-gm.button>
                    </form>
                @endif
            </x-gm.card>

            <x-gm.card title="Perangkat & sesi">
                <p class="text-sm text-gray-600">
                    Mengganti kata sandi akan mengeluarkan semua perangkat lain dari akun ini.
                    Lihat <a href="{{ route('account.show') }}" class="font-semibold text-black-900 hover:underline">profil</a>
                    untuk formulir ganti kata sandi.
                </p>
            </x-gm.card>
        </div>

        <aside class="space-y-4">
            <x-gm.card title="Cara kerjanya">
                <ol class="space-y-2 text-sm text-gray-600">
                    <li>1. Masukkan email dan kata sandi seperti biasa.</li>
                    <li>2. Kami mengirim 6 angka ke email Anda.</li>
                    <li>3. Masukkan kode itu; sesi baru dibuat setelah kode benar.</li>
                </ol>
            </x-gm.card>

            <x-gm.card title="Bila email tidak bisa diakses">
                <p class="text-sm text-gray-600">
                    Hubungi tim lewat menu Bantuan. Pemulihan akun dilakukan manual oleh staf
                    setelah identitas diverifikasi — tidak ada jalur pintas lewat email.
                </p>
            </x-gm.card>
        </aside>
    </div>
@endsection
