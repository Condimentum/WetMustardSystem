<?php

use App\Domains\Batch\Exceptions\BatchException;
use App\Domains\WinMan\Exceptions\WinManException;
use App\Features\Batches\StartBatchFromManufacturingOrderFeature;
use App\Features\ManufacturingOrders\SearchManufacturingOrdersFeature;
use App\Models\Product;
use App\Models\RecipeVariant;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('MO Search')] class extends Component {
    public string $search = '';

    /** @var array<int, array<string, mixed>> */
    public array $orders = [];

    public ?int $selectedWinmanMo = null;

    public ?string $selectedLabel = null;

    /** @var array<int, array{id:int,label:string}> */
    public array $variantOptions = [];

    public ?int $variantId = null;

    public string $batchPlannedQuantity = '';

    public ?string $error = null;

    public function mount(): void
    {
        $this->loadOrders();
    }

    public function updatedSearch(): void
    {
        $this->cancel();
        $this->loadOrders();
    }

    public function prepare(int $winmanMo): void
    {
        $this->error = null;
        $this->variantId = null;
        $this->selectedWinmanMo = $winmanMo;

        $order = collect($this->orders)->firstWhere('winman_manufacturing_order', $winmanMo);
        $this->selectedLabel = $order
            ? $order['winman_manufacturing_order_id'].' — '.$order['product_description']
            : (string) $winmanMo;

        $recipeCode = $order['recipe_code'] ?? null;

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

        $defaultQty = (float) ($order['quantity_outstanding'] ?? 0);
        $this->batchPlannedQuantity = $defaultQty > 0
            ? rtrim(rtrim((string) $defaultQty, '0'), '.')
            : '';
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

    public function prepareAndStart(int $winmanMo): void
    {
        $this->prepare($winmanMo);
        $this->start();
    }

    public function cancel(): void
    {
        $this->selectedWinmanMo = null;
        $this->selectedLabel = null;
        $this->variantOptions = [];
        $this->variantId = null;
        $this->batchPlannedQuantity = '';
        $this->error = null;
    }

    public function start(): void
    {
        $this->error = null;

        if ($this->selectedWinmanMo === null) {
            return;
        }

        $validated = $this->validate([
            'batchPlannedQuantity' => ['required', 'numeric', 'min:0.001'],
        ]);

        $plannedQuantity = (float) $validated['batchPlannedQuantity'];

        $selectedOrder = collect($this->orders)->firstWhere('winman_manufacturing_order', $this->selectedWinmanMo);
        $outstanding = is_array($selectedOrder) ? (float) ($selectedOrder['quantity_outstanding'] ?? 0) : 0.0;
        if ($outstanding > 0 && $plannedQuantity > $outstanding + 0.0001) {
            $this->error = 'Batch quantity cannot exceed MO outstanding quantity.';

            return;
        }

        // Variant required but none selected yet.
        if (count($this->variantOptions) > 0 && $this->variantId === null) {
            $this->error = 'Please select a batch-size variant before confirming.';

            return;
        }

        try {
            $batch = app(StartBatchFromManufacturingOrderFeature::class)(
                $this->selectedWinmanMo,
                $this->variantId,
                $plannedQuantity,
                auth()->user(),
            );
        } catch (WinManException|BatchException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->redirectRoute('batches.show', $batch, navigate: true);
    }

    private function loadOrders(): void
    {
        $orders = app(SearchManufacturingOrdersFeature::class)(
            $this->search !== '' ? $this->search : null,
            50,
        );

        $codes = collect($orders)->map(fn ($o) => $o->winmanProductId)->filter()->unique()->all();

        $productsByCode = [];
        if ($codes !== []) {
            Product::query()
                ->where(function ($query) use ($codes): void {
                    $query->whereIn('winman_product_id', $codes)
                        ->orWhereIn('finished_goods_code', $codes);
                })
                ->get()
                ->each(function (Product $p) use (&$productsByCode): void {
                    foreach ([$p->winman_product_id, $p->finished_goods_code] as $code) {
                        if ($code !== null) {
                            $productsByCode[$code] = $p;
                        }
                    }
                });
        }

        $this->orders = collect($orders)->map(function ($o) use ($productsByCode): array {
            $product = $productsByCode[$o->winmanProductId] ?? null;
            $recipeCode = $product?->recipe_code;
            $hasVariants = $recipeCode !== null && \App\Models\RecipeVariant::query()
                ->where('recipe_code', $recipeCode)
                ->where('active_flag', true)
                ->exists();

            return array_merge($o->toArray(), [
                'recipe_code' => $recipeCode,
                'dbmts_product_name' => $product?->product_name,
                'has_variants' => $hasVariants,
            ]);
        })->all();
    }
}; ?>

<div class="py-8">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-800">Manufacturing Orders</h2>
            <div class="w-72">
                <x-text-input
                    wire:model.live.debounce.400ms="search"
                    type="text"
                    class="block w-full"
                    placeholder="Search MO ref, product…" />
            </div>
        </div>

        @if ($selectedWinmanMo)
            <div class="bg-indigo-700 text-white rounded-lg p-4 shadow-lg" x-data="{ moTab: 'batch' }">
                <div class="text-sm font-semibold uppercase tracking-wide text-indigo-200 mb-1">Manufacturing Order Workspace</div>
                <div class="text-lg font-bold">{{ $selectedLabel }}</div>

                @if ($error)
                    <div class="mt-2 text-sm bg-red-500/20 border border-red-300/40 rounded px-3 py-2 text-red-100">{{ $error }}</div>
                @endif

                <div class="mt-4 border-b border-indigo-500/60">
                    <nav class="-mb-px flex gap-6 text-sm font-medium">
                        <button type="button" @click="moTab = 'batch'"
                            :class="moTab === 'batch' ? 'border-white text-white' : 'border-transparent text-indigo-200 hover:text-white'"
                            class="py-2 border-b-2">Batch</button>
                    </nav>
                </div>

                <div class="mt-4 flex flex-wrap items-end gap-3" x-show="moTab === 'batch'">
                    @if (count($variantOptions) > 0)
                        <div>
                            <label class="block text-sm text-indigo-200 mb-1">Batch-size variant <span class="text-red-300">*</span></label>
                            <select wire:model="variantId" class="border-0 rounded-md text-gray-800 text-sm">
                                <option value="">— select variant —</option>
                                @foreach ($variantOptions as $option)
                                    <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div>
                        <label class="block text-sm text-indigo-200 mb-1">Batch quantity (kg) <span class="text-red-300">*</span></label>
                        <input wire:model="batchPlannedQuantity" type="number" step="0.001" min="0.001" class="border-0 rounded-md text-gray-800 text-sm w-40" />
                        @error('batchPlannedQuantity') <span class="block text-xs text-red-100 mt-1">{{ $message }}</span> @enderror
                    </div>

                    <button wire:click="start" wire:loading.attr="disabled"
                        class="inline-flex items-center px-5 py-2.5 bg-white text-indigo-700 font-semibold rounded-lg hover:bg-indigo-50 disabled:opacity-50">
                        <span wire:loading.remove wire:target="start">+ Add batch</span>
                        <span wire:loading wire:target="start">Creating…</span>
                    </button>
                    <button wire:click="cancel" type="button"
                        class="inline-flex items-center px-4 py-2.5 border border-white/30 rounded-lg text-sm text-white hover:bg-white/10">
                        Cancel
                    </button>
                </div>
            </div>
        @endif

        <div class="bg-white shadow-sm rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        <th class="px-4 py-3">MO Ref</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Product</th>
                        <th class="px-4 py-3">DBMTS Product</th>
                        <th class="px-4 py-3 text-right">Outstanding</th>
                        <th class="px-4 py-3">Due</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @php
                        $preferredClassifications = [
                            30 => 'Intermediate',
                            29 => 'Wet Packed',
                        ];
                        $uomLabels = [
                            2 => 'Pallecon',
                            44 => 'Buckets',
                        ];

                        $renderOrderRow = function (array $order): string {
                            $startUrl = route('manufacturing-orders.show', ['winmanMo' => (int) $order['winman_manufacturing_order']]);
                            $actionButton = '<a href="'.e($startUrl).'" wire:navigate class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">Start</a>';

                            $productDescription = e(\Illuminate\Support\Str::limit((string) $order['product_description'], 40));
                            $dbmtsName = e($order['dbmts_product_name'] ?? '—');
                            $moRef = e((string) $order['winman_manufacturing_order_id']);
                            $systemType = e((string) $order['system_type']);
                            $productId = e((string) $order['winman_product_id']);
                            $outstanding = e(rtrim(rtrim((string) $order['quantity_outstanding'], '0'), '.'));
                            $due = e($order['due_date'] ? (string) \Illuminate\Support\Str::of($order['due_date'])->before(' ') : '—');

                            return '<tr class="text-sm text-gray-700 hover:bg-gray-50">'
                                .'<td class="px-4 py-3 font-medium">'.$moRef.'</td>'
                                .'<td class="px-4 py-3">'.$systemType.'</td>'
                                .'<td class="px-4 py-3"><span class="text-gray-500">'.$productId.'</span> '.$productDescription.'</td>'
                                .'<td class="px-4 py-3">'.$dbmtsName.'</td>'
                                .'<td class="px-4 py-3 text-right">'.$outstanding.'</td>'
                                .'<td class="px-4 py-3">'.$due.'</td>'
                                .'<td class="px-4 py-3 text-right">'.$actionButton.'</td>'
                                .'</tr>';
                        };

                        $allOrders = collect($orders);
                        $hasAnyOrder = $allOrders->isNotEmpty();
                    @endphp

                    @if (! $hasAnyOrder)
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center text-sm text-gray-500">
                                No eligible outstanding MOs found.
                                <div class="mt-1 text-xs text-gray-400">
                                    The ProductMaster WinMan mapping may be empty (pending WM024).
                                </div>
                            </td>
                        </tr>
                    @else
                        @foreach ($preferredClassifications as $classification => $classificationLabel)
                            @php
                                $classificationOrders = $allOrders
                                    ->where('classification', $classification)
                                    ->values();
                            @endphp

                            @if ($classificationOrders->isNotEmpty())
                                <tr class="bg-indigo-50/60">
                                    <td colspan="7" class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-indigo-800">
                                        Classification {{ $classification }} - {{ $classificationLabel }}
                                    </td>
                                </tr>

                                @foreach ($classificationOrders->groupBy(fn (array $order): string => $order['unit_of_measure'] !== null ? (string) $order['unit_of_measure'] : 'unknown') as $uom => $uomOrders)
                                    @php
                                        $uomInt = is_numeric($uom) ? (int) $uom : null;
                                        $uomLabel = $uomInt === null
                                            ? 'Unknown'
                                            : ($classification === 29
                                                ? ($uomLabels[$uomInt] ?? ('Other ('.number_format($uomInt).')'))
                                                : ($uomLabels[$uomInt] ?? number_format($uomInt)));
                                    @endphp
                                    <tr class="bg-gray-50">
                                        <td colspan="7" class="px-4 py-2 text-xs font-medium uppercase tracking-wide text-gray-600">
                                            UnitOfMeasure: {{ $uomLabel }}
                                        </td>
                                    </tr>

                                    @foreach ($uomOrders as $order)
                                        {!! $renderOrderRow($order) !!}
                                    @endforeach
                                @endforeach
                            @endif
                        @endforeach

                        @php
                            $otherOrders = $allOrders
                                ->filter(fn (array $order): bool => ! in_array((int) ($order['classification'] ?? -1), array_keys($preferredClassifications), true))
                                ->values();
                        @endphp

                        @if ($otherOrders->isNotEmpty())
                            <tr class="bg-amber-50/60">
                                <td colspan="7" class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-amber-800">
                                    Other classifications
                                </td>
                            </tr>
                            @foreach ($otherOrders as $order)
                                {!! $renderOrderRow($order) !!}
                            @endforeach
                        @endif
                    @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
