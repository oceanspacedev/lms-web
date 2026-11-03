@props([
    'label' => null,
])
{{-- Kode (mis. OTP) dalam kotak seperti input form Filament. --}}
<table class="code" width="100%" cellpadding="0" cellspacing="0" role="presentation">
@if (filled($label))
<tr>
<td class="code-label">{{ $label }}</td>
</tr>
@endif
<tr>
<td class="code-box">{{ trim($slot) }}</td>
</tr>
</table>
