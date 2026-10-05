<?php

use App\Features\Waste\RecordWasteFeature;
use App\Models\BatchRecord;
use App\Models\WasteRecord;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Waste / Scrap')] class extends Component {
    /** @var array<string, string> */
    public array $form = [
        'category' => 'spillage',
        'material_code' => '',
        'material_description' => '',
        'lot_number' => '',
        'quantity' => '',
        'uom' => 'kg',
        'reason' => '',
        'batch_number' => '',
    ];

    public ?string $flash = null;
    public bool $flashError = false;

    /** @return array<string, string> */
    #[Computed]
    public function categories(): array
    {
        return WasteRecord::CATEGORIES;
    }

    /** @return \Illuminate\Support\Collection<int, WasteRecord> */
    #[Computed]
    public function recentWaste()
    {
        return WasteRecord::query()
            ->with(['recordedBy:id,name', 'batchRecord:id,batch_number'])
            ->latest('recorded_at')
            ->limit(25)
            ->get();
    }

    public function record(): void
    {
        $data = $this->validate([
            'form.category' => ['required', 'in:'.implode(',', array_keys(WasteRecord::CATEGORIES))],
            'form.material_code' => ['nullable', 'string', 'max:255'],
            'form.material_description' => ['nullable', 'string', 'max:255'],
            'form.lot_number' => ['nullable', 'string', 'max:255'],
            'form.quantity' => ['required', 'numeric', 'min:0.001'],
            'form.uom' => ['required', 'string', 'max:20'],
            'form.reason' => ['required', 'string', 'max:1000'],
            'form.batch_number' => ['nullable', 'string', 'max:255'],
        ])['form'];

        $batchId = null;
        if (trim($data['batch_number']) !== '') {
            $batch = BatchRecord::where('batch_number', trim($data['batch_number']))->first();
            if ($batch === null) {
                $this->addError('form.batch_number', 'No batch found with that number.');

                return;
            }
            $batchId = $batch->id;
        }

        try {
            app(RecordWasteFeature::class)([
                'batch_record_id' => $batchId,
                'material_code' => $data['material_code'] ?: null,
                'material_description' => $data['material_description'] ?: null,
                'lot_number' => $data['lot_number'] ?: null,
                'quantity' => $data['quantity'],
                'uom' => $data['uom'],
                'category' => $data['category'],
                'reason' => $data['reason'],
            ], auth()->user());

            $this->flash = 'Waste recorded.';
            $this->flashError = false;
            $this->form = [
                'category' => 'spillage', 'material_code' => '', 'material_description' => '',
                'lot_number' => '', 'quantity' => '', 'uom' => 'kg', 'reason' => '', 'batch_number' => '',
            ];
            unset($this->recentWaste);
        } catch (\Throwable $e) {
            $this->flash = $e->getMessage();
            $this->flashError = true;
        }
    }
}; ?>

<div class="py-8">
    <x-mo-workspace-styles />
    <div class="wm-page max-w-5xl mx-auto space-y-6">

        <section class="wm-card wm-card--gear-tr">
            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                <span class="wml-medal">
                    <img src="{{ asset('images/dashboard/waste.png') }}" alt="" />
                </span>
                <div>
                    <h1 class="wm-title">Waste / Scrap</h1>
                    <div class="wm-sub">Log spilled, damaged, disposed or lost material with a reason.</div>
                </div>
            </div>
        </section>

        @if ($flash)
            <div class="rounded-md p-3 text-sm {{ $flashError ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-green-50 text-green-700 border border-green-200' }}">
                {{ $flash }}
            </div>
        @endif

        <section class="wm-card wm-card--gear-bl">
            <h2 class="wm-title" style="font-size:1.05rem;">Record waste</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3" style="margin-top:14px;">
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Category *</label>
                    <select wire:model="form.category" class="w-full rounded-lg border-slate-300 text-sm">
                        @foreach ($this->categories as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('form.category') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Quantity *</label>
                    <div class="flex gap-2">
                        <input type="number" step="0.001" min="0.001" wire:model="form.quantity" class="w-full rounded-lg border-slate-300 text-sm" placeholder="0.000" />
                        <input type="text" wire:model="form.uom" class="w-20 rounded-lg border-slate-300 text-sm" />
                    </div>
                    @error('form.quantity') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Batch number (optional)</label>
                    <input type="text" wire:model="form.batch_number" class="w-full rounded-lg border-slate-300 text-sm" placeholder="e.g. WM260909-01" />
                    @error('form.batch_number') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Material code (optional)</label>
                    <input type="text" wire:model="form.material_code" class="w-full rounded-lg border-slate-300 text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Material description (optional)</label>
                    <input type="text" wire:model="form.material_description" class="w-full rounded-lg border-slate-300 text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Lot number (optional)</label>
                    <input type="text" wire:model="form.lot_number" class="w-full rounded-lg border-slate-300 text-sm" />
                </div>
                <div class="md:col-span-3">
                    <label class="block text-xs font-medium text-slate-500 mb-1">Reason *</label>
                    <input type="text" wire:model="form.reason" class="w-full rounded-lg border-slate-300 text-sm" placeholder="What happened?" />
                    @error('form.reason') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="mt-4">
                <button wire:click="record" type="button" class="wm-btn-dark">Record waste</button>
            </div>
        </section>

        <section class="wm-card wm-card--gear-tr">
            <h2 class="wm-title" style="font-size:1.05rem;">Recent waste</h2>
            <div class="wm-table" style="margin-top:14px;">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-sm">
                    <thead>
                        <tr style="background:linear-gradient(180deg,#2b3238,#171c20);color:#fff;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;">
                            <th class="px-5 py-3">When</th>
                            <th class="px-5 py-3">Category</th>
                            <th class="px-5 py-3">Quantity</th>
                            <th class="px-5 py-3">Material</th>
                            <th class="px-5 py-3">Batch</th>
                            <th class="px-5 py-3">Reason</th>
                            <th class="px-5 py-3">By</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($this->recentWaste as $w)
                            <tr>
                                <td class="px-5 py-2 whitespace-nowrap">{{ $w->recorded_at?->format('d M H:i') }}</td>
                                <td class="px-5 py-2">{{ $w->categoryLabel() }}</td>
                                <td class="px-5 py-2 whitespace-nowrap">{{ number_format((float) $w->quantity, 3) }} {{ $w->uom }}</td>
                                <td class="px-5 py-2">{{ $w->material_code ?? '—' }}{{ $w->material_description ? ' · '.$w->material_description : '' }}</td>
                                <td class="px-5 py-2">{{ $w->batchRecord?->batch_number ?? '—' }}</td>
                                <td class="px-5 py-2">{{ $w->reason }}</td>
                                <td class="px-5 py-2 whitespace-nowrap">{{ $w->recordedBy?->name ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-5 py-6 text-center text-slate-400">No waste recorded yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            </div>
        </section>

    </div>
</div>
