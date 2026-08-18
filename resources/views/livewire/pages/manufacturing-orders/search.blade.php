<?php

use App\Domains\Batch\Exceptions\BatchException;
use App\Domains\WinMan\Exceptions\WinManException;
use App\Features\Batches\StartBatchFromManufacturingOrderFeature;
use App\Features\ManufacturingOrders\SearchManufacturingOrdersFeature;
use App\Models\BatchRecord;
use App\Models\ManufacturingOrder;
use App\Models\ProductMapping;
use App\Models\Product;
use App\Models\RecipeCard;
use App\Models\RecipeVariant;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('MO Search')] class extends Component {
    public string $search = '';

    public string $workspaceTab = 'start';

    /** @var array<int, array<string, mixed>> */
    public array $orders = [];

    public ?int $selectedWinmanMo = null;

    public ?string $selectedLabel = null;

    /** @var array<int, array<string,mixed>> */
    public array $selectedExistingBatches = [];

    /** @var array<int, array{id:int,label:string}> */
    public array $variantOptions = [];

    public ?int $variantId = null;

    /** @var array<int, float> */
    public array $recipeBatchSizeOptions = [];

    public string $selectedRecipeBatchSizeKg = '';

    public string $batchPlannedQuantity = '';

    public ?string $error = null;

    public function mount(): void
    {
        $this->loadOrders();

        $requestedTab = strtolower((string) request()->query('tab', 'start'));
        $this->workspaceTab = in_array($requestedTab, ['start', 'batch'], true)
            ? $requestedTab
            : 'start';

        $openMo = (int) request()->query('openMo', 0);
        if ($openMo > 0) {
            $this->redirectRoute('manufacturing-orders.workspace', ['winmanMo' => $openMo], navigate: true);

            return;
        }
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
                'label' => $v->variant_name.' ('.$this->formatQuantity((float) $v->batch_size).' kg)',
                'batch_size' => (float) $v->batch_size,
            ])
            ->all();

        $this->recipeBatchSizeOptions = $this->resolveRecipeBatchSizeOptionsFromOrder($order);
        if (count($this->recipeBatchSizeOptions) === 1) {
            $this->selectedRecipeBatchSizeKg = (string) $this->recipeBatchSizeOptions[0];
        } else {
            $this->selectedRecipeBatchSizeKg = '';
        }

        $this->batchPlannedQuantity = $this->selectedRecipeBatchSizeKg !== ''
            ? $this->formatQuantity((float) $this->selectedRecipeBatchSizeKg)
            : '';

        $this->selectedExistingBatches = $this->loadExistingBatchesForMo($winmanMo);
    }

    public function updatedVariantId($value): void
    {
        // Batch quantity is recipe-card driven, so variant selection no longer
        // overwrites planned quantity in the UI.
    }

    public function updatedSelectedRecipeBatchSizeKg($value): void
    {
        $this->batchPlannedQuantity = trim((string) $value) !== ''
            ? $this->formatQuantity((float) $value)
            : '';
    }

    private function formatQuantity(float $value): string
    {
        $formatted = number_format($value, 3, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    public function prepareAndStart(int $winmanMo): void
    {
        $this->prepare($winmanMo);
        $this->start();
    }

    public function openBatchWorkspace(int $winmanMo): void
    {
        $this->error = null;

        $existingBatchId = $this->resolveExistingBatchForMo($winmanMo);
        if ($existingBatchId !== null) {
            $this->redirectRoute('batches.show', ['batch' => $existingBatchId, 'tab' => 'allocation'], navigate: true);

            return;
        }

        $this->prepare($winmanMo);
        $this->workspaceTab = 'batch';
    }

    public function selectMo(int $winmanMo): void
    {
        $this->prepare($winmanMo);
        $this->workspaceTab = 'start';
    }

    public function openSelectedBatchWorkspace(): void
    {
        if ($this->selectedWinmanMo === null) {
            return;
        }

        $this->openBatchWorkspace($this->selectedWinmanMo);
    }

    public function openStartWorkspace(): void
    {
        $this->cancel();
    }

    public function cancel(): void
    {
        $this->selectedWinmanMo = null;
        $this->selectedLabel = null;
        $this->selectedExistingBatches = [];
        $this->variantOptions = [];
        $this->variantId = null;
        $this->recipeBatchSizeOptions = [];
        $this->selectedRecipeBatchSizeKg = '';
        $this->batchPlannedQuantity = '';
        $this->error = null;
        $this->workspaceTab = 'start';
    }

    public function start(): void
    {
        $this->error = null;

        if ($this->selectedWinmanMo === null) {
            return;
        }

        try {
            $batch = app(StartBatchFromManufacturingOrderFeature::class)(
                $this->selectedWinmanMo,
                $this->variantId,
                $this->selectedRecipeBatchSizeKg !== '' ? (float) $this->selectedRecipeBatchSizeKg : null,
                auth()->user(),
            );
        } catch (WinManException|BatchException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->redirectRoute('batches.show', ['batch' => $batch->id, 'tab' => 'allocation'], navigate: true);
    }

    /** @return array<int, float> */
    private function resolveRecipeBatchSizeOptionsFromOrder(?array $order): array
    {
        if (! is_array($order)) {
            return [];
        }

        $recipeCode = $this->resolveRecipeCodeFromOrder($order);
        if ($recipeCode === null) {
            return [];
        }

        return $this->resolveRecipeBatchSizeOptionsByRecipeCode($recipeCode);
    }

    /** @return array<int, float> */
    private function resolveRecipeBatchSizeOptionsByRecipeCode(string $recipeCode): array
    {
        $card = RecipeCard::query()
            ->where('recipe_code', $recipeCode)
            ->first(['batch_size_kg', 'batch_sizes_kg']);

        if ($card === null) {
            return [];
        }

        $sizes = [];

        if (is_array($card->batch_sizes_kg)) {
            foreach ($card->batch_sizes_kg as $size) {
                if (! is_numeric($size)) {
                    continue;
                }

                $numeric = round((float) $size, 3);
                if ($numeric > 0) {
                    $sizes[] = $numeric;
                }
            }
        }

        if ($card->batch_size_kg !== null) {
            $legacy = round((float) $card->batch_size_kg, 3);
            if ($legacy > 0) {
                $sizes[] = $legacy;
            }
        }

        $sizes = array_values(array_unique($sizes, SORT_NUMERIC));
        sort($sizes, SORT_NUMERIC);

        return $sizes;
    }

    private function resolveRecipeCodeFromOrder(array $order): ?string
    {
        $recipeCode = trim((string) ($order['recipe_code'] ?? ''));
        if ($recipeCode !== '') {
            return $recipeCode;
        }

        $structureProductId = trim((string) ($order['winman_product_id'] ?? ''));
        if ($structureProductId === '') {
            return null;
        }

        $directRecipe = ProductMapping::query()
            ->where('structure_product_id', $structureProductId)
            ->where(function ($query): void {
                $query->where('component_product_id', 'like', '3001%')
                    ->orWhere('component_product_id', 'like', '9900%');
            })
            ->orderBy('structure_level')
            ->value('component_product_id');
        if (is_string($directRecipe) && trim($directRecipe) !== '') {
            return trim($directRecipe);
        }

        $intermediate = ProductMapping::query()
            ->where('structure_product_id', $structureProductId)
            ->where('component_product_id', 'like', '5001%')
            ->orderBy('structure_level')
            ->value('component_product_id');
        if (! is_string($intermediate) || trim($intermediate) === '') {
            return null;
        }

        $nestedRecipe = ProductMapping::query()
            ->where('structure_product_id', trim($intermediate))
            ->where(function ($query): void {
                $query->where('component_product_id', 'like', '3001%')
                    ->orWhere('component_product_id', 'like', '9900%');
            })
            ->orderBy('structure_level')
            ->value('component_product_id');

        return is_string($nestedRecipe) && trim($nestedRecipe) !== ''
            ? trim($nestedRecipe)
            : null;
    }

    private function resolveExistingBatchForMo(int $winmanMo): ?int
    {
        $localOrder = ManufacturingOrder::query()
            ->where('winman_manufacturing_order', $winmanMo)
            ->first();

        if (! $localOrder) {
            return null;
        }

        $preferredStatuses = [
            BatchRecord::STATUS_IN_PROGRESS,
            BatchRecord::STATUS_QA_REVIEW,
            BatchRecord::STATUS_COMPLETED,
            BatchRecord::STATUS_CLOSED,
        ];

        foreach ($preferredStatuses as $status) {
            $match = BatchRecord::query()
                ->where('manufacturing_order_id', $localOrder->id)
                ->where('status', $status)
                ->orderByDesc('id')
                ->first(['id']);

            if ($match) {
                return (int) $match->id;
            }
        }

        return null;
    }

    private function resolveDefaultVariantId(array $order): ?int
    {
        $recipeCode = $order['recipe_code'] ?? null;
        if ($recipeCode === null) {
            return null;
        }

        $variant = RecipeVariant::query()
            ->where('recipe_code', $recipeCode)
            ->where('active_flag', true)
            ->orderBy('batch_size')
            ->first(['id']);

        return $variant ? (int) $variant->id : null;
    }

    /** @return array<int, array<string,mixed>> */
    private function loadExistingBatchesForMo(int $winmanMo): array
    {
        $localOrder = ManufacturingOrder::query()
            ->where('winman_manufacturing_order', $winmanMo)
            ->first();

        if (! $localOrder) {
            return [];
        }

        return BatchRecord::query()
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
            $hasVariants = $recipeCode !== null && RecipeVariant::query()
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

        @if ($workspaceTab === 'batch' && $selectedWinmanMo)
            @php
                $selectedOrder = collect($orders)->firstWhere('winman_manufacturing_order', $selectedWinmanMo);
            @endphp
            <div class="bg-white shadow-sm rounded-lg p-6 space-y-6">
                <div>
                    <div class="text-sm text-gray-500">MO Reference</div>
                    <div class="text-2xl font-semibold text-gray-800">{{ $selectedOrder['winman_manufacturing_order_id'] ?? $selectedWinmanMo }}</div>
                    <div class="mt-1 text-sm text-gray-600">
                        {{ $selectedOrder['product_description'] ?? '—' }}
                        @if (! empty($selectedOrder['dbmts_product_name']))
                            <span class="text-gray-400">·</span> {{ $selectedOrder['dbmts_product_name'] }}
                        @endif
                    </div>
                </div>

                <dl class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div><dt class="text-gray-500">Classification</dt><dd class="font-medium text-gray-800">{{ $selectedOrder['classification'] ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">UOM</dt><dd class="font-medium text-gray-800">{{ $selectedOrder['unit_of_measure_description'] ?? ($selectedOrder['unit_of_measure'] ?? '—') }}</dd></div>
                    <div><dt class="text-gray-500">Outstanding</dt><dd class="font-medium text-gray-800">{{ $this->formatQuantity((float) ($selectedOrder['quantity_outstanding'] ?? 0)) }}</dd></div>
                    <div><dt class="text-gray-500">Due</dt><dd class="font-medium text-gray-800">{{ ! empty($selectedOrder['due_date']) ? (string) \Illuminate\Support\Str::of((string) $selectedOrder['due_date'])->before(' ') : '—' }}</dd></div>
                </dl>

                <div class="border-b border-gray-200">
                    <nav class="-mb-px flex flex-wrap gap-6 text-sm font-medium">
                        <span class="py-3 border-b-2 border-indigo-500 text-indigo-600">Batch</span>
                    </nav>
                </div>

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
                            @forelse ($selectedExistingBatches as $batch)
                                <tr>
                                    <td class="px-3 py-2 font-medium text-gray-800">{{ $batch['batch_number'] }}</td>
                                    <td class="px-3 py-2">{{ $this->formatQuantity((float) $batch['planned_quantity']) }}</td>
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

                <div class="flex flex-wrap items-end gap-3">
                    @if (count($variantOptions) > 0)
                        <div>
                            <label class="block text-xs text-gray-600 mb-1">Batch-size variant (optional)</label>
                            <select wire:model="variantId" class="border-gray-300 rounded-md shadow-sm text-sm">
                                <option value="">— select variant —</option>
                                @foreach ($variantOptions as $option)
                                    <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                        @if (count($recipeBatchSizeOptions) > 1)
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">Recipe batch size (kg)</label>
                                <select wire:model="selectedRecipeBatchSizeKg" class="border-gray-300 rounded-md shadow-sm text-sm w-48">
                                    <option value="">- select batch size -</option>
                                    @foreach ($recipeBatchSizeOptions as $size)
                                        <option value="{{ $size }}">{{ $this->formatQuantity((float) $size) }} kg</option>
                                    @endforeach
                                </select>
                                <span class="block text-xs text-gray-500 mt-1">Multiple sizes are configured for this recipe.</span>
                            </div>
                        @else
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">Batch quantity (kg)</label>
                                <input wire:model="batchPlannedQuantity" type="text" readonly class="border-gray-300 bg-gray-100 rounded-md shadow-sm text-sm w-40" />
                                <span class="block text-xs text-gray-500 mt-1">Auto from recipe card batch size.</span>
                            </div>
                        @endif

                    <x-primary-button wire:click="start" wire:loading.attr="disabled">
                        Add batch
                    </x-primary-button>

                    <button wire:click="cancel" type="button" class="inline-flex items-center px-4 py-2.5 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
                        Back
                    </button>
                </div>
            </div>
        @endif

        @if ($workspaceTab === 'start')
        @if ($selectedWinmanMo)
            @php
                $startSelectedOrder = collect($orders)->firstWhere('winman_manufacturing_order', $selectedWinmanMo);
            @endphp
            <div class="bg-white shadow-sm rounded-lg p-5 border border-indigo-100">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-indigo-600">Start Workspace</div>
                        <div class="text-lg font-semibold text-gray-800 mt-1">
                            {{ $startSelectedOrder['winman_manufacturing_order_id'] ?? $selectedWinmanMo }}
                        </div>
                        <div class="text-sm text-gray-600 mt-1">
                            {{ $startSelectedOrder['product_description'] ?? 'Selected manufacturing order' }}
                        </div>
                    </div>
                    <button
                        type="button"
                        wire:click="openSelectedBatchWorkspace"
                        class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                        Open Batch
                    </button>
                </div>
            </div>
        @endif
        <div style="background:#fff;border:1px solid #dbe1ea;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,0.08);">
            @php
                $preferredClassifications = [
                    30 => 'Intermediate',
                    29 => 'Wet Packed',
                ];
                $uomLabels = [
                    2 => 'Pallecon',
                    44 => 'Buckets',
                ];

                $allOrders = collect($orders);
                $hasAnyOrder = $allOrders->isNotEmpty();
                $intermediateCount = $allOrders->where('classification', 30)->count();
                $wetPackedCount = $allOrders->where('classification', 29)->count();
                $otherCount = max($allOrders->count() - $intermediateCount - $wetPackedCount, 0);

                $renderOrderRow = function (array $order): string {
                    $winmanMo = (int) $order['winman_manufacturing_order'];
                    $systemTypeRaw = strtoupper(trim((string) ($order['system_type'] ?? '')));
                    $systemTypeStyles = match ($systemTypeRaw) {
                        'F' => ['bg' => '#eff6ff', 'border' => '#bfdbfe', 'color' => '#1d4ed8', 'label' => 'Firm'],
                        'R' => ['bg' => '#fffbeb', 'border' => '#fcd34d', 'color' => '#b45309', 'label' => 'Released'],
                        'I' => ['bg' => '#ecfdf5', 'border' => '#86efac', 'color' => '#15803d', 'label' => 'Issued'],
                        default => ['bg' => '#f1f5f9', 'border' => '#cbd5e1', 'color' => '#334155', 'label' => $systemTypeRaw !== '' ? $systemTypeRaw : 'Unknown'],
                    };

                    $actionButton = '<a href="'.e(route('manufacturing-orders.workspace', ['winmanMo' => $winmanMo])).'" wire:navigate style="display:inline-flex;align-items:center;padding:9px 14px;border-radius:10px;background:linear-gradient(180deg,#4f46e5 0%,#4338ca 100%);border:1px solid #4338ca;color:#fff;font-size:12px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;text-decoration:none;box-shadow:0 3px 10px rgba(67,56,202,.22);">Start</a>';

                    $productDescription = e(\Illuminate\Support\Str::limit((string) $order['product_description'], 52));
                    $dbmtsName = e($order['dbmts_product_name'] ?? '—');
                    $moRef = e((string) $order['winman_manufacturing_order_id']);
                    $productId = e((string) $order['winman_product_id']);
                    $formattedOutstanding = number_format((float) $order['quantity_outstanding'], 3, '.', '');
                    $outstanding = e(rtrim(rtrim($formattedOutstanding, '0'), '.'));
                    $due = e($order['due_date'] ? (string) \Illuminate\Support\Str::of($order['due_date'])->before(' ') : '—');

                    return '<tr style="color:#334155;background:#fff;">'
                        .'<td class="px-4 py-4 font-semibold" style="color:#1e293b;">'.$moRef.'</td>'
                        .'<td class="px-4 py-4">'
                            .'<span style="display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;border:1px solid '.$systemTypeStyles['border'].';background:'.$systemTypeStyles['bg'].';color:'.$systemTypeStyles['color'].';font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;">'.$systemTypeStyles['label'].'</span>'
                        .'</td>'
                        .'<td class="px-4 py-4" style="line-height:1.35;">'
                            .'<div style="color:#1e293b;font-weight:700;">'.$productDescription.'</div>'
                            .'<div style="color:#64748b;font-size:12px;margin-top:2px;">'.$productId.'</div>'
                        .'</td>'
                        .'<td class="px-4 py-4" style="color:#334155;">'.$dbmtsName.'</td>'
                        .'<td class="px-4 py-4 text-right" style="color:#0f766e;font-weight:800;">'.$outstanding.'</td>'
                        .'<td class="px-4 py-4" style="font-weight:700;color:#1e293b;">'.$due.'</td>'
                        .'<td class="px-4 py-4 text-right">'.$actionButton.'</td>'
                        .'</tr>';
                };
            @endphp

            <div style="padding:24px 26px;background:linear-gradient(135deg,#f8fafc 0%,#e0ecff 100%);border-bottom:1px solid #dbe1ea;">
                <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
                    <div style="width:56px;height:56px;background:#ecfdf5;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid #86efac;overflow:hidden;flex-shrink:0;">
                        <img src="{{ asset('mo-list-icon.png') }}" alt="Manufacturing Order List" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" />
                    </div>

                    <div>
                        <div style="font-size:1.3rem;font-weight:900;color:#1a1a2e;letter-spacing:-0.02em;line-height:1;">INTERMEDIATE PRODUCTION</div>
                        <div style="font-size:0.75rem;font-weight:700;color:#64748b;letter-spacing:.14em;margin-top:4px;">GROUPED OUTSTANDING ORDERS</div>
                    </div>

                    <span style="margin-left:auto;display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;font-size:12px;font-weight:800;">
                        {{ count($orders) }} shown
                    </span>
                </div>

                <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
                    <span style="display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;">Intermediate: {{ $intermediateCount }}</span>
                    <span style="display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;background:#ecfeff;border:1px solid #a5f3fc;color:#0e7490;font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;">Wet Packed: {{ $wetPackedCount }}</span>
                    <span style="display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;background:#fffbeb;border:1px solid #fde68a;color:#92400e;font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;">Other: {{ $otherCount }}</span>
                </div>
            </div>

            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead>
                    <tr style="background:#2d3f8f;color:#fff;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;">
                        <th class="px-4 py-3">MO Ref</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Product</th>
                        <th class="px-4 py-3">DBMTS Product</th>
                        <th class="px-4 py-3 text-right">Outstanding</th>
                        <th class="px-4 py-3">Due</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
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
                                <tr>
                                    <td colspan="7" style="padding:9px 16px;background:linear-gradient(90deg,#eef2ff 0%,#e0e7ff 100%);font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#3730a3;">
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
                                    <tr>
                                        <td colspan="7" style="padding:8px 16px;background:#f8fafc;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#475569;">
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
                            <tr>
                                <td colspan="7" style="padding:9px 16px;background:linear-gradient(90deg,#fffbeb 0%,#fef3c7 100%);font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#92400e;">
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
        @endif
    </div>
</div>
