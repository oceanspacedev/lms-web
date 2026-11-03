<x-mail::message>
<x-mail::card-header :icon="asset('images/mail/envelope.png')" :title="'Masuk ke '.config('app.name')">
Halo {{ $name }}, masukkan kode ini di halaman masuk atau klik tombol di bawah.
</x-mail::card-header>

<x-mail::code label="Kode OTP">
{{ $otp }}
</x-mail::code>

<x-mail::button :url="$loginUrl">
Masuk ke {{ config('app.name') }}
</x-mail::button>

<x-slot:subcopy>
Link dan kode berlaku {{ $expiresInMinutes }} menit dan hanya bisa dipakai sekali. Jangan bagikan kepada siapa pun. Jika Anda tidak meminta masuk, abaikan email ini.
</x-slot:subcopy>
</x-mail::message>
