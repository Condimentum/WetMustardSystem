<?php

use App\Support\FeatureSettings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Settings Admin')] class extends Component {
    /** @var array<string, bool> */
    public array $toggles = [];

    public ?string $flash = null;

    public string $flashLevel = 'success';

    /** @var array<int, array{key:string,label:string,description:string,default:bool}> */
    private array $definitions = [
        [
            'key' => 'allocation.scanner',
            'label' => 'Allocation Scanner View',
            'description' => 'Show Scanner view alongside Dropdown in ingredient allocation.',
            'default' => true,
        ],
        [
            'key' => 'allocation.scanner_camera_autostart',
            'label' => 'Scanner Camera Autostart',
            'description' => 'Automatically open tablet camera when Scanner view is selected.',
            'default' => true,
        ],
        [
            'key' => 'allocation.scanner_debug_panel',
            'label' => 'Scanner Debug Panel',
            'description' => 'Show parsed scan payload and technical lookup feedback.',
            'default' => true,
        ],
        [
            'key' => 'allocation.scanner_winman_lookup',
            'label' => 'Scanner WinMan Lookup',
            'description' => 'Validate scanned supplier lot against live WinMan lot availability.',
            'default' => true,
        ],
    ];

    public function mount(): void
    {
        foreach ($this->definitions as $definition) {
            $this->toggles[$definition['key']] = FeatureSettings::enabled($definition['key'], $definition['default']);
        }
    }

    public function save(): void
    {
        foreach ($this->definitions as $definition) {
            $key = $definition['key'];
            $enabled = (bool) ($this->toggles[$key] ?? false);

            FeatureSettings::set($key, $enabled, auth()->id(), $definition['description']);
        }

        $this->flashLevel = 'success';
        $this->flash = 'Settings saved.';
    }

    public function resetToDefaults(): void
    {
        foreach ($this->definitions as $definition) {
            FeatureSettings::clear($definition['key']);
            $this->toggles[$definition['key']] = $definition['default'];
        }

        $this->flashLevel = 'success';
        $this->flash = 'Settings reset to config defaults.';
    }

    /** @return array<int, array{key:string,label:string,description:string,default:bool}> */
    public function definitions(): array
    {
        return $this->definitions;
    }
}; ?>

<x-mo-workspace-styles />

<div class="py-8">
    <div class="wm-page max-w-4xl mx-auto space-y-6">
        <section class="wm-card wm-card--gear-tr">
            <h1 class="wm-title">Settings Admin</h1>
            <p class="wm-sub" style="margin-top:4px;">Central project toggles. Use these switches to turn features on or off without code changes.</p>

            <div class="wm-rows" style="flex-direction:row;flex-wrap:wrap;gap:8px;margin-top:16px;">
                <a href="{{ route('settings.admin') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.admin') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">General</a>
                <a href="{{ route('settings.recipes') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.recipes') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Recipes</a>
                <a href="{{ route('settings.product-mapping') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.product-mapping') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Product Mapping</a>
                <a href="{{ route('settings.operator-sync') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.operator-sync') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Operator Sync</a>
                <a href="{{ route('settings.documents') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.documents') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Documents</a>
                <a href="{{ route('reporting.admin') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('reporting.*') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Reporting</a>
                <a href="{{ route('notifications.setup') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('notifications.setup') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Notifications Setup</a>
                <a href="{{ route('audit.index') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('audit.index') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Audit</a>
                <a href="{{ route('audit.errors') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('audit.errors') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Error Log</a>
            </div>
        </section>

        @if ($flash)
            <div @class([
                'text-sm rounded-lg px-4 py-3 border',
                'bg-green-50 border-green-200 text-green-800' => $flashLevel === 'success',
                'bg-red-50 border-red-200 text-red-800' => $flashLevel === 'error',
            ])>{{ $flash }}</div>
        @endif

        <section class="wm-card wm-card--gear-bl">
            <h2 class="wm-title" style="font-size:1.05rem;">Feature Toggles</h2>

            <div class="wm-rows" style="margin-top:14px;">
                @foreach ($this->definitions() as $definition)
                    <div class="wm-row" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;--wm-strip:#c9a24a;">
                        <div>
                            <div class="wm-ref-text">{{ $definition['label'] }}</div>
                            <div class="wm-sub" style="margin-top:4px;">{{ $definition['description'] }}</div>
                            <div class="wm-sub">Key: {{ $definition['key'] }}</div>
                        </div>
                        <label class="inline-flex items-center cursor-pointer mt-1">
                            <input type="checkbox" wire:model="toggles.{{ $definition['key'] }}" class="rounded border-gray-300 text-indigo-600 shadow-sm" />
                        </label>
                    </div>
                @endforeach
            </div>

            <div class="flex items-center justify-end gap-2" style="margin-top:16px;">
                <x-secondary-button type="button" wire:click="resetToDefaults">Reset to defaults</x-secondary-button>
                <button type="button" wire:click="save" class="wm-btn-dark">Save settings</button>
            </div>
        </section>
    </div>
</div>
