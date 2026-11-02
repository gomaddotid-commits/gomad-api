@extends('layouts.app')

@section('title', $business->name.' | GoMad')

@section('content')
    {{--
        Anggota & undangan. Dipakai semua tipe usaha, jadi istilahnya netral:
        "peran" mengikuti tipe usaha (penjaga untuk warung, driver untuk agency,
        staf untuk keduanya).
    --}}
    <x-gm.page-header
        :title="$business->name"
        :subtitle="$business->type.' · '.$business->code">
        <x-slot:actions>
            <a href="{{ route('businesses.index') }}">
                <x-gm.button type="button" variant="secondary">← Semua usaha</x-gm.button>
            </a>
        </x-slot:actions>
    </x-gm.page-header>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-gm.card title="Anggota usaha" :subtitle="$members->count().' anggota'" :padded="false">
                <div class="divide-y divide-gray-100">
                    @forelse ($members as $member)
                        @php $isOwner = (int) $member->id === (int) $business->owner_user_id; @endphp

                        <div class="px-5 py-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-black-900">{{ $member->name }}</p>
                                    <p class="mt-0.5 text-xs text-gray-500">
                                        {{ $member->phone ?? 'tanpa nomor' }}
                                    </p>
                                </div>

                                <x-gm.badge :tone="$isOwner ? 'brand' : 'neutral'">
                                    {{ $isOwner ? 'Pemilik' : ucfirst((string) $member->pivot->role) }}
                                </x-gm.badge>
                            </div>

                            @unless ($isOwner)
                                <div class="mt-3 flex flex-wrap items-end gap-3">
                                    <form method="post"
                                          action="{{ route('businesses.members.update', [$business, $member->pivot->id]) }}"
                                          class="flex flex-wrap items-end gap-2">
                                        @csrf
                                        @method('PATCH')
                                        <label class="block">
                                            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Peran</span>
                                            <select id="role-{{ $member->pivot->id }}" name="role" required class="mt-1 w-40">
                                                @if ($business->type === 'warung')
                                                    <option value="penjaga" @selected($member->pivot->role === 'penjaga')>Penjaga</option>
                                                @endif
                                                @if ($business->type === 'agency')
                                                    <option value="driver" @selected($member->pivot->role === 'driver')>Driver</option>
                                                @endif
                                                <option value="staf" @selected($member->pivot->role === 'staf')>Staf</option>
                                            </select>
                                        </label>
                                        <x-gm.button type="submit" size="sm" variant="secondary">Simpan peran</x-gm.button>
                                    </form>

                                    <form method="post" action="{{ route('businesses.members.destroy', [$business, $member]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <x-gm.button type="submit" size="sm" variant="ghost">Nonaktifkan</x-gm.button>
                                    </form>
                                </div>
                            @endunless
                        </div>
                    @empty
                        <p class="px-5 py-6 text-sm text-gray-500">Belum ada anggota.</p>
                    @endforelse
                </div>
            </x-gm.card>
        </div>

        <aside class="space-y-5">
            <x-gm.card title="Undang staff">
                <form method="post" action="{{ route('businesses.invitations.store', $business) }}" class="space-y-3">
                    @csrf

                    <div>
                        <label for="phone">Nomor telepon staff</label>
                        <input id="phone" name="phone" value="{{ old('phone') }}" autocomplete="tel"
                               placeholder="08..." required
                               class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm">
                    </div>

                    <div>
                        <label for="role">Peran</label>
                        <select id="role" name="role" required class="mt-1 w-full">
                            @if ($business->type === 'warung')
                                <option value="penjaga">Penjaga</option>
                            @endif
                            @if ($business->type === 'agency')
                                <option value="driver">Driver</option>
                            @endif
                            <option value="staf">Staf</option>
                        </select>
                    </div>

                    <x-gm.button type="submit" variant="brand" class="w-full">Buat undangan</x-gm.button>
                </form>

                @if (session('invitation_url'))
                    {{-- Tautan hanya tampil sekali; kalau hilang, harus dibuat ulang. --}}
                    <div class="mt-4 rounded-md bg-lime-50 px-3 py-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gomad-green">Tautan undangan</p>
                        <p class="mt-1 text-sm text-gray-600">
                            Salin dan kirim ke staff. Tautan hanya ditampilkan saat dibuat dan berlaku 7 hari.
                        </p>
                        <input readonly value="{{ session('invitation_url') }}" aria-label="Tautan undangan"
                               class="mt-2 w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-xs">
                    </div>
                @endif
            </x-gm.card>

            <x-gm.card title="Ruang kerja">
                @if ($business->status === 'active')
                    <p class="text-sm text-gray-500">
                        Anggota yang sudah bergabung membuka ruang kerja sesuai perannya.
                    </p>
                    <a href="{{ $business->type === 'agency'
                            ? route('mad-trans.agency.show', $business)
                            : route('warung.show', $business) }}"
                       class="mt-3 block">
                        <x-gm.button type="button" variant="secondary" class="w-full">
                            {{ $business->type === 'agency' ? 'Buka ruang kerja travel' : 'Buka ruang kerja warung' }}
                        </x-gm.button>
                    </a>
                @else
                    <p class="text-sm text-gray-500">
                        Usaha belum diverifikasi, jadi ruang kerja belum bisa dibuka.
                    </p>
                    <a href="{{ route('businesses.index') }}" class="mt-3 block">
                        <x-gm.button type="button" variant="brand" class="w-full">Lengkapi profil usaha</x-gm.button>
                    </a>
                @endif
            </x-gm.card>
        </aside>
    </div>
@endsection
