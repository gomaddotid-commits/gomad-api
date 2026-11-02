@extends('layouts.app')

@section('title', 'Dokumen penyewa — '.$rental->code)

@section('content')
    <div class="gm-surface p-5 mb-4">
        <h1 class="gm-h2 text-black-900 mb-1">Dokumen penyewa</h1>
        <p class="text-sm text-gray-500">
            Sewa {{ $rental->code }} sedang berjalan. Akses dokumen ini berlaku selama masa sewa dan
            otomatis dicabut setelah sewa diselesaikan.
        </p>
        <p><a href="{{ route('mad-trans.agency.show', $business) }}">← Kembali ke portal agency</a></p>
    </div>

    <div class="gm-surface p-5 mb-4">
        @forelse ($documents as $document)
            <div class="gm-list-row">
                <div>
                    <strong>{{ $document->label() }}</strong><br>
                    <span class="text-sm text-gray-500">terverifikasi {{ $document->verified_at?->toDateString() }}</span>
                </div>
                <div>
                    <a href="{{ route('rental-documents.show', $document) }}">Lihat berkas</a>
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500">Tidak ada dokumen terverifikasi untuk penyewa ini.</p>
        @endforelse
    </div>
@endsection
