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

<div class="py-8">
    <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <h2 class="text-xl font-semibold text-gray-800">Settings Admin</h2>
        <p class="text-sm text-gray-600">Central project toggles. Use these switches to turn features on or off without code changes.</p>

        <div class="rounded-lg border border-slate-200 bg-white p-2 shadow-sm">
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('settings.admin') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.admin') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">General</a>
                <a href="{{ route('settings.recipes') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.recipes') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Recipes</a>
                <a href="{{ route('settings.product-mapping') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.product-mapping') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Product Mapping</a>
                <a href="{{ route('settings.operator-sync') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.operator-sync') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Operator Sync</a>
            </div>
        </div>

        @if ($flash)
            <div @class([
                'text-sm rounded-lg px-4 py-3 border',
                'bg-green-50 border-green-200 text-green-800' => $flashLevel === 'success',
                'bg-red-50 border-red-200 text-red-800' => $flashLevel === 'error',
            ])>{{ $flash }}</div>
        @endif

        <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 font-medium text-gray-800">Feature Toggles</div>

            <div class="divide-y divide-gray-100">
                @foreach ($this->definitions() as $definition)
                    <div class="px-5 py-4 flex items-start justify-between gap-4">
                        <div>
                            <div class="font-medium text-gray-800">{{ $definition['label'] }}</div>
                            <div class="text-sm text-gray-600">{{ $definition['description'] }}</div>
                            <div class="text-xs text-gray-400 mt-1">Key: {{ $definition['key'] }}</div>
                        </div>
                        <label class="inline-flex items-center cursor-pointer mt-1">
                            <input type="checkbox" wire:model="toggles.{{ $definition['key'] }}" class="rounded border-gray-300 text-indigo-600 shadow-sm" />
                        </label>
                    </div>
                @endforeach
            </div>

            <div class="px-5 py-4 border-t border-gray-100 flex items-center justify-end gap-2">
                <x-secondary-button type="button" wire:click="resetToDefaults">Reset to defaults</x-secondary-button>
                <x-primary-button type="button" wire:click="save">Save settings</x-primary-button>
            </div>
        </div>
    </div>
</div>
