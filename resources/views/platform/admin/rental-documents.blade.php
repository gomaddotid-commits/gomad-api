@extends('layouts.app')

@section('title', 'Verifikasi dokumen sewa')

@section('content')
    @include('platform.admin._nav', ['active' => 'documents'])

    <div class="card">
        <h1>Verifikasi dokumen sewa</h1>
        <p class="muted">
            Hanya admin platform. Dokumen yang belum diverifikasi tampil lebih dulu.
            Agency tidak dapat memverifikasi maupun melihat dokumen di luar masa sewa berjalan.
        </p>
    </div>

    <div class="card">
        @forelse ($documents as $document)
            <div style="border-top:1px solid #e6edee;padding:14px 0">
                <div class="row">
                    <div>
                        <strong>{{ $document->label() }}</strong>
                        · {{ $document->user?->name }} ({{ $document->user?->email }})<br>
                        <span class="muted">
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
                            <button type="submit">Verifikasi</button>
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
            <p class="muted">Belum ada dokumen yang diunggah.</p>
        @endforelse
    </div>
@endsection
