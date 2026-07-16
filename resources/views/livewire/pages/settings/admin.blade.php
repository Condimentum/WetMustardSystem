<?php

use App\Support\FeatureSettings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Settings Admin')] class extends Component {
    /** @var array<string, bool> */
    public array $toggles = [];

    public ?string $flash = null;

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

        $this->flash = 'Settings saved.';
    }

    public function resetToDefaults(): void
    {
        foreach ($this->definitions as $definition) {
            FeatureSettings::clear($definition['key']);
            $this->toggles[$definition['key']] = $definition['default'];
        }

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

        @if ($flash)
            <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-lg px-4 py-3">{{ $flash }}</div>
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
