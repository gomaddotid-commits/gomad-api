@extends('layouts.app')

@section('title', 'Dokumen sewa')

@section('content')
    <div class="gm-surface p-5 mb-4">
        <h1 class="gm-h2 text-black-900 mb-1">Dokumen untuk sewa lepas kunci</h1>
        <p class="text-sm text-gray-500">
            Unggah KTP, SIM, dan selfie. Ketiganya diverifikasi <strong>admin GoMad</strong>, bukan oleh agency.
            Agency hanya dapat melihat dokumen selama sewa Anda berjalan, dan aksesnya dicabut begitu sewa selesai.
        </p>
        <p><a href="{{ route('mad-trans.index') }}">← Kembali</a></p>
    </div>

    @foreach ($types as $type)
        @php $document = $documents[$type] ?? null; @endphp
        <div class="gm-surface p-5 mb-4">
            <div class="row">
                <div>
                    <strong>{{ strtoupper($type) }}</strong><br>
                    <span class="text-sm text-gray-500">
                        @if ($document === null)
                            belum diunggah
                        @elseif ($document->status === 'verified')
                            terverifikasi {{ $document->verified_at?->toDateString() }}
                        @elseif ($document->status === 'rejected')
                            ditolak — {{ $document->rejection_reason }}
                        @else
                            menunggu verifikasi admin
                        @endif
                    </span>
                </div>
                <div>
                    @if ($document !== null)
                        <a href="{{ route('rental-documents.show', $document) }}">Lihat berkas</a>
                    @endif
                </div>
            </div>

            <form method="post" action="{{ route('rental-documents.store') }}" enctype="multipart/form-data" style="margin-top:10px">
                @csrf
                <input type="hidden" name="type" value="{{ $type }}">
                <label for="file_{{ $type }}">Berkas {{ strtoupper($type) }} (JPG/PNG/PDF, maks 4 MB)</label>
                <input id="file_{{ $type }}" type="file" name="file" accept=".jpg,.jpeg,.png,.pdf" required>
                <x-gm.button type="submit">{{ $document === null ? 'Unggah' : 'Ganti berkas' }}</x-gm.button>
            </form>
        </div>
    @endforeach
@endsection
