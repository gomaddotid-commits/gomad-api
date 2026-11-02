@extends('layouts.app')

@section('title', 'Parameter platform · Konsol operasional')

@section('content')
    <x-gm.page-header
        eyebrow="Konsol operasional"
        title="Parameter platform"
        subtitle="Nilai yang sedang dipakai aplikasi. Perubahan dilakukan lewat variabel lingkungan, lalu deploy ulang.">
    </x-gm.page-header>

    @include('platform.admin._nav', ['active' => 'parameters'])

    @if ($warnings !== [])
        <div class="mb-5 space-y-2">
            @foreach ($warnings as $warning)
                <x-gm.alert tone="warning">{{ $warning }}</x-gm.alert>
            @endforeach
        </div>
    @endif

    <div class="grid gap-5 lg:grid-cols-2">
        @foreach ($groups as $group => $items)
            <x-gm.card :title="$group" :padded="false">
                <dl class="divide-y divide-gray-100">
                    @foreach ($items as $label => $value)
                        <div class="flex items-center justify-between gap-3 px-5 py-2.5 text-sm">
                            <dt class="text-gray-600">{{ $label }}</dt>
                            <dd class="font-semibold text-black-900">
                                {{ $value !== '' ? $value : 'belum diset' }}
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </x-gm.card>
        @endforeach
    </div>

    <div class="mt-5">
        <x-gm.alert tone="info">
            Halaman ini hanya membaca. Perubahan parameter harus lewat konfigurasi deployment supaya
            riwayatnya tercatat di repositori, bukan di database yang bisa berubah tanpa jejak.
        </x-gm.alert>
    </div>
@endsection
