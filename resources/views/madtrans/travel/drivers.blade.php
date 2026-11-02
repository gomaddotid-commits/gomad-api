@extends('layouts.app')

@section('title', 'Pengemudi '.$business->name)

@section('content')
    <x-gm.page-header
        eyebrow="Ruang kerja agency"
        title="Pengemudi"
        :subtitle="$business->name.' · '.$drivers->count().' supir aktif'">
        <x-slot:actions>
            <a href="{{ route('businesses.show', $business) }}">
                <x-gm.button type="button" variant="secondary">Kelola anggota tim</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    @include('madtrans.travel._nav', ['business' => $business, 'active' => 'drivers'])

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-3">
            <x-gm.card title="Supir aktif" :padded="false">
                <div class="divide-y divide-gray-100">
                    @forelse ($drivers as $driver)
                        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                            <div>
                                <p class="text-sm font-semibold text-black-900">{{ $driver->name }}</p>
                                <p class="text-xs text-gray-600">{{ $driver->email ?? $driver->phone ?? 'tanpa kontak' }}</p>
                            </div>
                            <x-gm.badge tone="success">Aktif</x-gm.badge>
                        </div>
                    @empty
                        <div class="px-5 py-6 text-sm text-gray-600">
                            Belum ada supir. Undang lewat halaman anggota tim, lalu tetapkan perannya sebagai supir.
                        </div>
                    @endforelse
                </div>
            </x-gm.card>

            <x-gm.card title="Penugasan terdekat" subtitle="Jadwal dengan supir yang sudah ditetapkan." :padded="false">
                <div class="divide-y divide-gray-100">
                    @forelse ($assignments as $assignment)
                        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                            <div>
                                <p class="text-sm font-semibold text-black-900">
                                    {{ $assignment->driverMembership?->user?->name ?? 'Supir' }}
                                </p>
                                <p class="text-xs text-gray-600">
                                    {{ $assignment->route?->origin_city }} → {{ $assignment->route?->destination_city }}
                                    · {{ $assignment->departure_date->toDateString() }}
                                    {{ substr((string) $assignment->departure_time, 0, 5) }}
                                </p>
                            </div>
                            <a href="{{ route('mad-trans.agency.schedules.show', [$business, $assignment]) }}"
                               class="text-sm font-semibold text-black-900 hover:underline">Detail</a>
                        </div>
                    @empty
                        <div class="px-5 py-6 text-sm text-gray-600">
                            Belum ada penugasan. Tetapkan supir saat membuat jadwal.
                        </div>
                    @endforelse
                </div>
            </x-gm.card>
        </div>

        <aside class="space-y-5">
            <x-gm.card title="Anggota tim" :padded="false">
                <div class="divide-y divide-gray-100">
                    @foreach ($members as $member)
                        <div class="flex items-center justify-between gap-2 px-5 py-2.5 text-sm">
                            <span class="font-medium text-black-900">{{ $member->name }}</span>
                            <x-gm.badge tone="neutral">{{ $member->pivot->role ?? '-' }}</x-gm.badge>
                        </div>
                    @endforeach
                </div>
            </x-gm.card>

            <x-gm.card title="Tugas supir">
                <p class="text-sm text-gray-600">
                    Supir melihat daftar tugas, memulai perjalanan, dan menandai penumpang naik
                    dari halaman tugas supir — bukan dari halaman ini.
                </p>
                <a href="{{ route('mad-trans.driver.index') }}" class="mt-3 block">
                    <x-gm.button type="button" variant="secondary" class="w-full">Buka halaman supir</x-gm.button>
                </a>
            </x-gm.card>
        </aside>
    </div>
@endsection
