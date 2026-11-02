@extends('layouts.app')

@section('title', 'Pengguna & peran · Konsol operasional')

@section('content')
    <x-gm.page-header
        eyebrow="Konsol operasional"
        title="Pengguna & peran"
        subtitle="Peran platform (admin) hanya bisa diberikan lewat seeder/operasi terkontrol — halaman ini untuk memeriksa siapa punya akses apa.">
    </x-gm.page-header>

    @include('platform.admin._nav', ['active' => 'users'])

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="get" action="{{ route('admin.users') }}" class="flex items-end gap-2">
            <label class="block">
                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Cari pengguna</span>
                <input type="text" name="q" value="{{ $search }}" placeholder="nama, email, telepon">
            </label>
            <x-gm.button type="submit" size="sm" variant="secondary">Cari</x-gm.button>
        </form>

        <div class="flex flex-wrap gap-2 text-xs text-gray-600">
            @foreach ($roleCounts as $role => $total)
                <span class="rounded-pill bg-gray-100 px-2.5 py-1">{{ $role }}: {{ $total }}</span>
            @endforeach
        </div>
    </div>

    <x-gm.card :padded="false">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                        <th class="px-5 py-2">Nama</th>
                        <th class="px-5 py-2">Kontak</th>
                        <th class="px-5 py-2">Status</th>
                        <th class="px-5 py-2">Peran platform</th>
                        <th class="px-5 py-2 text-right">Usaha</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($users as $user)
                        <tr>
                            <td class="px-5 py-3 font-medium text-black-900">{{ $user->name }}</td>
                            <td class="px-5 py-3 text-gray-600">
                                {{ $user->email }}
                                @if ($user->phone)
                                    <span class="block text-xs">{{ $user->phone }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                <x-gm.badge :tone="$user->status === 'active' ? 'success' : 'neutral'">
                                    {{ $user->status }}
                                </x-gm.badge>
                            </td>
                            <td class="px-5 py-3">
                                @forelse ($user->platformRoles as $role)
                                    <x-gm.badge tone="info">{{ $role->role }}</x-gm.badge>
                                @empty
                                    <span class="text-xs text-gray-500">—</span>
                                @endforelse
                            </td>
                            <td class="px-5 py-3 text-right text-gray-600">{{ $user->businesses_count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-gm.card>

    <div class="mt-4">{{ $users->links() }}</div>
@endsection
