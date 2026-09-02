<?php

use App\Features\Drum\AddDrumPalletFeature;
use App\Features\Drum\AddDrumRecordFeature;
use App\Features\Drum\CreateDrumProcessingRunFeature;
use App\Models\BatchRecord;
use App\Models\DrumProcessingRun;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Drum Processing')] class extends Component {
    public BatchRecord $batch;
    public ?DrumProcessingRun $run = null;

    public string $operator = '';
    public bool $bbe_matches_winman = true;

    /** @var array<string, mixed> */
    public array $palletForm = ['pallecon_number' => '', 'pallet_ticket_number' => ''];

    public ?int $drumPalletId = null;
    /** @var array<string, mixed> */
    public array $drumForm = ['drum_number' => '', 'filler_weight' => '', 'bag_seal_number' => '', 'drum_seal_number' => '', 'liner_clean_undamaged' => true];

    public function mount(BatchRecord $batch): void
    {
        $this->batch = $batch;
        $this->operator = auth()->user()->name;
        $this->loadRun();
    }

    public function startRun(): void
    {
        app(CreateDrumProcessingRunFeature::class)($this->batch, [
            'operator' => $this->operator,
            'bbe_matches_winman' => $this->bbe_matches_winman,
        ], auth()->user());
        $this->loadRun();
    }

    public function addPallet(): void
    {
        $this->run || abort(400);
        $data = $this->validate([
            'palletForm.pallecon_number' => ['nullable', 'string', 'max:255'],
            'palletForm.pallet_ticket_number' => ['required', 'string', 'max:255'],
        ])['palletForm'];
        $data['start_time'] = now();

        app(AddDrumPalletFeature::class)($this->run, $data, auth()->user());
        $this->palletForm = ['pallecon_number' => '', 'pallet_ticket_number' => ''];
        $this->loadRun();
    }

    public function addDrum(): void
    {
        $this->run || abort(400);
        $data = $this->validate([
            'drumPalletId' => ['required', 'integer'],
            'drumForm.drum_number' => ['required', 'string', 'max:255'],
            'drumForm.filler_weight' => ['nullable', 'numeric', 'min:0'],
            'drumForm.bag_seal_number' => ['nullable', 'string', 'max:255'],
            'drumForm.drum_seal_number' => ['nullable', 'string', 'max:255'],
            'drumForm.liner_clean_undamaged' => ['boolean'],
        ]);

        $pallet = $this->run->pallets()->whereKey($this->drumPalletId)->firstOrFail();
        app(AddDrumRecordFeature::class)($pallet, $data['drumForm'], auth()->user());

        $this->drumForm = ['drum_number' => '', 'filler_weight' => '', 'bag_seal_number' => '', 'drum_seal_number' => '', 'liner_clean_undamaged' => true];
        $this->loadRun();
    }

    private function loadRun(): void
    {
        $this->run = $this->batch->drumProcessingRuns()
            ->with(['pallets.drumRecords', 'pallets.checkedBy'])
            ->latest('id')->first();
    }
}; ?>

<div class="py-8">
    <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Drum Processing</h2>
                <p class="text-sm text-gray-500">Batch {{ $batch->batch_number }} · Route B drum processing (WM046)</p>
            </div>
            <a href="{{ route('batches.show', $batch) }}" wire:navigate class="text-sm text-indigo-600 hover:underline">← Batch record</a>
        </div>

        @if (! $run)
            <div class="bg-white shadow-sm rounded-lg p-6 space-y-4">
                <div class="flex flex-wrap items-end gap-4">
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Operator</label>
                        <input wire:model="operator" class="border-gray-300 rounded-md shadow-sm text-sm" />
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="bbe_matches_winman" class="rounded border-gray-300"> BBE matches WinMan</label>
                    <x-primary-button wire:click="startRun">Start drum run</x-primary-button>
                </div>
            </div>
        @else
            <div class="bg-white shadow-sm rounded-lg p-4 grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                <div><label class="block text-xs text-gray-600 mb-1">Pallecon number</label><input wire:model="palletForm.pallecon_number" class="w-full border-gray-300 rounded-md shadow-sm text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Pallet ticket *</label><input wire:model="palletForm.pallet_ticket_number" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />@error('palletForm.pallet_ticket_number')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</div>
                <x-primary-button wire:click="addPallet">Add pallet</x-primary-button>
            </div>

            <div class="bg-white shadow-sm rounded-lg p-4 grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Pallet *</label>
                    <select wire:model="drumPalletId" class="w-full border-gray-300 rounded-md shadow-sm text-sm">
                        <option value="">— pallet —</option>
                        @foreach ($run->pallets as $p)
                            <option value="{{ $p->id }}">{{ $p->pallet_ticket_number }}</option>
                        @endforeach
                    </select>
                    @error('drumPalletId')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
                </div>
                <div><label class="block text-xs text-gray-600 mb-1">Drum # *</label><input wire:model="drumForm.drum_number" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />@error('drumForm.drum_number')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</div>
                <div><label class="block text-xs text-gray-600 mb-1">Fill weight</label><input type="number" step="0.001" wire:model="drumForm.filler_weight" class="w-full border-gray-300 rounded-md shadow-sm text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Bag seal</label><input wire:model="drumForm.bag_seal_number" class="w-full border-gray-300 rounded-md shadow-sm text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Drum seal</label><input wire:model="drumForm.drum_seal_number" class="w-full border-gray-300 rounded-md shadow-sm text-sm" /></div>
                <x-primary-button wire:click="addDrum">Add drum</x-primary-button>
            </div>

            <div class="space-y-4">
                @forelse ($run->pallets as $pallet)
                    <div class="bg-white shadow-sm rounded-lg p-4">
                        <div class="flex items-center justify-between mb-2">
                            <div class="font-semibold text-gray-800">Pallet {{ $pallet->pallet_ticket_number }}</div>
                            <div class="text-xs text-gray-400">Pallecon {{ $pallet->pallecon_number ?? '—' }} · {{ $pallet->drumRecords->count() }} drums</div>
                        </div>
                        <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="text-left text-xs text-gray-500 uppercase"><tr><th class="py-1">Drum</th><th class="py-1 text-right">Weight</th><th class="py-1">Bag seal</th><th class="py-1">Drum seal</th><th class="py-1">Liner</th></tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($pallet->drumRecords as $drum)
                                    <tr><td class="py-1">{{ $drum->drum_number }}</td><td class="py-1 text-right">{{ $drum->filler_weight }}</td><td class="py-1">{{ $drum->bag_seal_number }}</td><td class="py-1">{{ $drum->drum_seal_number }}</td><td class="py-1">{{ $drum->liner_clean_undamaged ? '✓' : '—' }}</td></tr>
                                @empty
                                    <tr><td colspan="5" class="py-3 text-center text-gray-400">No drums yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                        </div>
                    </div>
                @empty
                    <div class="bg-white rounded-lg p-8 text-center text-sm text-gray-500">No pallets recorded yet.</div>
                @endforelse
            </div>
        @endif
    </div>
</div>
