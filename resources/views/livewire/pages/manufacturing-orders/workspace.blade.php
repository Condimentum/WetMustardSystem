<?php

use App\Domains\Audit\Jobs\RecordErrorLogJob;
use App\Domains\Batch\Exceptions\BatchException;
use App\Domains\Pallecon\Support\PalleconCapacity;
use App\Domains\WinMan\Exceptions\WinManException;
use App\Domains\WinMan\Jobs\FetchManufacturingOrderJob;
use App\Domains\WinMan\Support\WinManHealthCheck;
use App\Features\Batches\StartBatchFromManufacturingOrderFeature;
use App\Features\Pallecon\OpenPalleconFeature;
use App\Models\BatchRecord;
use App\Models\ManufacturingOrder;
use App\Models\Pallecon;
use App\Models\ProductMapping;
use App\Models\Product;
use App\Models\RecipeCard;
use App\Models\RecipeVariant;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('MO Workspace')] class extends Component {
    public int $winmanMo;

    /** @var array<string,mixed>|null */
    public ?array $order = null;

    /** @var array<int, array<string,mixed>> */
    public array $existingBatches = [];

    /** @var array<int, array{id:int,label:string,batch_size:float}> */
    public array $variantOptions = [];

    public ?int $variantId = null;

    /** @var array<int, float> */
    public array $recipeBatchSizeOptions = [];

    public string $selectedRecipeBatchSizeKg = '';

    public string $batchPlannedQuantity = '';

    public ?string $error = null;

    public ?string $status = null;

    public bool $winManDown = false;

    public string $palleconWeight = '';

    public ?string $palleconError = null;

    public ?string $palleconStatus = null;

    public bool $showAddPalleconModal = false;

    public function mount(int $winmanMo): void
    {
        $this->winmanMo = $winmanMo;
        $this->loadWorkspace();
    }

    public function updatedVariantId($value): void
    {
        // Batch quantity is recipe-card driven, so variant selection no longer
        // overwrites planned quantity in the UI.
    }

    public function start(): void
    {
        $this->error = null;
        $this->status = null;

        $hasInProgressBatch = collect($this->existingBatches)->contains(
            fn (array $batch): bool => (string) ($batch['status'] ?? '') === BatchRecord::STATUS_IN_PROGRESS
        );
        if ($hasInProgressBatch) {
            $this->error = 'Complete the current in-progress batch before adding another batch.';

            return;
        }

        if ($this->order === null) {
            $this->error = 'Manufacturing order was not found.';

            return;
        }

        try {
            $batch = app(StartBatchFromManufacturingOrderFeature::class)(
                $this->winmanMo,
                $this->variantId,
                $this->selectedRecipeBatchSizeKg !== '' ? (float) $this->selectedRecipeBatchSizeKg : null,
                auth()->user(),
            );
        } catch (WinManException|BatchException $e) {
            app(RecordErrorLogJob::class)($e, 'manufacturing-orders.workspace.start-batch');
            $this->error = $e->getMessage();

            return;
        }

        $this->loadWorkspace();
        $this->status = 'Batch '.$batch->batch_number.' created. You can continue with any batch below.';
    }

    private function localMoOrder(): ?ManufacturingOrder
    {
        return ManufacturingOrder::query()
            ->where('winman_manufacturing_order', $this->winmanMo)
            ->first();
    }

    /**
     * Every pallecon for this MO (any status) - the list under the Pallecon
     * Workspace header, mirroring the batch list above.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function moPallecons(): array
    {
        $moId = (int) ($this->localMoOrder()?->id ?? 0);

        if ($moId <= 0) {
            return [];
        }

        return Pallecon::query()
            ->where('manufacturing_order_id', $moId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (Pallecon $pallecon): array => [
                'id' => $pallecon->id,
                'reference' => (string) ($pallecon->winman_reference ?? ''),
                'quantity_kg' => $pallecon->final_weight !== null
                    ? (float) $pallecon->final_weight
                    : ($pallecon->target_weight_kg !== null ? (float) $pallecon->target_weight_kg : $pallecon->filledWeight()),
                'production_date' => $pallecon->production_date?->format('Y-m-d'),
                'status' => (string) $pallecon->status,
                'on_hold' => $pallecon->isOnHold(),
            ])
            ->all();
    }

    #[Computed]
    public function hasOpenPalleconForMo(): bool
    {
        return collect($this->moPallecons)->contains(
            fn (array $pallecon): bool => in_array($pallecon['status'], ['open', 'filling'], true)
        );
    }

    public function createPallecon(): void
    {
        $this->palleconError = null;
        $this->palleconStatus = null;

        $this->validate([
            'palleconWeight' => ['required', 'numeric', 'min:0.001'],
        ]);

        $order = $this->localMoOrder();
        if ($order === null) {
            $this->palleconError = 'Manufacturing order was not found.';

            return;
        }

        if ($this->hasOpenPalleconForMo) {
            $this->palleconError = 'A pallecon is already open for this MO. Complete it before starting another.';

            return;
        }

        $weight = (float) $this->palleconWeight;
        if (PalleconCapacity::exceedsPhysicalCapacity($weight)) {
            $this->palleconError = sprintf(
                'Target weight %s kg is above the %s kg pallecon capacity.',
                rtrim(rtrim(number_format($weight, 3, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format(PalleconCapacity::capacityKg(), 3, '.', ''), '0'), '.'),
            );

            return;
        }

        try {
            $pallecon = app(OpenPalleconFeature::class)([
                'manufacturing_order_id' => $order->id,
                'mo_number' => $order->mo_number,
                'target_weight_kg' => $weight,
                'production_date' => now()->toDateString(),
            ], auth()->user());
        } catch (\Throwable $e) {
            app(RecordErrorLogJob::class)($e, 'manufacturing-orders.workspace.create-pallecon');
            $this->palleconError = $e->getMessage();

            return;
        }

        $this->palleconStatus = 'Pallecon opened with a '.$this->palleconWeight.' kg target. Continue to record fills, number and seals.';
        $this->reset('palleconWeight');
        $this->showAddPalleconModal = false;
        unset($this->moPallecons, $this->hasOpenPalleconForMo);
    }

    public function openAddPalleconModal(): void
    {
        $this->palleconError = null;
        $this->palleconStatus = null;
        $this->reset('palleconWeight');
        $this->resetErrorBag('palleconWeight');
        $this->showAddPalleconModal = true;
    }

    public function closeAddPalleconModal(): void
    {
        $this->showAddPalleconModal = false;
        $this->reset('palleconWeight');
        $this->resetErrorBag('palleconWeight');
    }

    private function loadWorkspace(): void
    {
        if (! app(WinManHealthCheck::class)->isUp()) {
            $this->winManDown = true;
            $winmanOrder = null;
        } else {
            $this->winManDown = false;

            try {
                $winmanOrder = app(FetchManufacturingOrderJob::class)($this->winmanMo);
            } catch (\Throwable $e) {
                report($e);
                $winmanOrder = null;
            }
        }

        if ($winmanOrder !== null) {
            $orderData = $winmanOrder->toArray();
            $product = Product::query()
                ->where(function ($query) use ($orderData): void {
                    $query->where('winman_product_id', (string) $orderData['winman_product_id'])
                        ->orWhere('finished_goods_code', (string) $orderData['winman_product_id']);
                })
                ->first();

            $this->order = array_merge($orderData, [
                'recipe_code' => $product?->recipe_code,
                'dbmts_product_name' => $product?->product_name,
            ]);
        } else {
            $this->order = null;
        }

        if (! is_array($this->order)) {
            $this->existingBatches = [];
            $this->variantOptions = [];
            $this->variantId = null;
            $this->recipeBatchSizeOptions = [];
            $this->selectedRecipeBatchSizeKg = '';
            $this->batchPlannedQuantity = '';

            return;
        }

        $recipeCode = $this->order['recipe_code'] ?? null;

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

        $this->recipeBatchSizeOptions = $this->resolveRecipeBatchSizeOptionsFromOrder($this->order);
        if (count($this->recipeBatchSizeOptions) === 1) {
            $this->selectedRecipeBatchSizeKg = (string) $this->recipeBatchSizeOptions[0];
        } else {
            $this->selectedRecipeBatchSizeKg = '';
        }

        $this->batchPlannedQuantity = $this->selectedRecipeBatchSizeKg !== ''
            ? $this->formatQuantity((float) $this->selectedRecipeBatchSizeKg)
            : '';

        $this->existingBatches = $this->loadExistingBatchesForMo($this->winmanMo);
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
            ->orderBy('id')
            ->withCount('palleconFills as pallecon_fills_count')
            ->withSum('palleconFills as pallecon_allocated_kg', 'fill_weight')
            ->get(['id', 'batch_number', 'planned_quantity', 'production_date', 'status'])
            ->map(function (BatchRecord $batch): array {
                $planned = (float) ($batch->planned_quantity ?? 0);
                $allocatedKg = (float) ($batch->pallecon_allocated_kg ?? 0);
                $fills = (int) ($batch->pallecon_fills_count ?? 0);

                // Pallecon allocation state: nothing filled yet, part of the
                // planned quantity filled, or the whole batch accounted for.
                $allocationState = match (true) {
                    $fills === 0 => 'awaiting',
                    $planned > 0 && $allocatedKg + 0.0001 >= $planned => 'allocated',
                    default => 'partial',
                };

                return [
                    'id' => $batch->id,
                    // Batch Reference is app-only; WinMan references belong to pallecons.
                    'reference' => (string) $batch->batch_number,
                    'application_batch_number' => (string) $batch->batch_number,
                    'planned_quantity' => $planned,
                    'production_date' => $batch->production_date?->format('Y-m-d'),
                    'status' => (string) $batch->status,
                    'allocation_state' => $allocationState,
                    'allocated_kg' => $allocatedKg,
                ];
            })
            ->all();
    }

    private function formatQuantity(float $value): string
    {
        $formatted = number_format($value, 3, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    public function updatedSelectedRecipeBatchSizeKg($value): void
    {
        $this->batchPlannedQuantity = trim((string) $value) !== ''
            ? $this->formatQuantity((float) $value)
            : '';
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

}; ?>

<div class="py-8">
    <x-mo-workspace-styles />
    <div class="wm-page max-w-7xl mx-auto space-y-6">
        <div class="flex items-center justify-end">
            <a href="{{ route('manufacturing-orders.search') }}" wire:navigate class="text-sm text-indigo-600 hover:underline">Back to Manufacturing Orders</a>
        </div>

        @if ($winManDown)
            <x-winman-offline-banner message="WinMan connection is currently unavailable. This order's live details can't be refreshed right now — any existing batch for it remains fully usable." />
        @endif

        @if (! $order)
            <div class="bg-white shadow-sm rounded-lg p-6 text-sm text-gray-600">
                Manufacturing order was not found or is no longer eligible.
            </div>
        @else
            @if ($status)
                <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-lg px-4 py-3">
                    {{ $status }}
                </div>
            @endif

            @php
                $planned = (float) ($order['planned_quantity'] ?? 0);
                $outstanding = (float) ($order['quantity_outstanding'] ?? 0);
                $made = max($planned - $outstanding, 0.0);
                $fmt = static function (float $v): string {
                    $formatted = number_format($v, 3, '.', '');

                    return rtrim(rtrim($formatted, '0'), '.') ?: '0';
                };
            @endphp

            <x-mo-header
                :system-type="$order['system_type'] ?? ''"
                :mo-number="$order['winman_manufacturing_order_id'] ?? $winmanMo"
                :product="$order['winman_product_id'] ?? '-'"
                :description="$order['product_description'] ?? '-'"
                date-label="Due Date"
                :date-value="! empty($order['due_date']) ? (string) \Illuminate\Support\Str::of((string) $order['due_date'])->before(' ') : '-'"
                :planned="$planned"
                :made="$made"
                :outstanding="$outstanding"
                :batches="count($existingBatches)"
                :fmt="$fmt"
            />

            <section class="wm-card wm-card--gear-tr">
                @if ($error)
                    <div class="text-sm bg-red-50 border border-red-200 rounded px-3 py-2 text-red-700" style="margin-bottom:12px;">{{ $error }}</div>
                @endif

                @if (count($existingBatches) > 0)
                    <div class="wm-grid wm-grid--batch wm-head wm-head--batch">
                        <h2 class="wm-title">Batch Workspace</h2>
                        <div>Qty</div>
                        <div>Production Date</div>
                        <div>Status</div>
                        <div>Allocation</div>
                        <div></div>
                    </div>

                    <div class="wm-rows">
                        @foreach ($existingBatches as $batch)
                            @php
                                $isCompleted = (string) $batch['status'] === \App\Models\BatchRecord::STATUS_COMPLETED;
                                $batchTone = match ((string) $batch['status']) {
                                    \App\Models\BatchRecord::STATUS_IN_PROGRESS => ['strip' => '#c9a24a', 'pill' => 'linear-gradient(180deg,#d4ad55,#b8913a)', 'dot' => '#fdf3d0'],
                                    \App\Models\BatchRecord::STATUS_COMPLETED => ['strip' => '#1f5c61', 'pill' => '#1f5c61', 'dot' => '#7fd1bf'],
                                    \App\Models\BatchRecord::STATUS_QA_REVIEW => ['strip' => '#6d5a96', 'pill' => '#6d5a96', 'dot' => '#d6ccf0'],
                                    \App\Models\BatchRecord::STATUS_CLOSED => ['strip' => '#6b7280', 'pill' => '#6b7280', 'dot' => '#d1d5db'],
                                    default => ['strip' => '#9b3b3b', 'pill' => '#9b3b3b', 'dot' => '#f5c2c2'],
                                };
                                $batchStatusLabel = (string) $batch['status'] === \App\Models\BatchRecord::STATUS_IN_PROGRESS
                                    ? 'Issued'
                                    : \Illuminate\Support\Str::headline((string) $batch['status']);

                                $allocState = (string) ($batch['allocation_state'] ?? 'awaiting');
                                $allocTitle = match ($allocState) {
                                    'allocated' => 'Fully Allocated',
                                    'partial' => 'Partially Allocated',
                                    default => 'Awaiting Allocation',
                                };
                                $allocatedKg = (float) ($batch['allocated_kg'] ?? 0);
                                $plannedKg = (float) ($batch['planned_quantity'] ?? 0);
                                $allocPct = $plannedKg > 0 ? min(100, max(0, $allocatedKg / $plannedKg * 100)) : 0;
                            @endphp
                            <div class="wm-row wm-grid wm-grid--batch" style="--wm-strip: {{ $batchTone['strip'] }};">
                                <div class="wm-ref">
                                    <img src="{{ asset('images/batch-row-icon.png') }}" alt="" class="wm-picon" style="width:48px;height:48px;" />
                                    <div style="min-width:0;">
                                        <div class="wm-ref-text">{{ $batch['reference'] !== '' ? $batch['reference'] : '—' }}</div>
                                        @if ($batch['reference'] !== $batch['application_batch_number'] && $batch['application_batch_number'] !== '')
                                            <div class="wm-sub">App ref: {{ $batch['application_batch_number'] }}</div>
                                        @endif
                                    </div>
                                </div>
                                <div data-label="Qty">{{ $fmt((float) $batch['planned_quantity']) }}</div>
                                <div data-label="Production Date">{{ $batch['production_date'] ?? '-' }}</div>
                                <div data-label="Status">
                                    <span class="wm-pill" style="background:{{ $batchTone['pill'] }};">
                                        <span class="wm-pill-dot" style="background:{{ $batchTone['dot'] }};"></span>
                                        {{ $batchStatusLabel }}
                                    </span>
                                </div>
                                <div data-label="Allocation">
                                    <a href="{{ route('manufacturing-orders.pallecons', ['winmanMo' => $winmanMo]) }}" wire:navigate
                                       title="{{ $allocTitle }}: {{ $fmt($allocatedKg) }} of {{ $fmt($plannedKg) }} kg allocated to pallecons"
                                       class="wm-alloc wm-alloc--{{ $allocState === 'allocated' ? 'full' : ($allocState === 'partial' ? 'partial' : 'awaiting') }}">
                                        @if ($allocState === 'allocated')
                                            <span class="wm-orb"></span>
                                            <span style="max-width:70px;">Fully Allocated</span>
                                        @else
                                            <span class="wm-alloc-fill" style="width:{{ round($allocPct, 1) }}%;"></span>
                                            <span>{{ $fmt($allocatedKg) }} / {{ $fmt($plannedKg) }} kg</span>
                                        @endif
                                    </a>
                                </div>
                                <div class="wm-action">
                                    @if ($isCompleted)
                                        <a href="{{ route('batches.show', ['batch' => (int) $batch['id'], 'tab' => 'allocation']) }}" wire:navigate class="wm-link">View Completed</a>
                                    @else
                                        <a href="{{ route('batches.show', ['batch' => (int) $batch['id'], 'tab' => 'allocation']) }}" wire:navigate class="wm-btn-continue">Continue</a>
                                        <span class="wm-info" tabindex="0" aria-label="WinMan reference is automatically updated upon pallecon seal.">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9.5"/><path d="M12 11v6M12 7.5v.01" stroke-linecap="round"/></svg>
                                            <span class="wm-tip" role="tooltip"><strong>Note:</strong> WinMan reference is automatically updated upon pallecon seal.</span>
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <h2 class="wm-title">Batch Workspace</h2>
                    <div class="wm-row" style="--wm-strip:#c9a24a;margin-top:12px;">
                        <div style="display:flex;align-items:stretch;gap:0;flex-wrap:wrap;row-gap:14px;">
                            <div style="padding:0 26px 0 0;min-width:180px;border-right:1px solid #ebe2cd;">
                                <div style="font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#8a7a5c;font-weight:700;">Reference</div>
                                <div style="margin-top:8px;font-size:24px;line-height:1.05;font-weight:800;color:#1f3f4f;">Not Created</div>
                            </div>

                            <div style="padding:0 26px;min-width:150px;border-right:1px solid #ebe2cd;">
                                <div style="font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#8a7a5c;font-weight:700;">Batch Qty</div>
                                <div style="margin-top:8px;font-size:24px;line-height:1.05;font-weight:800;color:#1f3f4f;">{{ $batchPlannedQuantity !== '' ? $batchPlannedQuantity : '0' }}</div>
                            </div>

                            <div style="padding:0 26px;display:flex;flex-direction:column;justify-content:center;">
                                <div style="font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#8a7a5c;font-weight:700;">Status</div>
                                <span class="wm-pill" style="margin-top:8px;width:max-content;background:linear-gradient(180deg,#d4ad55,#b8913a);">
                                    <span class="wm-pill-dot" style="background:#fdf3d0;"></span>
                                    Awaiting First Batch
                                </span>
                            </div>

                            <div style="margin-left:auto;display:flex;align-items:center;color:#8a7a5c;font-size:14px;font-weight:600;">
                                No batches exist yet for this MO.
                            </div>
                        </div>
                    </div>
                @endif

                @php
                    // Multiple batches per MO are allowed; only block a new one
                    // while an earlier batch is still in progress. start() enforces
                    // the same rule server-side.
                    $hasInProgressBatch = collect($existingBatches)->contains(
                        fn ($b): bool => (string) ($b['status'] ?? '') === \App\Models\BatchRecord::STATUS_IN_PROGRESS
                    );
                @endphp

                @if (! $hasInProgressBatch)
                    <div class="flex flex-wrap items-end justify-end gap-3" style="margin-top:18px;">
                        @if (count($variantOptions) > 0)
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">Batch-size variant (optional)</label>
                                <select wire:model="variantId" class="border-gray-300 rounded-md shadow-sm text-sm">
                                    <option value="">- select variant -</option>
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
                        @elseif (count($existingBatches) === 0)
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">Batch quantity (kg)</label>
                                <input wire:model="batchPlannedQuantity" type="text" readonly class="border-gray-300 bg-gray-100 rounded-md shadow-sm text-sm w-40" />
                                <span class="block text-xs text-gray-500 mt-1">Auto from recipe card batch size.</span>
                            </div>
                        @else
                            <input wire:model="batchPlannedQuantity" type="hidden" />
                        @endif

                        <button type="button" class="wm-btn-dark" wire:click="start" wire:loading.attr="disabled">
                            {{ count($existingBatches) === 0 ? 'Add batch' : 'Add another batch' }}
                            <span class="wm-plus">+</span>
                        </button>
                    </div>
                @elseif (count($existingBatches) > 0)
                    <div class="wm-warn" style="margin-top:14px;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 10.5v6M12 7.2v.01" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/></svg>
                        Complete the in-progress batch before adding another one to this MO.
                    </div>
                @endif
            </section>

            <section class="wm-card wm-card--gear-bl">
                <h2 class="wm-title">Pallecon Workspace</h2>

                @if ($palleconError)
                    <div class="text-sm bg-red-50 border border-red-200 rounded px-3 py-2 text-red-700" style="margin-top:12px;">{{ $palleconError }}</div>
                @endif
                @if ($palleconStatus)
                    <div class="text-sm bg-green-50 border border-green-200 rounded px-3 py-2 text-green-700" style="margin-top:12px;">{{ $palleconStatus }}</div>
                @endif

                @php
                    $palleconTone = fn (string $s): array => match ($s) {
                        'filling' => ['pill' => 'linear-gradient(180deg,#d4ad55,#b8913a)', 'dot' => '#fdf3d0'],
                        'open' => ['pill' => '#3d6a8a', 'dot' => '#c7e0f2'],
                        'sealed' => ['pill' => '#1f5c61', 'dot' => '#7fd1bf'],
                        'consumed' => ['pill' => '#6b7280', 'dot' => '#d1d5db'],
                        'on_hold' => ['pill' => '#9b3b3b', 'dot' => '#f5c2c2'],
                        default => ['pill' => '#6b7280', 'dot' => '#d1d5db'],
                    };
                @endphp

                @if (count($this->moPallecons) > 0)
                    <div class="wm-table">
                        <div class="wm-grid wm-grid--pallecon wm-head">
                            <div>Reference</div>
                            <div>Qty (kg)</div>
                            <div>Production Date</div>
                            <div>Status</div>
                            <div></div>
                        </div>
                        @foreach ($this->moPallecons as $pallecon)
                            @php
                                $pTone = $palleconTone($pallecon['on_hold'] ? 'on_hold' : $pallecon['status']);
                                $isPalleconCompleted = in_array($pallecon['status'], [\App\Models\Pallecon::STATUS_SEALED, \App\Models\Pallecon::STATUS_CONSUMED], true);
                                $palleconUrl = route('manufacturing-orders.pallecons', ['winmanMo' => $winmanMo, 'pallecon' => $pallecon['id']]);
                            @endphp
                            <div class="wm-prow wm-grid wm-grid--pallecon">
                                <div class="wm-ref">
                                    <img src="{{ asset('pallecon-row-icon.png') }}" alt="" class="wm-picon" />
                                    <div class="wm-ref-text">{{ $pallecon['reference'] !== '' ? $pallecon['reference'] : '—' }}</div>
                                </div>
                                <div data-label="Qty (kg)">{{ rtrim(rtrim(number_format((float) $pallecon['quantity_kg'], 3, '.', ''), '0'), '.') ?: '0' }}</div>
                                <div data-label="Production Date">{{ $pallecon['production_date'] ?? '-' }}</div>
                                <div data-label="Status">
                                    <span class="wm-pill" style="background:{{ $pTone['pill'] }};">
                                        <span class="wm-pill-dot" style="background:{{ $pTone['dot'] }};"></span>
                                        {{ $pallecon['on_hold'] ? 'On Hold' : \Illuminate\Support\Str::headline($pallecon['status']) }}
                                    </span>
                                </div>
                                <div class="wm-action">
                                    @if ($isPalleconCompleted)
                                        <a href="{{ $palleconUrl }}" wire:navigate class="wm-link">View Completed</a>
                                    @else
                                        <a href="{{ $palleconUrl }}" wire:navigate class="wm-btn-continue">Continue</a>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($this->hasOpenPalleconForMo)
                    <div class="wm-warn" style="margin-top:14px;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 10.5v6M12 7.2v.01" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/></svg>
                        Complete the open pallecon before creating another one for this MO.
                    </div>
                @else
                    <div class="flex flex-wrap items-center justify-end gap-3" style="margin-top:18px;">
                        <button type="button" class="wm-btn-dark" wire:click="openAddPalleconModal" wire:loading.attr="disabled">
                            {{ count($this->moPallecons) === 0 ? 'Add pallecon' : 'Add another pallecon' }}
                            <span class="wm-plus">+</span>
                        </button>
                    </div>
                    <p class="wm-note" style="margin-top:10px;">Use Continue on a row to record batch fills, the pallecon number, seals and completion — the WinMan reference is written back on seal.</p>
                @endif
            </section>
        @endif
    </div>

    @if ($showAddPalleconModal)
        <div style="position:fixed;inset:0;z-index:50;display:flex;align-items:center;justify-content:center;background:rgba(15,23,42,0.5);padding:16px;" wire:click.self="closeAddPalleconModal">
            <div style="background:#fff;border-radius:16px;box-shadow:0 20px 40px rgba(15,23,42,0.25);width:100%;max-width:360px;padding:24px;">
                <h3 style="font-size:16px;font-weight:700;color:#0f172a;margin:0 0 4px;">{{ count($this->moPallecons) === 0 ? 'Add Pallecon' : 'Add Another Pallecon' }}</h3>
                <p style="font-size:13px;color:#64748b;margin:0 0 16px;">Set a target fill weight to open a new pallecon for this MO.</p>

                @if ($palleconError)
                    <div class="text-sm bg-red-50 border border-red-200 rounded px-3 py-2 text-red-700" style="margin-bottom:12px;">{{ $palleconError }}</div>
                @endif

                <label class="block text-xs text-gray-600 mb-1">Pallecon weight (kg)</label>
                <input type="number" step="0.001" min="0.001" wire:model="palleconWeight" wire:keydown.enter="createPallecon" autofocus class="border-gray-300 rounded-md shadow-sm text-sm w-full" placeholder="e.g. 1000" />
                @error('palleconWeight') <span class="block text-xs text-red-600 mt-1">{{ $message }}</span> @enderror
                <span class="block text-xs text-gray-500 mt-1">Target weight for this pallecon.</span>

                <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:20px;">
                    <button type="button" wire:click="closeAddPalleconModal" style="padding:8px 16px;border-radius:8px;border:1px solid #cbd5e1;background:#fff;color:#334155;font-size:13px;font-weight:700;cursor:pointer;">Cancel</button>
                    <x-primary-button wire:click="createPallecon" wire:loading.attr="disabled">Confirm</x-primary-button>
                </div>
            </div>
        </div>
    @endif
</div>
