@extends('layouts.app')

@section('title', 'Buat tiket bantuan')

@section('content')
    <x-gm.page-header
        eyebrow="Bantuan"
        title="Buat tiket"
        subtitle="Semakin jelas masalahnya, semakin cepat kami bisa membantu.">
        <x-slot:actions>
            <a href="{{ route('support.index') }}">
                <x-gm.button type="button" variant="secondary">Kembali</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-gm.card title="Detail masalah">
                <form method="post" action="{{ route('support.store') }}" class="space-y-4">
                    @csrf

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Kategori</span>
                            <select name="category" required>
                                @foreach ($categories as $category)
                                    <option value="{{ $category }}" @selected(old('category') === $category)>
                                        {{ ucfirst($category) }}
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Prioritas</span>
                            <select name="priority" required>
                                <option value="normal" @selected(old('priority', 'normal') === 'normal')>Normal</option>
                                <option value="high" @selected(old('priority') === 'high')>Mendesak (perjalanan hari ini)</option>
                            </select>
                        </label>
                    </div>

                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Judul singkat</span>
                        <input type="text" name="subject" maxlength="160" required value="{{ old('subject') }}"
                               placeholder="Contoh: Pembayaran berhasil tetapi tiket belum terbit">
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Kode pesanan (opsional)</span>
                        <input type="text" name="booking_code" maxlength="32"
                               value="{{ old('booking_code', $booking?->code) }}"
                               placeholder="MT-TR-...">
                        <span class="mt-1 block text-xs text-gray-500">
                            Isi bila masalahnya menyangkut pesanan tertentu. Hanya pesanan milik Anda yang diterima.
                        </span>
                    </label>

                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Ceritakan masalahnya</span>
                        <textarea name="body" rows="6" required maxlength="4000"
                                  placeholder="Waktu kejadian, nominal, dan apa yang sudah Anda coba.">{{ old('body') }}</textarea>
                    </label>

                    <x-gm.button type="submit" variant="brand">Kirim tiket</x-gm.button>
                </form>
            </x-gm.card>
        </div>

        <aside>
            <x-gm.card title="Sebelum mengirim">
                <ul class="space-y-2 text-sm text-gray-600">
                    <li>• Untuk pembatalan tiket, gunakan tombol <strong>Batalkan</strong> di halaman pesanan — tidak perlu tiket.</li>
                    <li>• Untuk dana yang sudah terlanjur dibayar, sebutkan kode pesanan agar tim keuangan bisa menelusuri.</li>
                    <li>• Jangan kirim nomor kartu, PIN, atau kata sandi di dalam tiket.</li>
                </ul>
            </x-gm.card>
        </aside>
    </div>
@endsection
