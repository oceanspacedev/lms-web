@props([
    'icon',
    'title',
])
{{-- Kepala kartu seperti halaman login Mekaya: ikon dalam kotak, judul, lalu keterangan. Ikon PNG karena banyak klien email tidak menampilkan SVG. --}}
<table class="card-header" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="card-header-icon" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="card-header-icon-cell">
<img src="{{ $icon }}" width="20" height="20" alt="">
</td>
</tr>
</table>
<h1 class="card-header-title">{{ $title }}</h1>
@if (filled(trim($slot)))
<p class="card-header-description">{{ trim($slot) }}</p>
@endif
</td>
</tr>
</table>
