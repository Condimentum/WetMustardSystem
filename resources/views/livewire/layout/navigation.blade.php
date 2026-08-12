<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

@php
    $isTestEnvironment = app()->environment(['local', 'development', 'testing', 'staging']);
    $linkBase = 'inline-flex items-center rounded-md px-3 py-2 text-sm font-medium transition';
    $linkIdle = 'text-slate-700 hover:bg-sky-50 hover:text-sky-700';
    $linkActive = 'bg-sky-700 text-white';
@endphp

<nav x-data="{ open: false }" class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 shadow-sm backdrop-blur">
    <div class="mx-auto flex h-20 max-w-[1400px] items-center justify-between gap-3 px-4 sm:px-6 lg:px-8">
        <div class="flex min-w-0 items-center gap-4">
            <a href="{{ route('dashboard') }}" wire:navigate class="shrink-0">
                <img src="{{ asset('assets/condimentum-logo.png') }}" alt="{{ config('app.name', 'Wet Mustard System') }}" class="h-12 w-auto" />
            </a>

            @unless (request()->routeIs('dashboard'))
                <a href="{{ route('dashboard') }}" wire:navigate class="hidden sm:inline-flex items-center gap-1.5 rounded-md px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-sky-50 hover:text-sky-700">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                    Main Menu
                </a>
            @endunless
        </div>

        <div class="hidden items-center gap-2 sm:flex">
            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-bold tracking-wide {{ $isTestEnvironment ? 'bg-amber-500 text-white' : 'bg-emerald-600 text-white' }}">
                {{ $isTestEnvironment ? 'Test DB' : 'Live DB' }}
            </span>
            <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                {{ auth()->user()->email }}
            </span>
            @can('admin')
                <a href="{{ route('settings.admin') }}" wire:navigate class="{{ $linkBase }} {{ request()->routeIs('settings.*') || request()->routeIs('reporting.*') || request()->routeIs('notifications.*') || request()->routeIs('audit.*') ? $linkActive : $linkIdle }}">Settings</a>
            @endcan
            <a href="{{ route('profile') }}" wire:navigate class="{{ $linkBase }} {{ request()->routeIs('profile') ? $linkActive : $linkIdle }}">Profile</a>
            <button wire:click="logout" type="button" class="inline-flex items-center rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100">Sign out</button>
        </div>

        <button @click="open = !open" type="button" class="inline-flex items-center justify-center rounded-md p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-700 sm:hidden" aria-label="Toggle menu">
            <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                <path :class="{ 'hidden': open, 'inline-flex': !open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                <path :class="{ 'hidden': !open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <div :class="{ 'block': open, 'hidden': !open }" class="hidden border-t border-slate-200 bg-white sm:hidden">
        <div class="space-y-1 px-4 py-3">
            @unless (request()->routeIs('dashboard'))
                <a href="{{ route('dashboard') }}" wire:navigate class="block rounded-md px-3 py-2 text-sm font-medium text-slate-700 hover:bg-sky-50 hover:text-sky-700">&larr; Main Menu</a>
            @endunless
            @can('admin')
                <a href="{{ route('settings.admin') }}" wire:navigate class="block rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.*') || request()->routeIs('reporting.*') || request()->routeIs('notifications.*') || request()->routeIs('audit.*') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Settings</a>
            @endcan
        </div>

        <div class="border-t border-slate-200 px-4 py-3 text-sm">
            <div class="mb-2 flex items-center gap-2">
                <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-bold tracking-wide {{ $isTestEnvironment ? 'bg-amber-500 text-white' : 'bg-emerald-600 text-white' }}">
                    {{ $isTestEnvironment ? 'Test DB' : 'Live DB' }}
                </span>
                <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">{{ auth()->user()->name }}</span>
            </div>
            <p class="mb-3 text-xs text-slate-500">{{ auth()->user()->email }}</p>
            <div class="flex items-center gap-2">
                <a href="{{ route('profile') }}" wire:navigate class="inline-flex items-center rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100">Profile</a>
                <button wire:click="logout" type="button" class="inline-flex items-center rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100">Sign out</button>
            </div>
        </div>
    </div>
</nav>
