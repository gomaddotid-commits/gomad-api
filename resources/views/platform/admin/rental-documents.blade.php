@extends('layouts.app')

@section('title', 'Verifikasi dokumen sewa')

@section('content')
    @include('platform.admin._nav', ['active' => 'documents'])

    <div class="gm-surface p-5 mb-4">
        <h1 class="gm-h2 text-black-900 mb-1">Verifikasi dokumen sewa</h1>
        <p class="text-sm text-gray-500">
            Hanya admin platform. Dokumen yang belum diverifikasi tampil lebih dulu.
            Agency tidak dapat memverifikasi maupun melihat dokumen di luar masa sewa berjalan.
        </p>
    </div>

    <div class="gm-surface p-5 mb-4">
        @forelse ($documents as $document)
            <div class="gm-list-block">
                <div class="row">
                    <div>
                        <strong>{{ $document->label() }}</strong>
                        · {{ $document->user?->name }} ({{ $document->user?->email }})<br>
                        <span class="text-sm text-gray-500">
                            {{ $document->status }}
                            @if ($document->verified_at)
                                · {{ $document->verified_at->toDateTimeString() }}
                            @endif
                            @if ($document->rejection_reason)
                                · {{ $document->rejection_reason }}
                            @endif
                        </span>
                    </div>
                    <div>
                        <a href="{{ route('rental-documents.show', $document) }}">Lihat berkas</a>
                    </div>
                </div>

                @if ($document->status !== 'verified')
                    <div class="row" style="justify-content:flex-start;gap:8px;flex-wrap:wrap;margin-top:8px">
                        <form class="inline" method="post"
                              action="{{ route('admin.rental-documents.verify', $document) }}">
                            @csrf
                            <x-gm.button type="submit">Verifikasi</x-gm.button>
                        </form>
                        <form class="inline" method="post"
                              action="{{ route('admin.rental-documents.reject', $document) }}">
                            @csrf
                            <input type="text" name="rejection_reason" placeholder="Alasan penolakan" required
                                   style="width:auto;min-width:220px">
                            <button type="submit" style="background:#a12622">Tolak</button>
                        </form>
                    </div>
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-500">Belum ada dokumen yang diunggah.</p>
        @endforelse
    </div>
@endsection
