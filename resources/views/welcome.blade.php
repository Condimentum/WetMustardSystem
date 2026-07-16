<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Laravel</title>

        <!-- Fonts -->
        <!DOCTYPE html>
        <html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">

            <title>{{ config('app.name', 'WetMustardSystem') }}</title>

            <link rel="preconnect" href="https://fonts.bunny.net">
            <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        </head>
        <body class="min-h-screen bg-slate-100 text-slate-900 antialiased">
            <div class="min-h-screen bg-[radial-gradient(circle_at_top,_rgba(59,130,246,0.16),_transparent_42%),linear-gradient(180deg,_#f8fafc_0%,_#e2e8f0_100%)]">
                <div class="mx-auto flex min-h-screen max-w-6xl flex-col px-6 py-8 lg:px-10">
                    <header class="mb-10 flex items-center justify-between rounded-2xl border border-slate-200/80 bg-white/80 px-6 py-4 shadow-sm backdrop-blur">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.3em] text-sky-700">Condimentum</p>
                            <h1 class="text-2xl font-semibold text-slate-800">{{ config('app.name', 'WetMustardSystem') }}</h1>
                        </div>

                        @auth
                            <div class="text-right">
                                <p class="text-sm text-slate-500">Signed in as</p>
                                <p class="font-medium text-slate-800">{{ auth()->user()->email }}</p>
                            </div>
                        @endauth
                    </header>

                    <main class="grid flex-1 gap-6 lg:grid-cols-[1.25fr_0.9fr]">
                        <section class="rounded-[2rem] border border-slate-200 bg-slate-900 px-8 py-10 text-white shadow-xl shadow-slate-300/40 lg:px-12 lg:py-14">
                            <div class="max-w-2xl">
                                <p class="mb-4 inline-flex rounded-full border border-sky-400/30 bg-sky-400/10 px-4 py-1 text-xs font-semibold uppercase tracking-[0.3em] text-sky-200">
                                    Production Platform
                                </p>

                                <h2 class="text-4xl font-semibold tracking-tight text-white sm:text-5xl">
                                    Shared foundation with MintSystemNew, rebuilt in Laravel.
                                </h2>

                                <p class="mt-6 max-w-xl text-base leading-7 text-slate-300 sm:text-lg">
                                    The login flow mirrors the existing Microsoft 365 sign-in used in MintSystemNew. Module buttons and device-specific actions will follow later, but access control starts from the same OAuth entry point now.
                                </p>

                                <div class="mt-10 flex flex-wrap gap-4">
                                    @auth
                                        <a href="{{ route('dashboard') }}" class="inline-flex items-center rounded-xl bg-sky-500 px-5 py-3 text-sm font-semibold text-white transition hover:bg-sky-400">
                                            Continue to dashboard
                                        </a>

                                        <form method="POST" action="{{ route('logout') }}">
                                            @csrf
                                            <button type="submit" class="inline-flex items-center rounded-xl border border-white/20 bg-white/5 px-5 py-3 text-sm font-semibold text-white transition hover:bg-white/10">
                                                Sign out
                                            </button>
                                        </form>
                                    @else
                                        <a href="{{ route('auth.microsoft.redirect') }}" class="inline-flex items-center rounded-xl border border-white/20 bg-white/5 px-5 py-3 text-sm font-semibold text-white transition hover:bg-white/10">
                                            Sign in with Microsoft 365
                                        </a>
                                    @endauth
                                </div>

                                @if (session('auth_error'))
                                    <div class="mt-6 rounded-2xl border border-rose-300/40 bg-rose-400/10 px-4 py-3 text-sm text-rose-100">
                                        {{ session('auth_error') }}
                                    </div>
                                @endif
                            </div>
                        </section>

                        <aside class="grid gap-6">
                            <section class="rounded-[2rem] border border-slate-200 bg-white p-8 shadow-sm">
                                <p class="text-sm font-semibold uppercase tracking-[0.25em] text-slate-500">Authentication</p>
                                <h3 class="mt-4 text-2xl font-semibold text-slate-800">Microsoft OAuth</h3>
                                <p class="mt-4 text-sm leading-7 text-slate-600">
                                    Users are authenticated against Microsoft and then signed into Laravel using the returned email identity. The app keeps Laravel sessions while deferring identity verification to Microsoft 365.
                                </p>
                            </section>

                            <section class="rounded-[2rem] border border-slate-200 bg-white p-8 shadow-sm">
                                <p class="text-sm font-semibold uppercase tracking-[0.25em] text-slate-500">Current scope</p>
                                <ul class="mt-4 space-y-3 text-sm leading-7 text-slate-600">
                                    <li>Front page aligned to the new Laravel app.</li>
                                    <li>Sign-in flow replicated from MintSystemNew.</li>
                                    <li>Feature buttons intentionally deferred until modules are built.</li>
                                </ul>
                            </section>
                        </aside>
                    </main>
                </div>
            </div>
        </body>
        </html>

