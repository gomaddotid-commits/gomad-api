@props(['amount' => null])

{{-- DS §39: `Rp 150.000` adalah format tampilan, bukan format penyimpanan. --}}
<span {{ $attributes }}>{{ \App\Support\Rupiah::format($amount) }}</span>
