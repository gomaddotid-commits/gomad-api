@extends('layouts.app')

@section('title', 'Verifikasi mitra · Konsol operasional')

@section('content')
    <x-gm.page-header
        eyebrow="Konsol operasional"
        title="Verifikasi mitra"
        subtitle="Mitra yang belum diverifikasi belum boleh melayani transaksi. Verifikasi juga mengaktifkan usaha yang masih draf.">
    </x-gm.page-header>

    @include('platform.admin._nav', ['active' => 'partners'])

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach ([null => 'Semua', 'warung' => 'Warung', 'grosir' => 'Grosir', 'agency' => 'Agency'] as $value => $label)
            @php $isActive = $type === $value; @endphp
            <a href="{{ route('admin.partners', array_filter(['type' => $value])) }}"
               class="rounded-pill border px-3 py-1.5 text-xs font-semibold
                      {{ $isActive ? 'border-black-900 bg-black-900 text-white' : 'border-gray-300 bg-white text-gray-600 hover:text-black-900' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    @if ($businesses->isEmpty())
        <x-gm.empty title="Belum ada mitra" description="Mitra muncul setelah pengguna membuat usaha." />
    @else
        <x-gm.card :padded="false">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                            <th class="px-5 py-2">Usaha</th>
                            <th class="px-5 py-2">Pemilik</th>
                            <th class="px-5 py-2">Status usaha</th>
                            <th class="px-5 py-2">Verifikasi</th>
                            <th class="px-5 py-2 text-right">Tindakan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($businesses as $business)
                            <tr>
                                <td class="px-5 py-3">
                                    <p class="font-semibold text-black-900">{{ $business->name }}</p>
                                    <p class="text-xs text-gray-600">{{ $business->type }} · {{ $business->code }}</p>
                                </td>
                                <td class="px-5 py-3 text-gray-600">
                                    {{ $business->owner?->name ?? '—' }}
                                    <span class="block text-xs">{{ $business->owner?->email }}</span>
                                </td>
                                <td class="px-5 py-3">
                                    <x-gm.badge :tone="$business->status === 'active' ? 'success' : 'warning'">
                                        {{ $business->status }}
                                    </x-gm.badge>
                                </td>
                                <td class="px-5 py-3 text-gray-600">
                                    @if ($business->verified_at !== null)
                                        <x-gm.badge tone="success">Terverifikasi</x-gm.badge>
                                        <span class="mt-1 block text-xs">{{ $business->verified_at->format('d M Y H:i') }}</span>
                                    @else
                                        <x-gm.badge tone="neutral">Belum</x-gm.badge>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <form method="post" action="{{ route('admin.partners.verify', $business) }}">
                                        @csrf
                                        <input type="hidden" name="verified" value="{{ $business->verified_at === null ? '1' : '0' }}">
                                        <x-gm.button type="submit" size="sm"
                                            :variant="$business->verified_at === null ? 'brand' : 'ghost'">
                                            {{ $business->verified_at === null ? 'Verifikasi' : 'Cabut verifikasi' }}
                                        </x-gm.button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-gm.card>

        <div class="mt-4">{{ $businesses->links() }}</div>
    @endif
@endsection
