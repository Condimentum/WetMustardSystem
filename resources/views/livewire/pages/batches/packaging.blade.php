<?php

use App\Features\Packaging\AddPackagingLotFeature;
use App\Models\BatchRecord;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Packaging Lots')] class extends Component {
    public BatchRecord $batch;

    /** @var array<string, mixed> */
    public array $form = [
        'packaging_type' => 'Bucket',
        'supplier' => '',
        'supplier_reference_type' => 'lot_job',
        'supplier_reference_number' => '',
        'lot_or_job_number' => '',
        'machine_number' => '',
        'operator_name' => '',
        'supplier_production_date' => '',
    ];

    public function mount(BatchRecord $batch): void
    {
        $this->batch = $batch->load('packagingLots');
        $this->form['operator_name'] = auth()->user()->name;
    }

    public function addLot(): void
    {
        $data = $this->validate([
            'form.packaging_type' => ['required', 'string', 'max:100'],
            'form.supplier' => ['nullable', 'string', 'max:255'],
            'form.supplier_reference_type' => ['required', 'in:lot_job,nve'],
            'form.supplier_reference_number' => ['nullable', 'string', 'max:255'],
            'form.lot_or_job_number' => ['nullable', 'string', 'max:255'],
            'form.machine_number' => ['nullable', 'string', 'max:255'],
            'form.operator_name' => ['nullable', 'string', 'max:255'],
            'form.supplier_production_date' => ['nullable', 'date'],
        ])['form'];

        $data['batch_record_id'] = $this->batch->id;
        $data['linked_mo'] = $this->batch->manufacturingOrder?->mo_number;
        $data['time_on'] = now();
        $data['supplier_production_date'] = $data['supplier_production_date'] ?: null;

        app(AddPackagingLotFeature::class)($data, auth()->user());

        $this->form['supplier_reference_number'] = '';
        $this->form['lot_or_job_number'] = '';
        $this->batch = $this->batch->fresh('packagingLots');
    }
}; ?>

<div class="py-8">
    <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Packaging Traceability</h2>
                <p class="text-sm text-gray-500">Batch {{ $batch->batch_number }} · supplier lots (WM014/015/047)</p>
            </div>
            <a href="{{ route('batches.show', $batch) }}" wire:navigate class="text-sm text-indigo-600 hover:underline">← Batch record</a>
        </div>

        <form wire:submit="addLot" class="bg-white shadow-sm rounded-lg p-6 grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-xs text-gray-600 mb-1">Packaging type</label>
                <select wire:model="form.packaging_type" class="w-full border-gray-300 rounded-md shadow-sm text-sm">
                    <option>Bucket</option><option>Lid</option><option>Drum</option><option>Liner</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Supplier</label>
                <input wire:model="form.supplier" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Reference type</label>
                <select wire:model.live="form.supplier_reference_type" class="w-full border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="lot_job">Lot / Job</option>
                    <option value="nve">NVE number</option>
                </select>
            </div>
            @if ($form['supplier_reference_type'] === 'nve')
                <div>
                    <label class="block text-xs text-gray-600 mb-1">NVE number</label>
                    <input wire:model="form.supplier_reference_number" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
                </div>
            @else
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Lot / Job number</label>
                    <input wire:model="form.lot_or_job_number" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
                </div>
            @endif
            <div>
                <label class="block text-xs text-gray-600 mb-1">Machine number</label>
                <input wire:model="form.machine_number" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Supplier production date</label>
                <input type="date" wire:model="form.supplier_production_date" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Operator</label>
                <input wire:model="form.operator_name" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
            </div>
            <div class="flex items-end">
                <x-primary-button type="submit" class="w-full justify-center">Add packaging lot</x-primary-button>
            </div>
        </form>

        <div class="bg-white shadow-sm rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500 uppercase">
                    <tr><th class="px-4 py-3">Type</th><th class="px-4 py-3">Supplier</th><th class="px-4 py-3">Reference</th><th class="px-4 py-3">Machine</th><th class="px-4 py-3">Operator</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($batch->packagingLots as $lot)
                        <tr>
                            <td class="px-4 py-2">{{ $lot->packaging_type }}</td>
                            <td class="px-4 py-2">{{ $lot->supplier ?? '—' }}</td>
                            <td class="px-4 py-2">
                                @if ($lot->supplier_reference_type === 'nve')
                                    <span class="text-xs text-gray-400">NVE</span> {{ $lot->supplier_reference_number }}
                                @else
                                    <span class="text-xs text-gray-400">Lot/Job</span> {{ $lot->lot_or_job_number ?? $lot->supplier_reference_number }}
                                @endif
                            </td>
                            <td class="px-4 py-2">{{ $lot->machine_number ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $lot->operator_name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">No packaging lots recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>
