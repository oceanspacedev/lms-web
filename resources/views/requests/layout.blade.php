<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Pengajuan Dokumen') · {{ config('app.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/logo.svg') }}">
    <link rel="stylesheet" href="{{ asset('css/request-form.css') }}">
    <script src="{{ asset('js/request-form.js') }}" defer></script>
</head>
<body>
    <main><div class="brand"><img src="{{ asset('images/logo.svg') }}" alt="" width="32" height="32"><span>{{ config('app.name') }}</span></div>@yield('content')</main>
</body>
</html>
