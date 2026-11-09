@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
])
{{-- Tombol selebar kartu seperti tombol "Masuk" di halaman login. --}}
<table class="action" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<a href="{{ $url }}" class="button button-{{ $color }}" target="_blank" rel="noopener">{!! $slot !!}</a>
</td>
</tr>
</table>
