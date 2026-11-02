@extends('layouts.app')

@section('title', 'Dokumen penyewa — '.$rental->code)

@section('content')
    <div class="card">
        <h1>Dokumen penyewa</h1>
        <p class="muted">
            Sewa {{ $rental->code }} sedang berjalan. Akses dokumen ini berlaku selama masa sewa dan
            otomatis dicabut setelah sewa diselesaikan.
        </p>
        <p><a href="{{ route('mad-trans.agency.show', $business) }}">← Kembali ke portal agency</a></p>
    </div>

    <div class="card">
        @forelse ($documents as $document)
            <div class="row" style="border-top:1px solid #e6edee;padding:12px 0">
                <div>
                    <strong>{{ $document->label() }}</strong><br>
                    <span class="muted">terverifikasi {{ $document->verified_at?->toDateString() }}</span>
                </div>
                <div>
                    <a href="{{ route('rental-documents.show', $document) }}">Lihat berkas</a>
                </div>
            </div>
        @empty
            <p class="muted">Tidak ada dokumen terverifikasi untuk penyewa ini.</p>
        @endforelse
    </div>
@endsection
