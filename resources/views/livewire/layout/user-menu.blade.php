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

    #[Computed]
    public function canAccessSettings(): bool
    {
        return \Illuminate\Support\Str::lower((string) (auth()->user()->email ?? '')) === 'adrian.lacki@condimentum.co.uk';
    }

    #[Computed]
    public function isTestEnvironment(): bool
    {
        return app()->environment(['local', 'development', 'testing', 'staging']);
    }
}; ?>

<div class="flex shrink-0 items-center gap-3">
    <div x-data="{ userMenuOpen: false }" class="relative shrink-0">
        <button
            @click="userMenuOpen = !userMenuOpen"
            @click.outside="userMenuOpen = false"
            type="button"
            class="relative flex h-12 w-12 shrink-0 aspect-square items-center justify-center rounded-full text-base font-bold text-white shadow-md ring-2 ring-white transition hover:opacity-90"
            style="background:{{ $this->avatarColor }};"
            aria-label="Account menu"
        >
            {{ $this->initials }}
            @if ($this->openNotificationsCount > 0)
                <span class="absolute -top-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full bg-red-600 text-[10px] font-bold text-white ring-2 ring-white">{{ $this->openNotificationsCount > 9 ? '9+' : $this->openNotificationsCount }}</span>
            @endif
        </button>

        <div x-show="userMenuOpen" x-transition style="display:none;" class="absolute right-0 z-50 mt-2 w-64 rounded-lg border border-slate-200 bg-white py-2 text-left shadow-lg">
            <div class="border-b border-slate-100 px-4 py-3">
                <div class="flex items-center gap-3">
                    <div class="flex h-12 w-12 shrink-0 aspect-square items-center justify-center rounded-full text-base font-bold text-white" style="background:{{ $this->avatarColor }};">{{ $this->initials }}</div>
                    <div class="min-w-0">
                        <div class="truncate text-sm font-semibold text-slate-900">{{ auth()->user()->name }}</div>
                        <div class="truncate text-xs text-slate-500">{{ auth()->user()->email }}</div>
                    </div>
                </div>
                <span class="mt-2 inline-flex items-center rounded-full px-3 py-1 text-xs font-bold tracking-wide {{ $this->isTestEnvironment ? 'bg-amber-500 text-white' : 'bg-emerald-600 text-white' }}">
                    {{ $this->isTestEnvironment ? 'Test DB' : 'Live DB' }}
                </span>
            </div>

            <a href="{{ route('profile') }}" wire:navigate class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">View account</a>

            @if ($this->canAccessSettings)
                <a href="{{ route('settings.admin') }}" wire:navigate class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Settings</a>
            @endif

            <a href="{{ route('notifications.index') }}" wire:navigate class="flex items-center justify-between px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                <span>Notifications</span>
                @if ($this->openNotificationsCount > 0)
                    <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700">{{ $this->openNotificationsCount }} open</span>
                @endif
            </a>

            <button wire:click="logout" type="button" class="block w-full px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-50">Sign out</button>
        </div>
    </div>
</div>
