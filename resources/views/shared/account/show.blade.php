@extends('layouts.app')

@section('title', 'Profil saya')

@section('content')
    <x-gm.page-header
        eyebrow="Akun"
        title="Profil saya"
        subtitle="Satu akun untuk semua peran: penumpang, penjaga warung, supir, atau pemilik usaha." />

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-5">
            <x-gm.card title="Data diri" subtitle="Nama dan email bisa Anda ubah sendiri.">
                <form method="post" action="{{ route('account.update') }}" class="grid gap-4 sm:grid-cols-2">
                    @csrf
                    @method('PATCH')

                    <label class="sm:col-span-2">
                        <span class="text-sm font-semibold text-black-900">Nama lengkap</span>
                        <input type="text" name="name" value="{{ old('name', $user->name) }}" required maxlength="120">
                    </label>

                    <label>
                        <span class="text-sm font-semibold text-black-900">Email</span>
                        <input type="email" name="email" value="{{ old('email', $user->email) }}" maxlength="255">
                    </label>

                    <div>
                        <label for="phone-display" class="text-sm font-semibold text-black-900">Nomor telepon</label>
                        <input id="phone-display" type="text" value="{{ $user->phone }}" disabled>
                        <p class="mt-1 text-xs text-gray-500">
                            Nomor dipakai untuk masuk, jadi perubahan nomor lewat verifikasi, bukan di sini.
                        </p>
                    </div>

                    <div class="sm:col-span-2">
                        <x-gm.button type="submit" variant="brand">Simpan perubahan</x-gm.button>
                    </div>
                </form>
            </x-gm.card>

            <x-gm.card title="Keamanan" subtitle="Ganti kata sandi akan mengeluarkan sesi di perangkat lain.">
                <form method="post" action="{{ route('account.password') }}" class="grid gap-4 sm:grid-cols-2">
                    @csrf
                    @method('PATCH')

                    <label class="sm:col-span-2">
                        <span class="text-sm font-semibold text-black-900">Kata sandi saat ini</span>
                        <input type="password" name="current_password" required autocomplete="current-password">
                    </label>

                    <label>
                        <span class="text-sm font-semibold text-black-900">Kata sandi baru</span>
                        <input type="password" name="password" required minlength="12" autocomplete="new-password">
                    </label>

                    <label>
                        <span class="text-sm font-semibold text-black-900">Ulangi kata sandi baru</span>
                        <input type="password" name="password_confirmation" required minlength="12" autocomplete="new-password">
                    </label>

                    <div class="sm:col-span-2">
                        <x-gm.button type="submit" variant="secondary">Perbarui kata sandi</x-gm.button>
                    </div>
                </form>

                <div class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 pt-4">
                    <div>
                        <p class="text-sm font-semibold text-black-900">Verifikasi dua langkah</p>
                        <p class="text-xs text-gray-600">
                            {{ $user->two_factor_enabled_at !== null
                                ? 'Aktif sejak '.$user->two_factor_enabled_at->format('d M Y')
                                : 'Belum aktif — akun hanya dilindungi kata sandi.' }}
                        </p>
                    </div>
                    <a href="{{ route('account.security') }}" class="text-sm font-semibold text-black-900 hover:underline">
                        Atur keamanan →
                    </a>
                </div>
            </x-gm.card>

            <x-gm.card title="Peran & akses" subtitle="Akses mengikuti izin yang Anda pegang, bukan pilihan menu.">
                @if ($memberships->isEmpty())
                    <p class="text-sm text-gray-600">
                        Anda belum tergabung dalam usaha mana pun. Buat usaha atau terima undangan tim untuk
                        membuka portal pemilik.
                    </p>
                @else
                    <ul class="divide-y divide-gray-200">
                        @foreach ($memberships as $membership)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                <span class="font-semibold text-black-900">{{ $membership->business?->name }}</span>
                                <span class="flex items-center gap-2">
                                    <x-gm.badge tone="neutral">{{ ucfirst($membership->business?->type ?? '-') }}</x-gm.badge>
                                    <x-gm.badge tone="info">{{ $membership->role }}</x-gm.badge>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-gm.card>
        </div>

        <aside class="space-y-5">
            <x-gm.card title="Ringkasan">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-600">Riwayat sewa</dt>
                        <dd class="font-semibold text-black-900">{{ $rentalCount }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-600">Notifikasi belum dibaca</dt>
                        <dd class="font-semibold text-black-900">{{ $unreadNotifications }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-600">Poin loyalitas</dt>
                        <dd class="font-semibold text-black-900">{{ (int) $user->points_cached }}</dd>
                    </div>
                </dl>

                <div class="mt-4 space-y-2">
                    <a href="{{ route('mad-trans.bookings.index') }}" class="block">
                        <x-gm.button type="button" variant="secondary" class="w-full">Pesanan saya</x-gm.button>
                    </a>
                    <a href="{{ route('account.rentals') }}" class="block">
                        <x-gm.button type="button" variant="secondary" class="w-full">Riwayat sewa</x-gm.button>
                    </a>
                    <a href="{{ route('rental-documents.index') }}" class="block">
                        <x-gm.button type="button" variant="secondary" class="w-full">Dokumen lepas kunci</x-gm.button>
                    </a>
                    <a href="{{ route('loyalty.index') }}" class="block">
                        <x-gm.button type="button" variant="ghost" class="w-full">Level &amp; misi</x-gm.button>
                    </a>
                </div>
            </x-gm.card>
        </aside>
    </div>
@endsection
