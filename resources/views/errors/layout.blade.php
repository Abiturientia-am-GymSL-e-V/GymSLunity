<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') === 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">

        <title>@yield('title') – {{ config('app.name', 'GymSLunity') }}</title>

        <script nonce="{{ Vite::cspNonce() }}">
            if ('{{ $appearance ?? 'system' }}' === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                document.documentElement.classList.add('dark');
            }
        </script>
        <style nonce="{{ Vite::cspNonce() }}">
            html { background-color: oklch(1 0 0); }
            html.dark { background-color: oklch(0.145 0 0); }
        </style>

        <link rel="icon" href="/images/gymslunity-logo-left.png" type="image/png">

        @fonts
        @vite('resources/css/app.css')
    </head>
    <body class="min-h-screen bg-background font-sans text-foreground antialiased">
        <main class="flex min-h-screen items-center justify-center px-6 py-12">
            <section class="w-full max-w-lg rounded-xl border bg-card p-8 text-center shadow-sm sm:p-10">
                <a href="{{ route('home') }}" class="mx-auto mb-8 flex w-fit items-center gap-3" aria-label="Zur Startseite von GymSLunity">
                    <img src="/images/gymslunity-logo-left.png" alt="" class="size-11 object-contain">
                    <img src="/images/gymslunity-logo-right.png" alt="GymSLunity" class="h-8 max-w-48 object-contain">
                </a>

                <p class="text-sm font-semibold tracking-wider text-muted-foreground">FEHLER @yield('code')</p>
                <h1 class="mt-3 text-2xl font-semibold tracking-tight sm:text-3xl">@yield('title')</h1>
                <p class="mt-4 text-sm leading-6 text-muted-foreground sm:text-base">@yield('message')</p>

                <a
                    href="{{ route('home') }}"
                    class="mt-8 inline-flex h-10 items-center justify-center rounded-md bg-primary px-5 text-sm font-medium text-primary-foreground shadow-sm transition-colors hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                >
                    Zur Startseite
                </a>
            </section>
        </main>
    </body>
</html>
