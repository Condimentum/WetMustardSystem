<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-100">
            <livewire:layout.navigation />

            @unless (request()->routeIs('dashboard'))
                <div style="background:linear-gradient(135deg,#f8fafc 0%,#eef2ff 100%);border-bottom:1px solid #e2e8f0;">
                    <div class="max-w-[1400px] mx-auto px-4 sm:px-6 lg:px-8">
                        <a href="{{ route('dashboard') }}" wire:navigate style="display:inline-flex;align-items:center;gap:8px;padding:12px 0;color:#3730a3;font-size:13px;font-weight:800;letter-spacing:.02em;text-decoration:none;">
                            <svg style="width:16px;height:16px;flex-shrink:0;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                            </svg>
                            Main Menu
                        </a>
                    </div>
                </div>
            @endunless

            <!-- Page Heading -->
            @if (isset($header))
                <header class="bg-white shadow">
                    <div class="max-w-[1400px] mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endif

            <!-- Page Content -->
            <main class="max-w-[1400px] mx-auto px-4 sm:px-6 lg:px-8 py-6">
                {{ $slot }}
            </main>
        </div>

        <x-loading-indicator />

        @livewireScripts
    </body>
</html>
