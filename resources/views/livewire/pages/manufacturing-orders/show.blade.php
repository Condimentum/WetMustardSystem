<?php

use App\Domains\Batch\Exceptions\BatchException;
use App\Domains\WinMan\Exceptions\WinManException;
use App\Domains\WinMan\Jobs\FetchManufacturingOrderJob;
use App\Features\Batches\StartBatchFromManufacturingOrderFeature;
use App\Models\BatchRecord;
use App\Models\ManufacturingOrder;
use App\Models\Product;
use App\Models\RecipeVariant;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Manufacturing Order')] class extends Component {
    public int $winmanMo;

    /** @var array<string,mixed>|null */
    public ?array $order = null;

    public ?string $error = null;

    public ?int $variantId = null;

    /** @var array<int, array{id:int,label:string,batch_size:float}> */
    public array $variantOptions = [];

    /** @var array<int, array<string,mixed>> */
    public array $existingBatches = [];

    public string $batchPlannedQuantity = '';

    public function mount(int $winmanMo): void
    {
        $this->winmanMo = $winmanMo;
        $this->loadOrder();
    }

    public function updatedVariantId($value): void
    {
        $variantId = (int) ($value ?? 0);
        if ($variantId <= 0) {
            return;
        }

        $selected = collect($this->variantOptions)->firstWhere('id', $variantId);
        if (! is_array($selected)) {
            return;
        }

        $batchSize = (float) ($selected['batch_size'] ?? 0);
        if ($batchSize > 0) {
            $this->batchPlannedQuantity = rtrim(rtrim((string) $batchSize, '0'), '.');
        }
    }

    public function addBatch(): void
    {
        $validated = $this->validate([
            'batchPlannedQuantity' => ['required', 'numeric', 'min:0.001'],
        ]);

        $plannedQuantity = (float) $validated['batchPlannedQuantity'];
        $outstanding = (float) ($this->order['quantity_outstanding'] ?? 0);
        if ($outstanding > 0 && $plannedQuantity > $outstanding + 0.0001) {
            $this->error = 'Batch quantity cannot exceed MO outstanding quantity.';

            return;
        }

        try {
            $batch = app(StartBatchFromManufacturingOrderFeature::class)(
                $this->winmanMo,
                $this->variantId,
                $plannedQuantity,
                auth()->user(),
            );
        } catch (WinManException|BatchException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->redirectRoute('batches.show', ['batch' => $batch->id, 'tab' => 'allocation'], navigate: true);
    }

    public function formatQty(float $value, int $precision = 3): string
    {
        $rounded = round($value, $precision);

        if (abs($rounded) < 0.000001) {
            return '0';
        }

        return rtrim(rtrim((string) $rounded, '0'), '.');
    }

    private function loadOrder(): void
    {
        $this->error = null;

        $data = app(FetchManufacturingOrderJob::class)($this->winmanMo);

        if ($data === null) {
            abort(404, 'Manufacturing order not found.');
        }

        if ((float) $data->quantityOutstanding <= 0) {
            abort(404, 'Manufacturing order is no longer outstanding.');
        }

        if ((int) ($data->classification ?? 0) !== 30) {
            abort(403, 'Batch production workflow is available for Intermediate manufacturing orders only (classification 30).');
        }

        $product = Product::query()
            ->where(function ($query) use ($data): void {
                $query->where('winman_product_id', $data->winmanProductId)
                    ->orWhere('finished_goods_code', $data->winmanProductId);
            })
            ->first();

        $recipeCode = $product?->recipe_code;

        $this->variantOptions = $recipeCode === null ? [] : RecipeVariant::query()
            ->where('recipe_code', $recipeCode)
            ->where('active_flag', true)
            ->orderBy('batch_size')
            ->get(['id', 'variant_name', 'batch_size'])
            ->map(fn (RecipeVariant $v): array => [
                'id' => $v->id,
                'label' => $v->variant_name.' ('.rtrim(rtrim((string) $v->batch_size, '0'), '.').' kg)',
                'batch_size' => (float) $v->batch_size,
            ])
            ->all();

        $this->order = array_merge($data->toArray(), [
            'dbmts_product_name' => $product?->product_name,
            'recipe_code' => $recipeCode,
        ]);

        $localOrder = ManufacturingOrder::query()
            ->where('winman_manufacturing_order', $this->winmanMo)
            ->first();

        $this->existingBatches = $localOrder === null
            ? []
            : BatchRecord::query()
                ->where('manufacturing_order_id', $localOrder->id)
                ->orderByDesc('id')
                ->get(['id', 'batch_number', 'planned_quantity', 'production_date', 'status'])
                ->map(fn (BatchRecord $batch): array => [
                    'id' => $batch->id,
                    'batch_number' => (string) $batch->batch_number,
                    'planned_quantity' => (float) ($batch->planned_quantity ?? 0),
                    'production_date' => $batch->production_date?->format('Y-m-d'),
                    'status' => (string) $batch->status,
                ])
                ->all();

        $defaultQty = (float) ($data->quantityOutstanding ?? 0);
        $this->batchPlannedQuantity = $defaultQty > 0
            ? rtrim(rtrim((string) $defaultQty, '0'), '.')
            : '';
    }
}; ?>

<div class="py-8">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-800">Manufacturing Order</h2>
            <a href="{{ route('manufacturing-orders.search') }}" wire:navigate class="text-sm text-indigo-600 hover:underline">← Back to MO list</a>
        </div>

        @if ($order)
            <div class="bg-white shadow-sm rounded-lg p-6 space-y-6" x-data="{ tab: 'batch' }">
                <div>
                    <div class="text-sm text-gray-500">MO Reference</div>
                    <div class="text-2xl font-semibold text-gray-800">{{ $order['winman_manufacturing_order_id'] }}</div>
                    <div class="mt-1 text-sm text-gray-600">
                        {{ $order['product_description'] ?? '—' }}
                        @if (! empty($order['dbmts_product_name']))
                            <span class="text-gray-400">·</span> {{ $order['dbmts_product_name'] }}
                        @endif
                    </div>
                </div>

                <dl class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div><dt class="text-gray-500">Classification</dt><dd class="font-medium text-gray-800">{{ $order['classification'] ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">UOM</dt><dd class="font-medium text-gray-800">{{ $order['unit_of_measure_description'] ?? ($order['unit_of_measure'] ?? '—') }}</dd></div>
                    <div><dt class="text-gray-500">Outstanding</dt><dd class="font-medium text-gray-800">{{ rtrim(rtrim((string) ($order['quantity_outstanding'] ?? 0), '0'), '.') }}</dd></div>
                    <div><dt class="text-gray-500">Due</dt><dd class="font-medium text-gray-800">{{ ! empty($order['due_date']) ? (string) \Illuminate\Support\Str::of((string) $order['due_date'])->before(' ') : '—' }}</dd></div>
                </dl>

                <div class="border-b border-gray-200">
                    <nav class="-mb-px flex flex-wrap gap-6 text-sm font-medium">
                        <button @click="tab = 'batch'"
                            :class="tab === 'batch' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                            class="py-3 border-b-2">Batch</button>
                    </nav>
                </div>

                <div x-show="tab === 'batch'" x-cloak class="space-y-4">
                    <div class="text-sm text-gray-600">Select an existing batch to continue, or add a new batch for this MO.</div>

                    @if ($error)
                        <div class="text-sm bg-red-50 border border-red-200 rounded px-3 py-2 text-red-700">{{ $error }}</div>
                    @endif

                    <div class="rounded-lg border border-gray-200 overflow-hidden">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="text-left text-xs text-gray-500 uppercase bg-gray-50">
                                <tr>
                                    <th class="px-3 py-2">Batch</th>
                                    <th class="px-3 py-2">Qty</th>
                                    <th class="px-3 py-2">Production Date</th>
                                    <th class="px-3 py-2">Status</th>
                                    <th class="px-3 py-2 text-right"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($existingBatches as $batch)
                                    <tr>
                                        <td class="px-3 py-2 font-medium text-gray-800">{{ $batch['batch_number'] }}</td>
                                        <td class="px-3 py-2">{{ $this->formatQty((float) $batch['planned_quantity']) }}</td>
                                        <td class="px-3 py-2">{{ $batch['production_date'] ?? '—' }}</td>
                                        <td class="px-3 py-2">{{ \Illuminate\Support\Str::headline((string) $batch['status']) }}</td>
                                        <td class="px-3 py-2 text-right">
                                            <a href="{{ route('batches.show', ['batch' => (int) $batch['id'], 'tab' => 'allocation']) }}" wire:navigate class="text-indigo-600 hover:underline">Continue</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-3 py-4 text-center text-gray-500">No batches created for this MO yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <details class="rounded-lg border border-gray-200 p-3" @if(count($existingBatches) === 0) open @endif>
                        <summary class="cursor-pointer text-sm font-medium text-gray-700">Add New Batch</summary>

                        <div class="mt-3 flex flex-wrap items-end gap-3">
                            @if (count($variantOptions) > 0)
                                <div>
                                    <label class="block text-xs text-gray-600 mb-1">Batch-size variant <span class="text-red-500">*</span></label>
                                    <select wire:model="variantId" class="border-gray-300 rounded-md shadow-sm text-sm">
                                        <option value="">— select variant —</option>
                                        @foreach ($variantOptions as $option)
                                            <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif

                            <div>
                                <label class="block text-xs text-gray-600 mb-1">Batch quantity (kg) <span class="text-red-500">*</span></label>
                                <input wire:model="batchPlannedQuantity" type="number" step="0.001" min="0.001" class="border-gray-300 rounded-md shadow-sm text-sm w-40" />
                                @error('batchPlannedQuantity') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                            </div>

                            <x-primary-button wire:click="addBatch" wire:loading.attr="disabled">
                                Add batch
                            </x-primary-button>
                        </div>
                    </details>
                </div>
            </div>
        @endif
    </div>
</div>
