@extends('layouts.app')

@section('title', $business->name.' | GoMad')

@section('content')
<p><a href="{{ route('businesses.index') }}">← Semua usaha</a></p>
<h1>{{ $business->name }}</h1>
<p class="muted">{{ $business->type }} · {{ $business->code }} · {{ $business->status }}</p>

<section class="card">
    <h2>Anggota usaha</h2>
    @forelse ($members as $member)
        <div class="card">
            <p>{{ $member->name }} · {{ $member->phone }} · {{ $member->pivot->role }}</p>
            @if ((int) $member->id !== (int) $business->owner_user_id)
                <form method="post" action="{{ route('businesses.members.update', [$business, $member->pivot->id]) }}">
                    @csrf
                    @method('PATCH')
                    <label for="role-{{ $member->pivot->id }}">Ubah peran</label>
                    <select id="role-{{ $member->pivot->id }}" name="role" required>
                        @if ($business->type === 'warung')<option value="penjaga" @selected($member->pivot->role === 'penjaga')>Penjaga</option>@endif
                        @if ($business->type === 'agency')<option value="driver" @selected($member->pivot->role === 'driver')>Driver</option>@endif
                        <option value="staf" @selected($member->pivot->role === 'staf')>Staf</option>
                    </select>
                    <button type="submit">Simpan peran</button>
                </form>
                <form method="post" action="{{ route('businesses.members.destroy', [$business, $member]) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit">Nonaktifkan anggota</button>
                </form>
            @endif
        </div>
    @empty
        <p>Belum ada anggota.</p>
    @endforelse
</section>

<section class="card">
    <h2>Undang staff</h2>
    <form method="post" action="{{ route('businesses.invitations.store', $business) }}">
        @csrf
        <label for="phone">Nomor telepon staff</label>
        <input id="phone" name="phone" value="{{ old('phone') }}" autocomplete="tel" placeholder="08..." required>
        <label for="role">Peran</label>
        <select id="role" name="role" required>
            @if ($business->type === 'warung')<option value="penjaga">Penjaga</option>@endif
            @if ($business->type === 'agency')<option value="driver">Driver</option>@endif
            <option value="staf">Staf</option>
        </select>
        <button type="submit">Buat undangan</button>
    </form>
    @if (session('invitation_url'))
        <p>Salin dan kirim tautan ini kepada staff. Tautan hanya ditampilkan saat dibuat dan berlaku 7 hari:</p>
        <input readonly value="{{ session('invitation_url') }}" aria-label="Tautan undangan">
    @endif
</section>
@endsection
