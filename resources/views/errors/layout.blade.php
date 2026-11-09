<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/logo.svg') }}">
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1.5rem; box-sizing: border-box; background: #fafaf9; color: #18181b; font-family: ui-sans-serif, system-ui, sans-serif; }
        main { max-width: 28rem; text-align: center; }
        .code { margin: 0 0 .25rem; font-size: .8125rem; font-weight: 600; letter-spacing: .06em; color: #71717a; }
        h1 { margin: 0 0 .5rem; font-size: 1.125rem; }
        p { margin: 0; color: #52525b; line-height: 1.6; }
        a { display: inline-block; margin-top: 1.25rem; color: inherit; font-weight: 600; text-underline-offset: 3px; }
        @media (prefers-color-scheme: dark) { body { background: #09090b; color: #fafafa; } p { color: #a1a1aa; } .code { color: #a1a1aa; } }
    </style>
</head>
<body>
    <main>
        <p class="code">@yield('code')</p>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        @hasSection('action')
            @yield('action')
        @else
            <a href="{{ url('/') }}">Kembali ke beranda</a>
        @endif
    </main>
</body>
</html>
