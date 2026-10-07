<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Pengajuan Dokumen') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/request-form.css') }}">
    <script src="{{ asset('js/request-form.js') }}" defer></script>
</head>
<body>
    <main>@yield('content')</main>
</body>
</html>
