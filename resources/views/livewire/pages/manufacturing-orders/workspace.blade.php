<?php

use App\Domains\Audit\Jobs\RecordErrorLogJob;
use App\Domains\Batch\Exceptions\BatchException;
use App\Domains\Pallecon\Support\PalleconFilling;
use App\Domains\WinMan\Exceptions\WinManException;
use App\Domains\WinMan\Jobs\FetchManufacturingOrderJob;
use App\Domains\WinMan\Support\WinManHealthCheck;
use App\Features\Batches\StartBatchFromManufacturingOrderFeature;
use App\Features\Pallecon\CreatePalleconWithFillFeature;
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

    public string $palleconNumber = '';

    public string $palleconFillWeight = '';

    public string $palleconBatchId = '';

    public ?string $palleconError = null;

    public ?string $palleconStatus = null;

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
     * Every pallecon worked for this MO (any status) - the list under the
     * Pallecon Workspace header, mirroring the batch list above.
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
            ->whereHas('fills.batchRecord', fn ($query) => $query->where('manufacturing_order_id', $moId))
            ->with(['fills.batchRecord:id,manufacturing_order_id'])
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Pallecon $pallecon): bool => $pallecon->fills->isNotEmpty()
                && $pallecon->fills->every(
                    fn ($fill): bool => (int) ($fill->batchRecord?->manufacturing_order_id ?? 0) === $moId
                ))
            ->map(fn (Pallecon $pallecon): array => [
                'id' => $pallecon->id,
                'reference' => (string) ($pallecon->serial_number ?? 'Pallecon #'.$pallecon->id),
                'filled_kg' => $pallecon->filledWeight(),
                'final_weight' => $pallecon->final_weight !== null ? (float) $pallecon->final_weight : null,
                'opened_date' => $pallecon->opened_at?->format('Y-m-d'),
                'status' => (string) $pallecon->status,
                'on_hold' => $pallecon->isOnHold(),
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    #[Computed]
    public function palleconFillBatches(): array
    {
        $order = $this->localMoOrder();

        return $order === null ? [] : app(PalleconFilling::class)->fillableBatches($order);
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
            'palleconNumber' => ['required', 'string', 'max:255'],
            'palleconBatchId' => ['required', 'integer'],
            'palleconFillWeight' => ['required', 'numeric', 'min:0.001'],
        ]);

        $order = $this->localMoOrder();
        if ($order === null) {
            $this->palleconError = 'Manufacturing order was not found.';

            return;
        }

        $filling = app(PalleconFilling::class);
        $batchMeta = collect($filling->fillableBatches($order))->firstWhere('id', (int) $this->palleconBatchId);

        $guard = $filling->guardFill($batchMeta, (float) $this->palleconFillWeight);
        if ($guard !== null) {
            $this->palleconError = $guard;

            return;
        }

        $batch = BatchRecord::with('manufacturingOrder', 'product')->findOrFail((int) $this->palleconBatchId);

        try {
            ['pallecon' => $pallecon, 'fill' => $fill] = app(CreatePalleconWithFillFeature::class)(
                $order,
                $this->palleconNumber,
                $batch,
                (float) $this->palleconFillWeight,
                auth()->user(),
            );
        } catch (\Throwable $e) {
            app(RecordErrorLogJob::class)($e, 'manufacturing-orders.workspace.create-pallecon');
            $this->palleconError = $e->getMessage();

            return;
        }

        $booking = $filling->bookFill($batch, $pallecon, $fill, now()->toDateString(), auth()->user());

        $message = 'Pallecon '.($pallecon->serial_number ?? '#'.$pallecon->id)
            .' created with a '.$this->palleconFillWeight.' kg fill from batch '.$batch->batch_number.'.';
        if ($booking['messages'] !== []) {
            $message .= ' '.implode(' ', $booking['messages']);
        }

        $this->palleconStatus = $message;
        $this->reset('palleconNumber', 'palleconFillWeight', 'palleconBatchId');
        unset($this->moPallecons, $this->palleconFillBatches, $this->hasOpenPalleconForMo);
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
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
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
                $moStatus = strtoupper(trim((string) ($order['system_type'] ?? '')));
                $statusPill = match ($moStatus) {
                    'C', 'CANCELLED', 'CANCELED' => ['bg' => '#fef2f2', 'border' => '#fca5a5', 'color' => '#dc2626', 'dot' => '#dc2626', 'label' => 'Cancelled'],
                    'F' => ['bg' => '#eff6ff', 'border' => '#bfdbfe', 'color' => '#2563eb', 'dot' => '#2563eb', 'label' => 'Firm'],
                    'R' => ['bg' => '#fffbeb', 'border' => '#fcd34d', 'color' => '#b45309', 'dot' => '#f59e0b', 'label' => 'Released'],
                    'I' => ['bg' => '#ecfdf5', 'border' => '#86efac', 'color' => '#15803d', 'dot' => '#16a34a', 'label' => 'Issued'],
                    default => ['bg' => '#f3f4f6', 'border' => '#d1d5db', 'color' => '#4b5563', 'dot' => '#6b7280', 'label' => $moStatus !== '' ? $moStatus : 'Unknown'],
                };

                $planned = (float) ($order['planned_quantity'] ?? 0);
                $outstanding = (float) ($order['quantity_outstanding'] ?? 0);
                $made = max($planned - $outstanding, 0.0);
                $fmt = static function (float $v): string {
                    $formatted = number_format($v, 3, '.', '');

                    return rtrim(rtrim($formatted, '0'), '.') ?: '0';
                };
            @endphp

            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                <div style="padding:28px 32px 0;">
                    <div style="display:flex;align-items:center;gap:18px;padding-bottom:22px;border-bottom:1px solid #e5e7eb;margin-bottom:22px;flex-wrap:wrap;">
                        <div style="width:64px;height:64px;background:#ecfdf5;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;border:2px solid #86efac;overflow:hidden;">
                            <img src="{{ asset('mustard.png') }}" alt="Mustard" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" />
                        </div>
                        <div>
                            <div style="font-size:1.5rem;font-weight:900;color:#1a1a2e;letter-spacing:-0.02em;line-height:1;">MANUFACTURING ORDER</div>
                            <div style="font-size:0.78rem;font-weight:700;color:#9ca3af;letter-spacing:.15em;margin-top:4px;">DETAILS</div>
                        </div>
                        <span style="margin-left:auto;display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border-radius:20px;font-size:0.78rem;font-weight:700;background:{{ $statusPill['bg'] }};border:1px solid {{ $statusPill['border'] }};color:{{ $statusPill['color'] }};">
                            <span style="width:7px;height:7px;border-radius:50%;background:{{ $statusPill['dot'] }};display:inline-block;"></span>
                            {{ $statusPill['label'] }}
                        </span>
                    </div>

                    <div style="overflow:auto hidden;margin-bottom:26px;">
                        <div style="display:grid;grid-template-columns:repeat(4,minmax(180px,1fr));gap:0;min-width:760px;">
                            <div style="padding:0 20px 0 0;border-right:1px solid #e5e7eb;">
                                <div style="font-size:0.72rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;">MO Number</div>
                                <div style="font-size:1.05rem;font-weight:800;color:#16a34a;">{{ $order['winman_manufacturing_order_id'] ?? $winmanMo }}</div>
                            </div>

                            <div style="padding:0 20px;border-right:1px solid #e5e7eb;">
                                <div style="font-size:0.72rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;">Product</div>
                                <div style="font-size:1.05rem;font-weight:800;color:#1a1a2e;">{{ $order['winman_product_id'] ?? '-' }}</div>
                            </div>

                            <div style="padding:0 20px;border-right:1px solid #e5e7eb;">
                                <div style="font-size:0.72rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;">Product Description</div>
                                <div style="font-size:0.95rem;font-weight:700;color:#1a1a2e;line-height:1.35;">{{ $order['product_description'] ?? '-' }}</div>
                            </div>

                            <div style="padding:0 0 0 20px;">
                                <div style="font-size:0.72rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px;">Due Date</div>
                                <div style="font-size:1.05rem;font-weight:800;color:#1a1a2e;">{{ ! empty($order['due_date']) ? (string) \Illuminate\Support\Str::of((string) $order['due_date'])->before(' ') : '-' }}</div>
                            </div>
                        </div>
                    </div>

                    <div style="background:#2d3f8f;border-radius:10px;overflow:hidden;margin-bottom:20px;">
                        <div style="padding:14px 20px;border-bottom:1px solid rgba(255,255,255,0.12);">
                            <span style="font-size:0.75rem;font-weight:800;color:#fff;text-transform:uppercase;letter-spacing:.12em;">Quantities</span>
                        </div>
                        <div style="overflow:auto hidden;background:#f8fafc;">
                            <div style="display:grid;grid-template-columns:repeat(4,minmax(170px,1fr));gap:0;min-width:700px;">
                                <div style="padding:22px 16px;text-align:center;border-right:1px solid #e5e7eb;">
                                    <div style="font-size:0.65rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;">On Order</div>
                                    <div style="font-size:1.3rem;font-weight:900;color:#f59e0b;">{{ $fmt($planned) }}</div>
                                </div>
                                <div style="padding:22px 16px;text-align:center;border-right:1px solid #e5e7eb;">
                                    <div style="font-size:0.65rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;">Made</div>
                                    <div style="font-size:1.3rem;font-weight:900;color:#16a34a;">{{ $fmt($made) }}</div>
                                </div>
                                <div style="padding:22px 16px;text-align:center;border-right:1px solid #e5e7eb;">
                                    <div style="font-size:0.65rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;">Outstanding</div>
                                    <div style="font-size:1.3rem;font-weight:900;color:#2563eb;">{{ $fmt($outstanding) }}</div>
                                </div>
                                <div style="padding:22px 16px;text-align:center;">
                                    <div style="font-size:0.65rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;">Batches</div>
                                    <div style="font-size:1.3rem;font-weight:900;color:#7c3aed;">{{ count($existingBatches) }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
                <div style="padding:14px 18px;border-bottom:1px solid #dbe1ea;background:linear-gradient(180deg,#f8fafc 0%,#f1f5f9 100%);display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                    <div class="text-base font-semibold text-gray-800">Batch Workspace</div>
                </div>
                <div class="p-6 space-y-6">

                @if ($error)
                    <div class="text-sm bg-red-50 border border-red-200 rounded px-3 py-2 text-red-700">{{ $error }}</div>
                @endif

                @if (count($existingBatches) > 0)
                    <div style="background:#fff;border:1px solid #dbe1ea;border-radius:16px;overflow:hidden;box-shadow:0 1px 2px rgba(15,23,42,0.05);">
                        <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="text-left text-xs text-slate-500 uppercase bg-slate-50">
                                <tr>
                                    <th class="px-4 py-3">Reference</th>
                                    <th class="px-3 py-2">Qty</th>
                                    <th class="px-3 py-2">Production Date</th>
                                    <th class="px-3 py-2">Status</th>
                                    <th class="px-3 py-2">Allocation</th>
                                    <th class="px-3 py-2 text-right"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($existingBatches as $batch)
                                    @php
                                        $isIssued = (string) $batch['status'] === \App\Models\BatchRecord::STATUS_IN_PROGRESS;
                                        $batchStatusStyles = match ((string) $batch['status']) {
                                            \App\Models\BatchRecord::STATUS_IN_PROGRESS => ['bg' => '#fef9c3', 'border' => '#fde68a', 'color' => '#92400e', 'dot' => '#f59e0b'],
                                            \App\Models\BatchRecord::STATUS_COMPLETED => ['bg' => '#dcfce7', 'border' => '#86efac', 'color' => '#166534', 'dot' => '#22c55e'],
                                            \App\Models\BatchRecord::STATUS_QA_REVIEW => ['bg' => '#ede9fe', 'border' => '#c4b5fd', 'color' => '#5b21b6', 'dot' => '#8b5cf6'],
                                            \App\Models\BatchRecord::STATUS_CLOSED => ['bg' => '#f1f5f9', 'border' => '#cbd5e1', 'color' => '#334155', 'dot' => '#64748b'],
                                            default => ['bg' => '#fee2e2', 'border' => '#fca5a5', 'color' => '#991b1b', 'dot' => '#ef4444'],
                                        };
                                        $batchStatusLabel = $isIssued
                                            ? 'Issued'
                                            : \Illuminate\Support\Str::headline((string) $batch['status']);
                                    @endphp
                                    <tr>
                                        <td class="px-4 py-3 font-medium text-gray-800">
                                            <div>{{ $batch['reference'] !== '' ? $batch['reference'] : '—' }}</div>
                                            @if ($batch['reference'] !== $batch['application_batch_number'] && $batch['application_batch_number'] !== '')
                                                <div class="text-xs text-slate-400 mt-1">App ref: {{ $batch['application_batch_number'] }}</div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2">{{ $fmt((float) $batch['planned_quantity']) }}</td>
                                        <td class="px-3 py-2">{{ $batch['production_date'] ?? '-' }}</td>
                                        <td class="px-3 py-2">
                                            <span style="display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;border:1px solid {{ $batchStatusStyles['border'] }};background:{{ $batchStatusStyles['bg'] }};color:{{ $batchStatusStyles['color'] }};font-size:13px;font-weight:700;">
                                                <span style="height:8px;width:8px;border-radius:999px;background:{{ $batchStatusStyles['dot'] }};display:inline-block;"></span>
                                                {{ $batchStatusLabel }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-2">
                                            @php
                                                $allocState = (string) ($batch['allocation_state'] ?? 'awaiting');
                                                $allocStyles = match ($allocState) {
                                                    'allocated' => ['bg' => '#dcfce7', 'border' => '#86efac', 'color' => '#166534', 'dot' => '#22c55e', 'label' => 'Allocated'],
                                                    'partial' => ['bg' => '#dbeafe', 'border' => '#93c5fd', 'color' => '#1e40af', 'dot' => '#3b82f6', 'label' => 'Partially Allocated'],
                                                    default => ['bg' => '#fef9c3', 'border' => '#fde68a', 'color' => '#92400e', 'dot' => '#f59e0b', 'label' => 'Awaiting Allocation'],
                                                };
                                                $allocatedKg = (float) ($batch['allocated_kg'] ?? 0);
                                                $plannedKg = (float) ($batch['planned_quantity'] ?? 0);
                                            @endphp
                                            <a href="{{ route('manufacturing-orders.pallecons', ['winmanMo' => $winmanMo]) }}" wire:navigate
                                               title="{{ $fmt($allocatedKg) }} of {{ $fmt($plannedKg) }} kg allocated to pallecons"
                                               style="display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;border:1px solid {{ $allocStyles['border'] }};background:{{ $allocStyles['bg'] }};color:{{ $allocStyles['color'] }};font-size:13px;font-weight:700;text-decoration:none;">
                                                <span style="height:8px;width:8px;border-radius:999px;background:{{ $allocStyles['dot'] }};display:inline-block;"></span>
                                                {{ $allocStyles['label'] }}
                                            </a>
                                        </td>
                                        <td class="px-3 py-2 text-right">
                                            <a href="{{ route('batches.show', ['batch' => (int) $batch['id'], 'tab' => 'allocation']) }}" wire:navigate style="display:inline-flex;align-items:center;padding:8px 14px;border-radius:10px;background:#eef2ff;border:1px solid #c7d2fe;color:#4338ca;font-size:13px;font-weight:700;text-decoration:none;">Continue</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        </div>
                    </div>
                @else
                    <div style="background:#fff;border:1px solid #dbe1ea;border-radius:16px;overflow:hidden;box-shadow:0 1px 2px rgba(15,23,42,0.05);">
                        <div style="padding:14px 22px;">
                            <div style="display:flex;align-items:stretch;gap:0;flex-wrap:wrap;">
                                <div style="padding:0 26px 0 0;min-width:220px;border-right:1px solid #dbe1ea;">
                                    <div style="font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#64748b;font-weight:700;">Reference</div>
                                    <div style="margin-top:8px;font-size:28px;line-height:1.05;font-weight:800;color:#0f172a;">Not Created</div>
                                </div>

                                <div style="padding:0 26px;min-width:190px;border-right:1px solid #dbe1ea;">
                                    <div style="font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#64748b;font-weight:700;">Batch Qty</div>
                                    <div style="margin-top:8px;font-size:28px;line-height:1.05;font-weight:800;color:#0f172a;">{{ $batchPlannedQuantity !== '' ? $batchPlannedQuantity : '0' }}</div>
                                </div>

                                <div style="padding:0 26px;min-width:220px;border-right:1px solid #dbe1ea;display:flex;flex-direction:column;justify-content:center;">
                                    <div style="font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#64748b;font-weight:700;">Status</div>
                                    <span style="margin-top:10px;display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:999px;border:1px solid #bae6fd;background:#f0f9ff;color:#0369a1;font-size:14px;font-weight:700;width:max-content;">
                                        <span style="height:8px;width:8px;border-radius:999px;background:#0ea5e9;display:inline-block;"></span>
                                        Awaiting First Batch
                                    </span>
                                </div>

                                <div style="padding:0 0 0 26px;margin-left:auto;display:flex;align-items:center;color:#64748b;font-size:14px;font-weight:600;">
                                    No batches exist yet for this MO.
                                </div>
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
                    <div class="flex flex-wrap items-end gap-3">
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
                        @else
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">Batch quantity (kg)</label>
                                <input wire:model="batchPlannedQuantity" type="text" readonly class="border-gray-300 bg-gray-100 rounded-md shadow-sm text-sm w-40" />
                                <span class="block text-xs text-gray-500 mt-1">Auto from recipe card batch size.</span>
                            </div>
                        @endif

                        <x-primary-button wire:click="start" wire:loading.attr="disabled">
                            {{ count($existingBatches) === 0 ? 'Add batch' : 'Add another batch' }}
                        </x-primary-button>
                    </div>
                @elseif (count($existingBatches) > 0)
                    <div class="text-xs text-slate-500">Complete the in-progress batch before adding another one to this MO.</div>
                @endif
            </div>

            <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
                <div style="padding:14px 18px;border-bottom:1px solid #dbe1ea;background:linear-gradient(180deg,#f8fafc 0%,#f1f5f9 100%);">
                    <div class="text-base font-semibold text-gray-800">Pallecon Workspace</div>
                </div>
                <div class="p-6 space-y-6">
                    @if ($palleconError)
                        <div class="text-sm bg-red-50 border border-red-200 rounded px-3 py-2 text-red-700">{{ $palleconError }}</div>
                    @endif
                    @if ($palleconStatus)
                        <div class="text-sm bg-green-50 border border-green-200 rounded px-3 py-2 text-green-700">{{ $palleconStatus }}</div>
                    @endif

                    @php
                        $palleconStatusStyle = fn (string $s): array => match ($s) {
                            'filling' => ['bg' => '#fef9c3', 'border' => '#fde68a', 'color' => '#92400e', 'dot' => '#f59e0b'],
                            'open' => ['bg' => '#eff6ff', 'border' => '#bfdbfe', 'color' => '#1e40af', 'dot' => '#3b82f6'],
                            'sealed' => ['bg' => '#dcfce7', 'border' => '#86efac', 'color' => '#166534', 'dot' => '#22c55e'],
                            'consumed' => ['bg' => '#f1f5f9', 'border' => '#cbd5e1', 'color' => '#334155', 'dot' => '#64748b'],
                            'on_hold' => ['bg' => '#fee2e2', 'border' => '#fca5a5', 'color' => '#991b1b', 'dot' => '#ef4444'],
                            default => ['bg' => '#f3f4f6', 'border' => '#d1d5db', 'color' => '#4b5563', 'dot' => '#6b7280'],
                        };
                    @endphp

                    @if (count($this->moPallecons) > 0)
                        <div style="background:#fff;border:1px solid #dbe1ea;border-radius:16px;overflow:hidden;box-shadow:0 1px 2px rgba(15,23,42,0.05);">
                            <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="text-left text-xs text-slate-500 uppercase bg-slate-50">
                                    <tr>
                                        <th class="px-4 py-3">Reference</th>
                                        <th class="px-3 py-2">Qty (kg)</th>
                                        <th class="px-3 py-2">Date</th>
                                        <th class="px-3 py-2">Status</th>
                                        <th class="px-3 py-2 text-right"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($this->moPallecons as $pallecon)
                                        @php
                                            $pStyle = $palleconStatusStyle($pallecon['on_hold'] ? 'on_hold' : $pallecon['status']);
                                            $qty = $pallecon['final_weight'] ?? $pallecon['filled_kg'];
                                        @endphp
                                        <tr>
                                            <td class="px-4 py-3 font-medium text-gray-800">{{ $pallecon['reference'] }}</td>
                                            <td class="px-3 py-2">{{ rtrim(rtrim(number_format((float) $qty, 3, '.', ''), '0'), '.') ?: '0' }}</td>
                                            <td class="px-3 py-2">{{ $pallecon['opened_date'] ?? '-' }}</td>
                                            <td class="px-3 py-2">
                                                <span style="display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;border:1px solid {{ $pStyle['border'] }};background:{{ $pStyle['bg'] }};color:{{ $pStyle['color'] }};font-size:13px;font-weight:700;">
                                                    <span style="height:8px;width:8px;border-radius:999px;background:{{ $pStyle['dot'] }};display:inline-block;"></span>
                                                    {{ $pallecon['on_hold'] ? 'On Hold' : \Illuminate\Support\Str::headline($pallecon['status']) }}
                                                </span>
                                            </td>
                                            <td class="px-3 py-2 text-right">
                                                <a href="{{ route('manufacturing-orders.pallecons', ['winmanMo' => $winmanMo, 'pallecon' => $pallecon['id']]) }}" wire:navigate style="display:inline-flex;align-items:center;padding:8px 14px;border-radius:10px;background:#eef2ff;border:1px solid #c7d2fe;color:#4338ca;font-size:13px;font-weight:700;text-decoration:none;">Continue</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            </div>
                        </div>
                    @endif

                    @php
                        $signedOffFillBatches = collect($this->palleconFillBatches)->where('signoff_complete', true)->values();
                    @endphp

                    @if ($this->hasOpenPalleconForMo)
                        <div class="text-xs text-slate-500">Complete the open pallecon before creating another one for this MO.</div>
                    @elseif ($signedOffFillBatches->isEmpty())
                        <div class="text-xs text-slate-500">No signed-off batch is available to fill a pallecon yet.</div>
                    @else
                        <div class="flex flex-wrap items-end gap-3">
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">Pallecon number</label>
                                <input type="text" wire:model="palleconNumber" class="border-gray-300 rounded-md shadow-sm text-sm w-44" placeholder="e.g. PAL-00123" />
                            </div>
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">Source batch</label>
                                <select wire:model="palleconBatchId" class="border-gray-300 rounded-md shadow-sm text-sm w-56">
                                    <option value="">- select batch -</option>
                                    @foreach ($signedOffFillBatches as $b)
                                        <option value="{{ $b['id'] }}">{{ $b['batch_number'] }} ({{ rtrim(rtrim(number_format($b['remaining_kg'], 3, '.', ''), '0'), '.') ?: '0' }} kg left)</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">Fill weight (kg)</label>
                                <input type="number" step="0.001" min="0.001" wire:model="palleconFillWeight" class="border-gray-300 rounded-md shadow-sm text-sm w-32" placeholder="e.g. 400" />
                            </div>
                            <x-primary-button wire:click="createPallecon" wire:loading.attr="disabled">
                                {{ count($this->moPallecons) === 0 ? 'Add pallecon' : 'Add another pallecon' }}
                            </x-primary-button>
                        </div>
                        <p class="text-xs text-slate-500">Creates the pallecon and records the first fill. Use Continue on a row to add more fills, seals and complete it.</p>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
