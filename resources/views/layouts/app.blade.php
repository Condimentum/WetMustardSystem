<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <x-view-mode-boot />

        <!-- Add-to-home-screen / standalone app behaviour on tablets -->
        <link rel="manifest" href="{{ asset('manifest.json') }}">
        <meta name="theme-color" content="#0b4a3a">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="WetMustard">
        <link rel="apple-touch-icon" href="{{ asset('wet-mustard-booking-icon.png') }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles

        {{-- Mint backdrop with large green discs and a faint gear behind every page's content --}}
        <style>
            .min-h-screen.bg-gray-100 {
                background-color: #ffffff;
                background-image:
                    url('{{ asset('gear-graphic.png') }}'),
                    radial-gradient(circle at 100% 0, #0b4a3a 0, #0b4a3a 190px, transparent 191px);
                background-repeat: no-repeat;
                background-position: right 60px top 20px, 100% 0;
                background-size: 280px, auto;
                background-attachment: fixed;
            }
        </style>
    </head>
    <body class="font-sans antialiased">
        <x-loading-indicator />
        <x-view-mode-toggle />

        <div class="min-h-screen bg-gray-100">
            {{-- Account menu only shows on the dashboard banner; other pages just get the go-back button --}}
            @unless (request()->routeIs('dashboard'))
                {{-- Scrolls with the page; pulled down past main's/page's own padding so it straddles the card's top border --}}
                <div style="display:flex;justify-content:center;padding-top:0;position:relative;z-index:30;margin-bottom:-96px;">
                    {{-- Settings tool area always returns straight to the Main Menu, never to another Settings sub-page --}}
                    <x-go-back-button
                        :href="route('dashboard')"
                        label="Main Menu"
                        :use-stack="! request()->routeIs(['settings.*', 'reporting.admin', 'notifications.setup', 'audit.*'])"
                    />
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

        @livewireScripts
    </body>
</html>
