<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <!-- Add-to-home-screen / standalone app behaviour on tablets -->
        <link rel="manifest" href="{{ asset('manifest.json') }}">
        <meta name="theme-color" content="#1c2552">
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

        {{-- Cream/gear backdrop behind every page's content, so no plain-gray background shows around the edges --}}
        <style>
            .min-h-screen.bg-gray-100 {
                background-color: #f8f4ea;
                background-image: url('{{ asset('gear-graphic.png') }}'), url('{{ asset('workspace-bg.png') }}?v={{ filemtime(public_path('workspace-bg.png')) }}');
                background-repeat: no-repeat, no-repeat;
                background-position: right -40px top -30px, center;
                background-size: 280px, cover;
                background-attachment: fixed;
            }
        </style>
    </head>
    <body class="font-sans antialiased">
        <x-loading-indicator />

        <div class="min-h-screen bg-gray-100">
            {{-- Account menu only shows on the dashboard banner; other pages just get the go-back button --}}
            @unless (request()->routeIs('dashboard'))
                {{-- Scrolls with the page; pulled down past main's/page's own padding so it straddles the card's top border --}}
                <div style="display:flex;justify-content:center;padding-top:0;position:relative;z-index:30;margin-bottom:-96px;">
                    <x-go-back-button :href="route('dashboard')" label="Main Menu" />
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
