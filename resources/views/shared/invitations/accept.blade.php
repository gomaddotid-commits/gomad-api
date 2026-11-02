@extends('layouts.app')

@section('title', 'Terima undangan | GoMad')

@section('content')
<section class="card">
    <h1>Terima undangan GoMad</h1>
    <p>Jika sudah memiliki akun dengan nomor telepon yang diundang, <a href="{{ route('login') }}">masuk dahulu</a> lalu buka kembali tautan undangan.</p>
    <form method="post" action="{{ route('invitations.accept') }}">
        @csrf
        <input type="hidden" name="token" value="{{ old('token') }}" required>
        @guest
            <label for="name">Nama</label>
            <input id="name" name="name" value="{{ old('name') }}" autocomplete="name" required>
            <label for="email">Email (opsional)</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email">
            <label for="password">Kata sandi (minimal 12 karakter)</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required>
            <label for="password_confirmation">Ulangi kata sandi</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
        @endguest
        <button type="submit">Terima undangan</button>
    </form>
    @auth
        <form method="post" action="{{ route('invitations.logout') }}">
            @csrf
            <input type="hidden" name="token" value="{{ old('token') }}">
            <button type="submit">Keluar dan lanjutkan dengan akun staff</button>
        </form>
    @endauth
</section>
<script>
    const invitationToken = location.hash.slice(1);
    if (/^[a-f0-9]{64}$/i.test(invitationToken)) {
        document.querySelectorAll('input[name="token"]').forEach((field) => {
            if (!field.value) field.value = invitationToken;
        });
    }
</script>
@endsection
