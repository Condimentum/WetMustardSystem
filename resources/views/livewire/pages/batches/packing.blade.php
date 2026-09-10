<?php

use App\Features\Packing\ConsumePalleconFeature;
use App\Features\Packing\CreatePackingRunFeature;
use App\Features\Packing\RecordPackingHourlyCheckFeature;
use App\Features\Packing\RecordPackingWeightCheckFeature;
use App\Features\Pallet\AddPalletRecordFeature;
use App\Models\BatchRecord;
use App\Models\PackingRun;
use App\Models\Pallecon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Packing Run')] class extends Component {
    public BatchRecord $batch;
    public ?PackingRun $run = null;

    public ?int $ibc_pallecon_id = null;
    public string $ibc_time_on = '';

    /** @var array<string, bool> */
    public array $hourly = [
        'bucket_clean' => true, 'lid_clean' => true, 'lids_secure' => true, 'tamper_in_place' => true,
        'label_correct' => true, 'print_clear' => true, 'lot_code_correct' => true, 'filler_clean' => true, 'fill_clean' => true,
    ];

    /** @var array<string, mixed> */
    public array $weights = ['weight_1' => '', 'weight_2' => '', 'weight_3' => '', 'weight_4' => '', 'weight_5' => '', 'weight_6' => '', 'result' => 'pass'];

    /** @var array<string, mixed> */
    public array $pallet = ['pallet_number' => '', 'ticket_number' => '', 'pallet_amount' => '', 'bbe_pallet_label' => ''];

    public function mount(BatchRecord $batch): void
    {
        $this->batch = $batch;
        $this->loadRun();
    }

    /** Sealed containers available to consume (a container may hold several batches). */
    #[Computed]
    public function sealedPallecons()
    {
        return Pallecon::query()
            ->where('status', Pallecon::STATUS_SEALED)
            ->whereNull('held_at')
            ->with('fills.batchRecord:id,batch_number')
            ->orderByDesc('sealed_at')
            ->get();
    }

    public function startRun(): void
    {
        $this->run = app(CreatePackingRunFeature::class)($this->batch, null, auth()->user());
        $this->loadRun();
    }

    public function addIbc(): void
    {
        $this->run || abort(400);

        app(ConsumePalleconFeature::class)($this->run, [
            'pallecon_id' => $this->ibc_pallecon_id,
            'source_mo_number' => $this->batch->manufacturingOrder?->mo_number,
            'time_on' => now(),
        ], auth()->user());

        $this->ibc_pallecon_id = null;
        unset($this->sealedPallecons);
        $this->loadRun();
    }

    public function addHourly(): void
    {
        $this->run || abort(400);
        app(RecordPackingHourlyCheckFeature::class)($this->run, $this->hourly, auth()->user());
        $this->loadRun();
    }

    public function addWeight(): void
    {
        $this->run || abort(400);
        app(RecordPackingWeightCheckFeature::class)($this->run, $this->weights, auth()->user());
        $this->weights = ['weight_1' => '', 'weight_2' => '', 'weight_3' => '', 'weight_4' => '', 'weight_5' => '', 'weight_6' => '', 'result' => 'pass'];
        $this->loadRun();
    }

    public function addPallet(): void
    {
        $this->run || abort(400);
        $data = $this->validate([
            'pallet.pallet_number' => ['required', 'string', 'max:255'],
            'pallet.ticket_number' => ['nullable', 'string', 'max:255'],
            'pallet.pallet_amount' => ['nullable', 'integer', 'min:0'],
            'pallet.bbe_pallet_label' => ['nullable', 'string', 'max:255'],
        ])['pallet'];
        $data['packing_run_id'] = $this->run->id;

        app(AddPalletRecordFeature::class)($data, auth()->user());
        $this->pallet = ['pallet_number' => '', 'ticket_number' => '', 'pallet_amount' => '', 'bbe_pallet_label' => ''];
        $this->loadRun();
    }

    private function loadRun(): void
    {
        $this->run = $this->batch->packingRuns()
            ->with(['ibcs.palleconRecord', 'ibcs.pallecon', 'hourlyChecks.signedBy', 'weightChecks', 'pallets'])
            ->latest('id')->first();
    }
}; ?>

<div class="py-8">
    <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6" x-data="{ tab: 'ibc' }">

        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Bucket Packing</h2>
                <p class="text-sm text-gray-500">Batch {{ $batch->batch_number }} · Route A packing (WM016)</p>
            </div>
            <a href="{{ route('batches.show', $batch) }}" wire:navigate class="text-sm text-indigo-600 hover:underline">← Batch record</a>
        </div>

        @if (! $run)
            <div class="bg-white shadow-sm rounded-lg p-8 text-center space-y-4">
                <p class="text-gray-600">No packing run started for this batch.</p>
                <x-primary-button wire:click="startRun">Start packing run</x-primary-button>
            </div>
        @else
            <div class="bg-white shadow-sm rounded-lg">
                <div class="border-b border-gray-200 px-4">
                    <nav class="-mb-px flex flex-wrap gap-6 text-sm font-medium">
                        @foreach (['ibc' => 'IBC Consumption', 'hourly' => 'Hourly Checks', 'weight' => 'Weight Checks', 'pallets' => 'Pallets'] as $key => $label)
                            <button @click="tab = '{{ $key }}'"
                                :class="tab === '{{ $key }}' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                                class="py-3 border-b-2">{{ $label }}</button>
                        @endforeach
                    </nav>
                </div>

                <div class="p-6">
                    {{-- IBC --}}
                    <div x-show="tab === 'ibc'" class="space-y-4">
                        <div class="flex flex-wrap items-end gap-3 bg-gray-50 rounded-lg p-4">
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">Source pallecon (sealed)</label>
                                <select wire:model="ibc_pallecon_id" class="border-gray-300 rounded-md shadow-sm text-sm">
                                    <option value="">— select —</option>
                                    @foreach ($this->sealedPallecons as $p)
                                        <option value="{{ $p->id }}">{{ $p->serial_number ?? 'Pallecon #'.$p->id }} ({{ number_format((float) $p->final_weight, 1) }}kg · {{ $p->fills->count() }} batch(es))</option>
                                    @endforeach
                                </select>
                            </div>
                            <x-primary-button wire:click="addIbc">Consume pallecon</x-primary-button>
                        </div>
                        <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="text-left text-xs text-gray-500 uppercase"><tr><th class="py-2">Pallecon</th><th class="py-2">Source batch(es)</th><th class="py-2">Time on</th></tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($run->ibcs as $ibc)
                                    <tr><td class="py-2">{{ $ibc->pallecon?->serial_number ?? $ibc->palleconRecord?->serial_number ?? '—' }}</td><td class="py-2">{{ $ibc->source_batch_number }}</td><td class="py-2">{{ $ibc->time_on?->format('d M H:i') }}</td></tr>
                                @empty
                                    <tr><td colspan="3" class="py-6 text-center text-gray-500">No IBCs consumed.</td></tr>
                                @endforelse
                                    <tr><td colspan="3" class="py-6 text-center text-gray-500">No IBCs consumed.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                        </div>
                    </div>

                    {{-- Hourly --}}
                    <div x-show="tab === 'hourly'" x-cloak class="space-y-4">
                        <div class="bg-gray-50 rounded-lg p-4 space-y-3">
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-2 text-sm text-gray-700">
                                @foreach (['bucket_clean' => 'Bucket clean', 'lid_clean' => 'Lid clean', 'lids_secure' => 'Lids secure', 'tamper_in_place' => 'Tamper in place', 'label_correct' => 'Label correct', 'print_clear' => 'Print clear', 'lot_code_correct' => 'Lot code correct', 'filler_clean' => 'Filler clean', 'fill_clean' => 'Fill clean'] as $field => $label)
                                    <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="hourly.{{ $field }}" class="rounded border-gray-300"> {{ $label }}</label>
                                @endforeach
                            </div>
                            <x-primary-button wire:click="addHourly">Record hourly check</x-primary-button>
                        </div>
                        <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="text-left text-xs text-gray-500 uppercase"><tr><th class="py-2">Time</th><th class="py-2">Signed by</th></tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($run->hourlyChecks->sortByDesc('check_time') as $check)
                                    <tr><td class="py-2">{{ $check->check_time?->format('d M H:i') }}</td><td class="py-2">{{ $check->signedBy?->name }}</td></tr>
                                @empty
                                    <tr><td colspan="2" class="py-6 text-center text-gray-500">No hourly checks recorded.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                        </div>
                    </div>

                    {{-- Weight --}}
                    <div x-show="tab === 'weight'" x-cloak class="space-y-4">
                        <div class="bg-gray-50 rounded-lg p-4 space-y-3">
                            <div class="grid grid-cols-3 md:grid-cols-6 gap-2">
                                @for ($i = 1; $i <= 6; $i++)
                                    <input type="number" step="0.001" wire:model="weights.weight_{{ $i }}" placeholder="W{{ $i }}" class="border-gray-300 rounded-md shadow-sm text-sm" />
                                @endfor
                            </div>
                            <div class="flex items-end gap-3">
                                <div>
                                    <label class="block text-xs text-gray-600 mb-1">Result</label>
                                    <select wire:model="weights.result" class="border-gray-300 rounded-md shadow-sm text-sm"><option value="pass">Pass</option><option value="fail">Fail</option></select>
                                </div>
                                <x-primary-button wire:click="addWeight">Record weight check</x-primary-button>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="text-left text-xs text-gray-500 uppercase"><tr><th class="py-2">Time</th><th class="py-2 text-right">Average</th><th class="py-2">Result</th></tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($run->weightChecks->sortByDesc('check_time') as $wc)
                                    <tr>
                                        <td class="py-2">{{ $wc->check_time?->format('d M H:i') }}</td>
                                        <td class="py-2 text-right">{{ $wc->average_weight }}</td>
                                        <td class="py-2"><span @class(['px-2 py-0.5 rounded-full text-xs', 'bg-green-100 text-green-800' => $wc->result === 'pass', 'bg-red-100 text-red-800' => $wc->result === 'fail'])>{{ ucfirst($wc->result) }}</span></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="py-6 text-center text-gray-500">No weight checks recorded.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                        </div>
                    </div>

                    {{-- Pallets --}}
                    <div x-show="tab === 'pallets'" x-cloak class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end bg-gray-50 rounded-lg p-4">
                            <div><label class="block text-xs text-gray-600 mb-1">Pallet number *</label><input wire:model="pallet.pallet_number" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />@error('pallet.pallet_number')<span class="text-xs text-red-600">{{ $message }}</span>@enderror</div>
                            <div><label class="block text-xs text-gray-600 mb-1">Ticket</label><input wire:model="pallet.ticket_number" class="w-full border-gray-300 rounded-md shadow-sm text-sm" /></div>
                            <div><label class="block text-xs text-gray-600 mb-1">Amount</label><input type="number" wire:model="pallet.pallet_amount" class="w-full border-gray-300 rounded-md shadow-sm text-sm" /></div>
                            <div><label class="block text-xs text-gray-600 mb-1">BBE label</label><input wire:model="pallet.bbe_pallet_label" class="w-full border-gray-300 rounded-md shadow-sm text-sm" /></div>
                            <x-primary-button wire:click="addPallet">Add pallet</x-primary-button>
                        </div>
                        <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="text-left text-xs text-gray-500 uppercase"><tr><th class="py-2">Pallet</th><th class="py-2">Ticket</th><th class="py-2 text-right">Amount</th><th class="py-2">BBE</th></tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($run->pallets as $pl)
                                    <tr><td class="py-2">{{ $pl->pallet_number }}</td><td class="py-2">{{ $pl->ticket_number }}</td><td class="py-2 text-right">{{ $pl->pallet_amount }}</td><td class="py-2">{{ $pl->bbe_pallet_label }}</td></tr>
                                @empty
                                    <tr><td colspan="4" class="py-6 text-center text-gray-500">No pallets recorded.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
