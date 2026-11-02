{{--
    Pesan sistem: galat validasi, status, dan galat umum.

    Dipisah dari layout karena tema consumer dan operasional memakai wadah berbeda;
    isi pesannya sama, jadi ditulis sekali (DS §9.2 "Toast/Alert" komponen bersama).
--}}
@if ($errors->any())
    <x-gm.alert tone="error" class="mb-5">
        <ul class="list-inside list-disc space-y-1">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </x-gm.alert>
@endif

@if (session('status'))
    <x-gm.alert tone="success" class="mb-5">{{ session('status') }}</x-gm.alert>
@endif

@if (session('error'))
    <x-gm.alert tone="error" class="mb-5">{{ session('error') }}</x-gm.alert>
@endif
