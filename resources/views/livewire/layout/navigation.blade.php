<?php

use App\Livewire\Actions\Logout;
use App\Models\NotificationEvent;
use Livewire\Attributes\Computed;
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

    #[Computed]
    public function initials(): string
    {
        $name = trim((string) (auth()->user()->name ?? ''));

        if ($name === '') {
            return '?';
        }

        $parts = preg_split('/\s+/', $name) ?: [$name];
        $initials = mb_substr($parts[0], 0, 1);

        if (count($parts) > 1) {
            $initials .= mb_substr($parts[count($parts) - 1], 0, 1);
        }

        return mb_strtoupper($initials);
    }

    #[Computed]
    public function avatarColor(): string
    {
        // Deterministic Teams-style palette pick per user, so the same person always gets the same colour.
        $palette = ['#c0392b', '#8e44ad', '#2980b9', '#16a085', '#d35400', '#2c3e50', '#27ae60', '#7f8c8d'];
        $seed = crc32((string) (auth()->id() ?? auth()->user()->email ?? ''));

        return $palette[$seed % count($palette)];
    }

    #[Computed]
    public function openNotificationsCount(): int
    {
        return NotificationEvent::query()->where('status', NotificationEvent::STATUS_OPEN)->count();
    }
}; ?>

@php
    $isTestEnvironment = app()->environment(['local', 'development', 'testing', 'staging']);
@endphp

<nav x-data="{ open: false }" class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 shadow-sm backdrop-blur">
    <div class="mx-auto flex h-20 max-w-[1400px] items-center justify-between gap-3 px-4 sm:px-6 lg:px-8">
        <div class="flex min-w-0 items-center gap-4">
            <a href="{{ route('dashboard') }}" wire:navigate class="shrink-0">
                <img src="{{ asset('assets/condimentum-logo.png') }}" alt="{{ config('app.name', 'Wet Mustard System') }}" class="h-12 w-auto" />
            </a>
        </div>

        <div class="hidden items-center gap-3 sm:flex">
            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-bold tracking-wide {{ $isTestEnvironment ? 'bg-amber-500 text-white' : 'bg-emerald-600 text-white' }}">
                {{ $isTestEnvironment ? 'Test DB' : 'Live DB' }}
            </span>

            {{-- On the dashboard the avatar menu is rendered on top of the banner instead --}}
            @unless (request()->routeIs('dashboard'))
                <livewire:layout.user-menu />
            @endunless
        </div>

        <button @click="open = !open" type="button" class="inline-flex items-center justify-center rounded-md p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-700 sm:hidden" aria-label="Toggle menu">
            <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                <path :class="{ 'hidden': open, 'inline-flex': !open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                <path :class="{ 'hidden': !open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <div :class="{ 'block': open, 'hidden': !open }" class="hidden border-t border-slate-200 bg-white sm:hidden">
        <div class="border-t border-slate-200 px-4 py-3 text-sm">
            <div class="mb-3 flex items-center gap-3">
                <div class="flex h-12 w-12 shrink-0 aspect-square items-center justify-center rounded-full text-base font-bold text-white" style="background:{{ $this->avatarColor }};">{{ $this->initials }}</div>
                <div class="min-w-0">
                    <div class="truncate text-sm font-semibold text-slate-900">{{ auth()->user()->name }}</div>
                    <div class="truncate text-xs text-slate-500">{{ auth()->user()->email }}</div>
                </div>
                @if (\Illuminate\Support\Str::lower((string) (auth()->user()->email ?? '')) === 'adrian.lacki@condimentum.co.uk')
                    <a href="{{ route('settings.admin') }}" wire:navigate aria-label="Settings" class="relative flex h-10 w-10 shrink-0 aspect-square items-center justify-center overflow-hidden rounded-full ring-[0.5px] {{ request()->routeIs('settings.*') || request()->routeIs('reporting.*') || request()->routeIs('notifications.setup') || request()->routeIs('audit.*') ? 'ring-sky-700' : 'ring-slate-300' }}">
                        <img src="{{ asset('settings-icon.jpg') }}" alt="" class="h-full w-full object-cover" />
                    </a>
                @endif
                <span class="ml-auto inline-flex items-center rounded-full px-3 py-1 text-xs font-bold tracking-wide {{ $isTestEnvironment ? 'bg-amber-500 text-white' : 'bg-emerald-600 text-white' }}">
                    {{ $isTestEnvironment ? 'Test DB' : 'Live DB' }}
                </span>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('profile') }}" wire:navigate class="inline-flex items-center rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100">View account</a>
                <a href="{{ route('notifications.index') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100">
                    Notifications
                    @if ($this->openNotificationsCount > 0)
                        <span class="inline-flex items-center rounded-full bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-700">{{ $this->openNotificationsCount }}</span>
                    @endif
                </a>
                <button wire:click="logout" type="button" class="inline-flex items-center rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100">Sign out</button>
            </div>
        </div>
    </div>
</nav>
