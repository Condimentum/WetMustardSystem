<?php

use App\Domains\Audit\Jobs\RecordErrorLogJob;
use App\Domains\Batch\Exceptions\BatchException;
use App\Domains\WinMan\Exceptions\WinManException;
use App\Domains\WinMan\Support\WinManHealthCheck;
use App\Features\Batches\StartBatchFromManufacturingOrderFeature;
use App\Features\ManufacturingOrders\SearchManufacturingOrdersFeature;
use App\Models\BatchRecord;
use App\Models\ManufacturingOrder;
use App\Models\ProductMapping;
use App\Models\Product;
use App\Models\RecipeCard;
use App\Models\RecipeVariant;
use App\Models\WinManSyncedManufacturingOrder;
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

    public bool $winManDown = false;

    public ?string $ordersSyncedAt = null;

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
            app(RecordErrorLogJob::class)($e, 'manufacturing-orders.search.start-batch');
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
        if (! app(WinManHealthCheck::class)->isUp()) {
            $this->winManDown = true;
            $this->loadOrdersFromLocalSyncCache();

            return;
        }

        $this->winManDown = false;
        $this->ordersSyncedAt = null;

        $orders = collect(app(SearchManufacturingOrdersFeature::class)(
            $this->search !== '' ? $this->search : null,
            50,
        ))->filter(fn ($o) => $o->classification === 30)->values()->map(fn ($o) => $o->toArray())->all();

        $this->orders = $this->enrichOrders($orders);
    }

    /**
     * Fallback source when WinMan is unreachable: the last background-synced
     * snapshot (php artisan winman:sync-manufacturing-orders), so operators
     * can still see and continue existing batches instead of a blank screen.
     */
    private function loadOrdersFromLocalSyncCache(): void
    {
        $cached = WinManSyncedManufacturingOrder::query()
            ->where('classification', 30)
            ->when($this->search !== '', fn ($query) => $query->where(function ($q): void {
                $q->where('winman_manufacturing_order_id', 'like', '%'.$this->search.'%')
                    ->orWhere('winman_product_id', 'like', '%'.$this->search.'%')
                    ->orWhere('product_description', 'like', '%'.$this->search.'%');
            }))
            ->orderBy('due_date')
            ->orderByDesc('winman_manufacturing_order')
            ->limit(50)
            ->get();

        $this->ordersSyncedAt = $cached->max('synced_at')?->diffForHumans();

        $this->orders = $this->enrichOrders($cached->map(fn (WinManSyncedManufacturingOrder $o) => $o->toOrderArray())->all());
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawOrders  shaped like ManufacturingOrderData::toArray()
     * @return array<int, array<string, mixed>>
     */
    private function enrichOrders(array $rawOrders): array
    {
        $codes = collect($rawOrders)->map(fn (array $o) => $o['winman_product_id'])->filter()->unique()->all();

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

        return collect($rawOrders)->map(function (array $o) use ($productsByCode): array {
            $product = $productsByCode[$o['winman_product_id']] ?? null;
            $recipeCode = $product?->recipe_code;
            $hasVariants = $recipeCode !== null && RecipeVariant::query()
                ->where('recipe_code', $recipeCode)
                ->where('active_flag', true)
                ->exists();

            return array_merge($o, [
                'recipe_code' => $recipeCode,
                'dbmts_product_name' => $product?->product_name,
                'has_variants' => $hasVariants,
            ]);
        })->all();
    }
}; ?>

<div class="py-8">
    <x-mo-workspace-styles />
    <div class="wm-page max-w-7xl mx-auto space-y-6">

        @if ($winManDown)
            <x-winman-offline-banner :message="'WinMan connection is currently unavailable. Showing the last synced list of outstanding orders'.($ordersSyncedAt ? ' (synced '.$ordersSyncedAt.')' : '').' — existing in-progress batches remain fully usable from the batch screen.'" />
        @endif

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
                    <div class="overflow-x-auto">
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
            <div class="wm-card" style="padding:18px 22px;">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <div style="font-size:.72rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#8a6a2a;">Start Workspace</div>
                        <div class="wml-title" style="font-size:1.2rem;margin-top:4px;">
                            {{ $startSelectedOrder['winman_manufacturing_order_id'] ?? $selectedWinmanMo }}
                        </div>
                        <div class="text-sm text-gray-600 mt-1">
                            {{ $startSelectedOrder['product_description'] ?? 'Selected manufacturing order' }}
                        </div>
                    </div>
                    <button
                        type="button"
                        wire:click="openSelectedBatchWorkspace"
                        class="wm-btn-dark">
                        Open Batch
                    </button>
                </div>
            </div>
        @endif
        <section class="wm-card wm-card--gear-tr">
            <div class="wml-head">
                <span class="wml-medal"><img src="{{ asset('images/dashboard/gear.png') }}" alt="" /></span>
                <div>
                    <div class="wml-title">WET MUSTARD - MANUFACTURING</div>
                    <div class="wml-sub">OUTSTANDING MANUFACTURING ORDERS</div>
                </div>
                <span class="wml-count">{{ count($orders) }} shown</span>
            </div>

            <div class="wml-table">
                <div class="wml-bar wm-grid wm-grid--molist">
                    <div>MO Ref</div>
                    <div>Type</div>
                    <div>Product</div>
                    <div class="wml-num">Outstanding</div>
                    <div>Due</div>
                    <div></div>
                </div>

                @forelse ($orders as $order)
                    @php
                        $systemTypeRaw = strtoupper(trim((string) ($order['system_type'] ?? '')));
                        $systemType = match ($systemTypeRaw) {
                            'F' => ['bg' => '#1e4f8a', 'dot' => '#bfdbfe', 'label' => 'Firm'],
                            'R' => ['bg' => '#9a5b12', 'dot' => '#fde68a', 'label' => 'Released'],
                            'I' => ['bg' => '#2f5d3a', 'dot' => '#86efac', 'label' => 'Issued'],
                            default => ['bg' => '#4b5563', 'dot' => '#d1d5db', 'label' => $systemTypeRaw !== '' ? $systemTypeRaw : 'Unknown'],
                        };
                    @endphp
                    <div class="wm-prow wm-grid wm-grid--molist">
                        <div class="wm-ref wml-ref">{{ $order['winman_manufacturing_order_id'] }}</div>
                        <div data-label="Type">
                            <span class="wm-pill" style="background:{{ $systemType['bg'] }};">
                                <span class="wm-pill-dot" style="background:{{ $systemType['dot'] }};"></span>
                                {{ $systemType['label'] }}
                            </span>
                        </div>
                        <div class="wml-prod" data-label="Product">
                            {{ \Illuminate\Support\Str::limit((string) $order['product_description'], 52) }}
                            <small>{{ $order['winman_product_id'] }}</small>
                        </div>
                        <div class="wml-num" data-label="Outstanding">{{ $this->formatQuantity((float) $order['quantity_outstanding']) }}</div>
                        <div data-label="Due">{{ $order['due_date'] ? (string) \Illuminate\Support\Str::of($order['due_date'])->before(' ') : '—' }}</div>
                        <div class="wm-action">
                            <a href="{{ route('manufacturing-orders.workspace', ['winmanMo' => (int) $order['winman_manufacturing_order']]) }}" wire:navigate class="wm-btn-continue">Start</a>
                        </div>
                    </div>
                @empty
                    <div class="wml-empty">
                        No eligible outstanding MOs found.
                        <div style="margin-top:4px;font-size:.75rem;color:#9a8b6d;">The ProductMaster WinMan mapping may be empty (pending WM024).</div>
                    </div>
                @endforelse
            </div>
        </section>
        @endif
    </div>
</div>
