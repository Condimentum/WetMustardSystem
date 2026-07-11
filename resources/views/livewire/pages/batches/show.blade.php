<?php

use App\Domains\Batch\Exceptions\BatchException;
use App\Domains\Batch\Jobs\ValidateBatchCompletionJob;
use App\Features\Batches\ApproveBatchQaFeature;
use App\Features\Batches\CompleteBatchFeature;
use App\Features\Batches\RejectBatchQaFeature;
use App\Features\Batches\GetAvailableIngredientLotsFeature;
use App\Features\Booking\BookFinishedGoodsFeature;
use App\Domains\WinMan\Exceptions\WinManException;
use App\Domains\WinMan\Jobs\ListIssuedLotsForWorkInProgressJob;
use App\Operations\AllocateBomIngredientOperation;
use App\Models\BatchRecord;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Batch Record')] class extends Component {
    public BatchRecord $batch;

    /** @var array<int, string> */
    public array $completionIssues = [];

    public ?string $activeBomMaterialCode = null;

    public ?int $activeBomComponentSnapshotId = null;

    public ?string $activeBomMaterialDescription = null;

    /** @var array<int, array{lot_number:string,quantity_outstanding:float}> */
    public array $activeBomLotOptions = [];

    public ?string $activeBomLotNumber = null;

    public string $activeBomActualQty = '';

    public ?string $activeBomMessage = null;

    /** @var array<int, array{lot_number:string,quantity:float,last_effective_date:?string}> */
    public array $activeBomHistoricalLots = [];

    public bool $showAllocateModal = false;

    public string $rejectReason = '';

    public string $bookQuantityKg = '';

    public string $bookLotNumber = '';

    public ?string $bookFlash = null;

    public ?string $moUnitOfMeasureDescription = null;

    public function mount(BatchRecord $batch): void
    {
        $this->batch = $batch;
        $this->reload();
    }

    public function openBomAllocation(int $componentSnapshotId, string $materialCode, string $materialDescription, ?string $suggestedQty = null): void
    {
        $this->activeBomComponentSnapshotId = $componentSnapshotId;
        $this->activeBomMaterialCode = trim($materialCode);
        $this->activeBomMaterialDescription = trim($materialDescription);
        $this->activeBomLotNumber = null;
        $this->activeBomActualQty = $suggestedQty !== null ? trim($suggestedQty) : '';
        $this->activeBomMessage = null;

        $this->loadActiveBomLotOptions();

        $component = $this->batch->componentSnapshots->firstWhere('id', $componentSnapshotId);
        if ($component !== null) {
            try {
                $this->activeBomHistoricalLots = app(ListIssuedLotsForWorkInProgressJob::class)(
                    (int) ($component->winman_work_in_progress ?? 0),
                    (int) ($component->winman_component_product ?? 0),
                    20,
                );
            } catch (\Throwable $e) {
                report($e);
                $this->activeBomHistoricalLots = [];
            }
        } else {
            $this->activeBomHistoricalLots = [];
        }

        if ($this->activeBomLotNumber === null && count($this->activeBomLotOptions) > 0) {
            $this->activeBomLotNumber = $this->activeBomLotOptions[0]['lot_number'];
        }
    }

    public function toggleBomAllocationRow(int $componentSnapshotId, string $materialCode, string $materialDescription, ?string $suggestedQty = null): void
    {
        if ($this->activeBomComponentSnapshotId === $componentSnapshotId) {
            $this->cancelBomAllocation();

            return;
        }

        $this->openBomAllocation($componentSnapshotId, $materialCode, $materialDescription, $suggestedQty);
    }

    public function openAllocateModal(int $componentSnapshotId, string $materialCode, string $materialDescription, ?string $suggestedQty = null): void
    {
        $this->openBomAllocation($componentSnapshotId, $materialCode, $materialDescription, $suggestedQty);
        $this->showAllocateModal = true;
    }

    public function closeAllocateModal(): void
    {
        $this->showAllocateModal = false;
    }

    public function cancelBomAllocation(): void
    {
        $this->showAllocateModal = false;
        $this->activeBomComponentSnapshotId = null;
        $this->activeBomMaterialCode = null;
        $this->activeBomMaterialDescription = null;
        $this->activeBomLotOptions = [];
        $this->activeBomLotNumber = null;
        $this->activeBomActualQty = '';
        $this->activeBomMessage = null;
        $this->activeBomHistoricalLots = [];
    }

    public function allocateBomIngredient(): void
    {
        $this->authorizeEditable();

        $this->activeBomMessage = null;

        $validated = $this->validate([
            'activeBomComponentSnapshotId' => ['required', 'integer'],
            'activeBomMaterialCode' => ['required', 'string', 'max:255'],
            'activeBomMaterialDescription' => ['required', 'string', 'max:255'],
            'activeBomLotNumber' => ['required', 'string', 'max:255'],
            'activeBomActualQty' => ['required', 'numeric', 'min:0.001'],
        ]);

        $component = $this->batch->componentSnapshots
            ->firstWhere('id', (int) $validated['activeBomComponentSnapshotId']);

        if ($component === null) {
            $this->activeBomMessage = 'Could not find the selected BOM line. Please refresh and try again.';

            return;
        }

        try {
            app(AllocateBomIngredientOperation::class)(
                $this->batch,
                $component,
                (string) $validated['activeBomLotNumber'],
                (float) $validated['activeBomActualQty'],
                auth()->user(),
            );
        } catch (WinManException $e) {
            $this->activeBomMessage = $e->getMessage();

            return;
        } catch (\Throwable $e) {
            report($e);
            $this->activeBomMessage = 'Could not allocate and issue this ingredient right now. Please try again.';

            return;
        }

        $this->reload();
        $this->cancelBomAllocation();
        $this->dispatch('switch-batch-tab', tab: 'allocation');
        session()->flash('status', 'Ingredient lot allocated and issued in WinMan.');
    }

    public function getEditableProperty(): bool
    {
        return $this->batch->status === BatchRecord::STATUS_IN_PROGRESS;
    }

    public function getBookingEnabledProperty(): bool
    {
        return (bool) config('winman.booking.enabled');
    }

    public function getPackingModeProperty(): string
    {
        $uomCode = (int) ($this->batch->manufacturingOrder?->winman_unit_of_measure ?? 0);
        $uom = strtoupper(trim((string) $this->moUnitOfMeasureDescription));

        if ($uom !== '' && str_contains($uom, 'IBC')) {
            return 'ibc';
        }

        if ($uomCode === 44 || ($uom !== '' && str_contains($uom, 'BUCKET'))) {
            return 'bucket';
        }

        if ($uomCode === 2 || ($uom !== '' && str_contains($uom, 'PALLECON'))) {
            return 'pallecon';
        }

        return 'bucket';
    }

    public function getPackingLabelProperty(): string
    {
        return match ($this->packingMode) {
            'pallecon' => 'Pallecon Packing',
            'ibc' => 'IBC Packing',
            default => 'Bucketing',
        };
    }

    public function getPackingRouteProperty(): string
    {
        return $this->packingMode === 'pallecon' ? 'batches.pallecons' : 'batches.packing';
    }

    public function getMoPlannedQuantityProperty(): float
    {
        return (float) ($this->batch->manufacturingOrder?->planned_quantity ?? 0);
    }

    public function getMoBatchCountProperty(): int
    {
        $moId = (int) ($this->batch->manufacturing_order_id ?? 0);

        if ($moId <= 0) {
            return 0;
        }

        return BatchRecord::query()
            ->where('manufacturing_order_id', $moId)
            ->count();
    }

    public function getMoAllocatedQuantityProperty(): float
    {
        $moId = (int) ($this->batch->manufacturing_order_id ?? 0);

        if ($moId <= 0) {
            return 0.0;
        }

        return (float) BatchRecord::query()
            ->where('manufacturing_order_id', $moId)
            ->sum('planned_quantity');
    }

    public function getMoRemainingQuantityProperty(): float
    {
        $remaining = $this->moPlannedQuantity - $this->moAllocatedQuantity;

        return $remaining > 0 ? $remaining : 0.0;
    }

    public function getCanAddBatchProperty(): bool
    {
        $classification = (int) ($this->batch->manufacturingOrder?->winman_classification ?? 0);
        $winmanMo = (int) ($this->batch->manufacturingOrder?->winman_manufacturing_order ?? 0);

        return $classification === 30 && $winmanMo > 0;
    }

    public function getAddBatchUrlProperty(): ?string
    {
        if (! $this->canAddBatch) {
            return null;
        }

        return route('manufacturing-orders.show', [
            'winmanMo' => (int) $this->batch->manufacturingOrder->winman_manufacturing_order,
        ]);
    }

    public function getMoIsOverAllocatedProperty(): bool
    {
        return $this->moAllocatedQuantity > ($this->moPlannedQuantity + 0.0001);
    }

    public function formatQty(float $value, int $precision = 3): string
    {
        $rounded = round($value, $precision);

        if (abs($rounded) < 0.000001) {
            return '0';
        }

        return rtrim(rtrim((string) $rounded, '0'), '.');
    }

    public function getBatchScaleRatioProperty(): float
    {
        $batchPlanned = (float) ($this->batch->planned_quantity ?? 0);
        $moPlanned = (float) ($this->batch->manufacturingOrder?->planned_quantity ?? 0);

        if ($batchPlanned <= 0 || $moPlanned <= 0) {
            return 1.0;
        }

        return $batchPlanned / $moPlanned;
    }

    public function getDerivedLotNumberProperty(): string
    {
        $moRefRaw = (string) ($this->batch->manufacturingOrder?->winman_manufacturing_order_id
            ?? $this->batch->manufacturingOrder?->mo_number
            ?? $this->batch->batch_number);
        $moRef = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $moRefRaw));

        if ($moRef === '') {
            $moRef = 'BATCH';
        }

        $datePart = $this->batch->production_date?->format('Ymd') ?? now()->format('Ymd');

        return $moRef.'-'.$datePart;
    }

    public function complete(): void
    {
        $this->authorizeEditable();

        try {
            app(CompleteBatchFeature::class)($this->batch, auth()->user());
        } catch (BatchException $e) {
            $this->completionIssues = $e->issues;

            return;
        }

        $this->reload();
        session()->flash('status', 'Batch completed.');
    }

    public function approve(): void
    {
        if ($this->batch->status !== BatchRecord::STATUS_COMPLETED) {
            return;
        }

        app(ApproveBatchQaFeature::class)($this->batch, auth()->user());
        $this->reload();
        session()->flash('status', 'Batch approved and closed by QA.');
    }

    public function reject(): void
    {
        if ($this->batch->status !== BatchRecord::STATUS_COMPLETED) {
            return;
        }

        $validated = $this->validate(['rejectReason' => ['required', 'string', 'max:500']]);

        app(RejectBatchQaFeature::class)($this->batch, auth()->user(), $validated['rejectReason']);
        $this->rejectReason = '';
        $this->reload();
        session()->flash('status', 'Batch returned to production for correction.');
    }

    public function book(): void
    {
        if (! $this->bookingEnabled) {
            return;
        }

        $data = $this->validate([
            'bookQuantityKg' => ['required', 'numeric', 'min:0.001'],
            'bookLotNumber' => ['required', 'string', 'max:100'],
        ]);

        $shelfDays = $this->batch->product?->shelf_life_days ?? 180;
        $finished = now();
        $expiry = now()->addDays($shelfDays)->endOfMonth();

        try {
            $log = app(BookFinishedGoodsFeature::class)(
                $this->batch,
                (float) $data['bookQuantityKg'],
                $data['bookLotNumber'],
                [$data['bookLotNumber']],
                $finished,
                $expiry,
                auth()->user(),
            );
        } catch (WinManException $e) {
            $this->bookFlash = $e->getMessage();

            return;
        }

        $this->bookFlash = $log->booking_status === 'success'
            ? "Booked to WinMan (Inventory {$log->winman_inventory_id})."
            : "Booking {$log->booking_status}: {$log->error_message}";
        $this->reload();
    }

    private function authorizeEditable(): void
    {
        abort_unless($this->editable, 403, 'This batch is no longer editable.');
    }

    private function reload(): void
    {
        $this->batch = $this->batch->fresh([
            'manufacturingOrder',
            'product',
            'variant',
            'componentSnapshots',
            'ingredientLots.weighedBy',
            'ingredientLots.tippedBy',
            'pallecons',
            'bookingLogs',
            'issueLogs',
        ]);

        $this->loadMoUnitOfMeasureDescription();

        if (trim($this->bookLotNumber) === '') {
            $this->bookLotNumber = $this->derivedLotNumber;
        }

        if (trim($this->bookQuantityKg) === '') {
            $defaultQty = (float) ($this->batch->planned_quantity ?? 0);
            if ($defaultQty > 0) {
                $this->bookQuantityKg = rtrim(rtrim((string) $defaultQty, '0'), '.');
            }
        }

        $this->completionIssues = app(ValidateBatchCompletionJob::class)($this->batch);
    }

    private function loadMoUnitOfMeasureDescription(): void
    {
        $this->moUnitOfMeasureDescription = $this->batch->manufacturingOrder?->winman_unit_of_measure_description;

        if (filled($this->moUnitOfMeasureDescription)) {
            return;
        }

        $internalProduct = (int) ($this->batch->manufacturingOrder?->winman_product_internal ?? 0);

        if ($internalProduct <= 0) {
            return;
        }

        try {
            $row = DB::connection('winman')->selectOne(
                'SELECT p.UnitOfMeasure, u.UnitOfMeasureDescription
                 FROM Products p
                 LEFT JOIN UnitsOfMeasure u ON u.UnitOfMeasure = p.UnitOfMeasure
                 WHERE p.Product = ?',
                [$internalProduct],
            );

            if ($row !== null) {
                $uomCode = isset($row->UnitOfMeasure) ? (int) $row->UnitOfMeasure : null;
                $description = isset($row->UnitOfMeasureDescription)
                    ? trim((string) $row->UnitOfMeasureDescription)
                    : '';

                $this->moUnitOfMeasureDescription = $description !== '' ? $description : null;

                if ($this->batch->manufacturingOrder !== null) {
                    $this->batch->manufacturingOrder->update([
                        'winman_unit_of_measure' => $uomCode,
                        'winman_unit_of_measure_description' => $this->moUnitOfMeasureDescription,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function loadActiveBomLotOptions(): void
    {
        $materialCode = trim((string) $this->activeBomMaterialCode);

        if ($materialCode === '') {
            $this->activeBomLotOptions = [];
            $this->activeBomMessage = 'No material selected.';

            return;
        }

        try {
            $this->activeBomLotOptions = app(GetAvailableIngredientLotsFeature::class)($materialCode, 100);
            if ($this->activeBomLotNumber === null && count($this->activeBomLotOptions) > 0) {
                $this->activeBomLotNumber = $this->activeBomLotOptions[0]['lot_number'];
            }
            $this->activeBomMessage = $this->activeBomLotOptions === []
                ? 'No available WinMan lots found for this BOM material.'
                : null;
        } catch (\Throwable $e) {
            report($e);
            $this->activeBomLotOptions = [];
            $this->activeBomMessage = 'Unable to load WinMan lots right now.';
        }
    }
}; ?>

<div class="py-8">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6" x-data="{ tab: @js(in_array((string) request()->query('tab', 'batch'), ['batch', 'allocation', 'review'], true) ? (string) request()->query('tab', 'batch') : 'batch') }" x-on:switch-batch-tab.window="tab = $event.detail.tab">

        @if (session('status'))
            <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-lg px-4 py-3">
                {{ session('status') }}
            </div>
        @endif

        {{-- Header --}}
        <div class="bg-white shadow-sm rounded-lg p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="text-sm text-gray-500">Batch Number</div>
                    <div class="text-2xl font-semibold text-gray-800">{{ $batch->batch_number }}</div>
                    <div class="mt-1 text-sm text-gray-600">
                        {{ $batch->product?->product_name ?? $batch->manufacturingOrder?->winman_product_id }}
                        @if ($batch->variant)
                            <span class="text-gray-400">·</span> {{ $batch->variant->variant_name }}
                        @endif
                    </div>
                </div>
                <div class="text-right">
                    <span @class([
                        'inline-flex px-3 py-1 rounded-full text-xs font-medium',
                        'bg-amber-100 text-amber-800' => $batch->status === BatchRecord::STATUS_IN_PROGRESS,
                        'bg-blue-100 text-blue-800' => $batch->status === BatchRecord::STATUS_COMPLETED,
                        'bg-purple-100 text-purple-800' => $batch->status === BatchRecord::STATUS_QA_REVIEW,
                        'bg-gray-200 text-gray-700' => $batch->status === BatchRecord::STATUS_CLOSED,
                    ])>
                        {{ \Illuminate\Support\Str::headline($batch->status) }}
                    </span>
                    <a href="{{ route('manufacturing-orders.search') }}" wire:navigate
                        class="block mt-3 text-sm text-indigo-600 hover:underline">← MO Search</a>
                </div>
            </div>

            <dl class="mt-6 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <div><dt class="text-gray-500">MO Reference</dt><dd class="font-medium text-gray-800">{{ $batch->manufacturingOrder?->mo_number ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">Recipe Code</dt><dd class="font-medium text-gray-800">{{ $batch->manufacturingOrder?->recipe_code ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">Batch Qty</dt><dd class="font-medium text-gray-800">{{ $batch->planned_quantity ? rtrim(rtrim((string) $batch->planned_quantity, '0'), '.') : '—' }}</dd></div>
                <div><dt class="text-gray-500">Production Date</dt><dd class="font-medium text-gray-800">{{ $batch->production_date?->toFormattedDateString() }}</dd></div>
            </dl>

            <dl class="mt-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <div><dt class="text-gray-500">MO Qty</dt><dd class="font-medium text-gray-800">{{ $this->formatQty($this->moPlannedQuantity) }}</dd></div>
                <div><dt class="text-gray-500">Batches</dt><dd class="font-medium text-gray-800">{{ $this->moBatchCount }}</dd></div>
                <div><dt class="text-gray-500">Allocated to Batches</dt><dd class="font-medium text-gray-800">{{ $this->formatQty($this->moAllocatedQuantity) }}</dd></div>
                <div><dt class="text-gray-500">Remaining</dt><dd class="font-medium text-gray-800">{{ $this->formatQty($this->moRemainingQuantity) }}</dd></div>
            </dl>

            <div class="mt-6 flex flex-wrap gap-3">
                <a href="{{ route('batches.export', $batch) }}" class="text-sm px-3 py-1.5 rounded-md bg-indigo-50 hover:bg-indigo-100 text-indigo-700">Export</a>
            </div>
        </div>

        @unless ($this->editable)
            <div class="bg-blue-50 border border-blue-200 text-blue-800 text-sm rounded-lg px-4 py-3">
                This batch is <strong>{{ \Illuminate\Support\Str::headline($batch->status) }}</strong> and is read-only.
            </div>
        @endunless

        {{-- Tabs --}}
        <div class="bg-white shadow-sm rounded-lg">
            <div class="border-b border-gray-200 px-4">
                <nav class="-mb-px flex flex-wrap gap-6 text-sm font-medium">
                    @foreach (['batch' => 'Batch', 'allocation' => 'Ingredient Allocation', 'packing' => $this->packingLabel, 'review' => 'Review & Complete'] as $key => $label)
                        @if ($key === 'packing')
                            <a href="{{ route($this->packingRoute, $batch) }}" wire:navigate
                                class="py-3 border-b-2 border-transparent text-gray-500 hover:text-gray-700">{{ $label }}</a>
                        @else
                            <button @click="tab = '{{ $key }}'"
                                :class="tab === '{{ $key }}' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                                class="py-3 border-b-2">{{ $label }}</button>
                        @endif
                    @endforeach
                </nav>
            </div>

            <div class="p-6">
                {{-- Batch --}}
                <div x-show="tab === 'batch'" class="space-y-4">
                    <div class="text-sm text-gray-600">Allocate this MO into one or more batches, then proceed to Ingredient Allocation for this batch.</div>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div class="rounded-lg border border-gray-200 p-3">
                            <div class="text-xs text-gray-500 uppercase tracking-wide">MO Qty</div>
                            <div class="mt-1 text-lg font-semibold text-gray-800">{{ $this->formatQty($this->moPlannedQuantity) }}</div>
                        </div>
                        <div class="rounded-lg border border-gray-200 p-3">
                            <div class="text-xs text-gray-500 uppercase tracking-wide">Batches</div>
                            <div class="mt-1 text-lg font-semibold text-gray-800">{{ $this->moBatchCount }}</div>
                        </div>
                        <div class="rounded-lg border border-gray-200 p-3">
                            <div class="text-xs text-gray-500 uppercase tracking-wide">Allocated</div>
                            <div class="mt-1 text-lg font-semibold text-gray-800">{{ $this->formatQty($this->moAllocatedQuantity) }}</div>
                        </div>
                        <div class="rounded-lg border border-gray-200 p-3">
                            <div class="text-xs text-gray-500 uppercase tracking-wide">Remaining</div>
                            <div class="mt-1 text-lg font-semibold text-gray-800">{{ $this->formatQty($this->moRemainingQuantity) }}</div>
                        </div>
                    </div>

                    @if ($this->moIsOverAllocated)
                        <div class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2">
                            Planned batch total is above MO quantity. Create additional batches only if this is intentional.
                        </div>
                    @endif

                    @if ($this->canAddBatch)
                        <div>
                            <a href="{{ $this->addBatchUrl }}" wire:navigate class="inline-flex items-center px-3 py-2 rounded-md bg-indigo-600 text-white text-sm hover:bg-indigo-500">Add Batch</a>
                        </div>
                    @else
                        <div class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2">
                            Add Batch is unavailable because this MO is not linked as Intermediate classification 30.
                        </div>
                    @endif

                    <div>
                        <button @click="tab = 'allocation'" class="text-sm text-indigo-600 hover:underline">Continue to Ingredient Allocation →</button>
                    </div>
                </div>

                {{-- Unified Allocation Workspace --}}
                <div x-show="tab === 'allocation'" class="space-y-4">
                    <div class="text-sm text-gray-600">Allocate against each WinMan BOM line. Allocations are posted to WinMan immediately and recorded locally.</div>
                    <div class="text-xs text-gray-500">Tip: click any BOM row to open allocation details and see allocated lot numbers.</div>
                    <div class="text-xs text-gray-500">Batch BOM quantities are pro-rated to this batch size ({{ rtrim(rtrim((string) round($this->batchScaleRatio * 100, 2), '0'), '.') }}% of MO quantity).</div>
                    <div>
                        <a href="{{ route($this->packingRoute, $batch) }}" wire:navigate class="text-sm text-indigo-600 hover:underline">Ingredients issued? Continue to {{ $this->packingLabel }} →</a>
                    </div>

                    @if ($batch->componentSnapshots->isEmpty())
                        <div class="text-sm text-gray-500">No component snapshot stored for this MO.</div>
                    @else
                        <div class="border border-gray-200 rounded-lg overflow-hidden">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="text-left text-xs text-gray-500 uppercase bg-gray-50">
                                    <tr>
                                        <th class="px-3 py-2">Type</th>
                                        <th class="px-3 py-2">Product</th>
                                        <th class="px-3 py-2">Description</th>
                                        <th class="px-3 py-2 text-right">Outstanding</th>
                                        <th class="px-3 py-2 text-right">Allocated</th>
                                        <th class="px-3 py-2 text-right"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($batch->componentSnapshots as $bomLine)
                                        @php
                                            $componentCode = (string) $bomLine->winman_component_product_id;
                                            $componentCodeNorm = strtoupper(trim($componentCode));
                                            $componentDescNorm = strtoupper(trim((string) $bomLine->component_description));
                                            $componentWip = (int) ($bomLine->winman_work_in_progress ?? 0);
                                            $ingredientLotsById = $batch->ingredientLots->keyBy('id');

                                            $componentIssueLogs = $batch->issueLogs->filter(function ($log) use ($componentCodeNorm, $componentWip): bool {
                                                if ((string) $log->issue_status !== 'success') {
                                                    return false;
                                                }

                                                $logCodeNorm = strtoupper(trim((string) ($log->material_code ?? '')));
                                                $logWip = (int) ($log->winman_work_in_progress ?? 0);

                                                if ($componentWip > 0 && $logWip > 0 && $logWip === $componentWip) {
                                                    return true;
                                                }

                                                return $componentCodeNorm !== '' && $logCodeNorm === $componentCodeNorm;
                                            });

                                            $allocatedLotIds = $componentIssueLogs
                                                ->pluck('batch_ingredient_lot_id')
                                                ->filter()
                                                ->map(fn ($id): int => (int) $id)
                                                ->values()
                                                ->all();

                                            $allocatedLots = ! empty($allocatedLotIds)
                                                ? $batch->ingredientLots->whereIn('id', $allocatedLotIds)
                                                : $batch->ingredientLots->filter(fn ($lot): bool =>
                                                    strtoupper(trim((string) ($lot->material_code ?? ''))) === $componentCodeNorm
                                                    || (trim((string) ($lot->material_code ?? '')) === ''
                                                        && strtoupper(trim((string) ($lot->material_description ?? ''))) === $componentDescNorm)
                                                );

                                            $allocatedPreviewRows = $componentIssueLogs->map(function ($log) use ($ingredientLotsById): array {
                                                $lot = $ingredientLotsById->get((int) ($log->batch_ingredient_lot_id ?? 0));

                                                return [
                                                    'lot_number' => (string) ($log->lot_number ?? ($lot?->lot_number ?? '—')),
                                                    'quantity' => (float) ($log->quantity_issued ?? ($lot?->actual_quantity ?? 0)),
                                                    'uom' => (string) ($lot?->uom ?? 'kg'),
                                                    'issue_status' => (string) ($log->issue_status ?? ''),
                                                    'error_message' => (string) ($log->error_message ?? ''),
                                                    'weighed_by' => $lot?->weighedBy?->name,
                                                    'tipped_by' => $lot?->tippedBy?->name,
                                                ];
                                            })->values();

                                            if ($allocatedPreviewRows->isEmpty()) {
                                                $allocatedPreviewRows = $allocatedLots->map(function ($lot) use ($batch): array {
                                                    $issueLog = $batch->issueLogs
                                                        ->where('batch_ingredient_lot_id', $lot->id)
                                                        ->sortByDesc('id')
                                                        ->first();

                                                    return [
                                                        'lot_number' => (string) ($lot->lot_number ?? '—'),
                                                        'quantity' => (float) ($lot->actual_quantity ?? 0),
                                                        'uom' => (string) ($lot->uom ?? 'kg'),
                                                        'issue_status' => (string) ($issueLog?->issue_status ?? ''),
                                                        'error_message' => (string) ($issueLog?->error_message ?? ''),
                                                        'weighed_by' => $lot->weighedBy?->name,
                                                        'tipped_by' => $lot->tippedBy?->name,
                                                    ];
                                                })->values();
                                            }

                                            $allocatedQty = (float) $allocatedPreviewRows->sum('quantity');
                                            $snapshotIssuedQty = abs((float) ($bomLine->quantity_issued ?? 0));
                                            if ($allocatedQty <= 0 && $snapshotIssuedQty > 0) {
                                                $allocatedQty = $snapshotIssuedQty;
                                            }

                                            $allocatedQtyDisplay = rtrim(rtrim((string) $allocatedQty, '0'), '.');
                                            if ($allocatedQtyDisplay === '') {
                                                $allocatedQtyDisplay = '0';
                                            }

                                            $requiredForBatch = round(abs((float) ($bomLine->quantity ?? 0)) * $this->batchScaleRatio, 3);
                                            $outstandingForBatch = max($requiredForBatch - $allocatedQty, 0.0);
                                            $outstandingForBatchDisplay = rtrim(rtrim((string) round($outstandingForBatch, 3), '0'), '.');
                                            if ($outstandingForBatchDisplay === '') {
                                                $outstandingForBatchDisplay = '0';
                                            }

                                            $allocatedLotCount = $allocatedPreviewRows->count();
                                        @endphp
                                        <tr
                                            wire:click="toggleBomAllocationRow({{ $bomLine->id }}, @js($componentCode), @js((string) $bomLine->component_description), @js($outstandingForBatchDisplay))"
                                            class="cursor-pointer hover:bg-gray-50">
                                            <td class="px-3 py-2">{{ $bomLine->item_type }}</td>
                                            <td class="px-3 py-2 text-gray-500">{{ $componentCode }}</td>
                                            <td class="px-3 py-2">{{ $bomLine->component_description }}</td>
                                            <td class="px-3 py-2 text-right">{{ $outstandingForBatchDisplay }}</td>
                                            <td class="px-3 py-2 text-right">
                                                <div class="font-medium">{{ $allocatedQtyDisplay }}</div>
                                                @if ($allocatedLotCount > 0)
                                                    <div class="text-xs text-gray-500">{{ $allocatedLotCount }} lot{{ $allocatedLotCount === 1 ? '' : 's' }}</div>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 text-right">
                                                <button
                                                    wire:click.stop="openAllocateModal({{ $bomLine->id }}, @js($componentCode), @js((string) $bomLine->component_description), @js($outstandingForBatchDisplay))"
                                                    type="button"
                                                    class="text-indigo-600 hover:underline">
                                                    {{ strtoupper((string) $bomLine->item_type) === 'C' && $this->editable ? 'Allocate' : 'View' }}
                                                </button>
                                            </td>
                                        </tr>

                                        @if ($activeBomComponentSnapshotId === (int) $bomLine->id)
                                            <tr class="bg-indigo-50/60">
                                                <td colspan="6" class="px-3 py-3 space-y-3">
                                                    <div class="text-sm font-medium text-gray-800">{{ $activeBomMaterialCode }} - {{ $activeBomMaterialDescription }}</div>

                                                    @if ($activeBomMessage)
                                                        <div class="text-xs text-gray-600">{{ $activeBomMessage }}</div>
                                                    @endif

                                                    @php
                                                        $expandedRows = $allocatedPreviewRows;

                                                        if ($expandedRows->isEmpty() && count($activeBomHistoricalLots) > 0) {
                                                            $expandedRows = collect($activeBomHistoricalLots)->map(static fn (array $history): array => [
                                                                'lot_number' => (string) $history['lot_number'],
                                                                'quantity' => abs((float) $history['quantity']),
                                                                'uom' => 'kg',
                                                                'issue_status' => 'success',
                                                                'error_message' => '',
                                                                'weighed_by' => null,
                                                                'tipped_by' => null,
                                                            ]);
                                                        }
                                                    @endphp

                                                    <div class="border border-gray-200 rounded-lg overflow-hidden bg-white">
                                                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                                                            <thead class="text-left text-xs text-gray-500 uppercase bg-gray-50">
                                                                <tr>
                                                                    <th class="px-3 py-2">Allocated Lot</th>
                                                                    <th class="px-3 py-2 text-right">Qty</th>
                                                                    <th class="px-3 py-2">UOM</th>
                                                                    <th class="px-3 py-2">WinMan</th>
                                                                    <th class="px-3 py-2">Weighed</th>
                                                                    <th class="px-3 py-2">Tipped</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody class="divide-y divide-gray-100">
                                                                @forelse ($expandedRows as $row)
                                                                    <tr>
                                                                        <td class="px-3 py-2">{{ $row['lot_number'] }}</td>
                                                                        <td class="px-3 py-2 text-right">{{ rtrim(rtrim((string) $row['quantity'], '0'), '.') }}</td>
                                                                        <td class="px-3 py-2">{{ $row['uom'] }}</td>
                                                                        <td class="px-3 py-2">
                                                                            @if ($row['issue_status'] === 'success')
                                                                                <span class="text-green-700">Issued</span>
                                                                            @elseif ($row['issue_status'] === 'rejected')
                                                                                <span class="text-amber-700" title="{{ $row['error_message'] }}">Rejected</span>
                                                                            @elseif ($row['issue_status'] === 'failed')
                                                                                <span class="text-red-700" title="{{ $row['error_message'] }}">Failed</span>
                                                                            @else
                                                                                <span class="text-gray-500">-</span>
                                                                            @endif
                                                                        </td>
                                                                        <td class="px-3 py-2">{{ $row['weighed_by'] ?: '—' }}</td>
                                                                        <td class="px-3 py-2">{{ $row['tipped_by'] ?: '—' }}</td>
                                                                    </tr>
                                                                @empty
                                                                    <tr>
                                                                        <td colspan="6" class="px-3 py-4 text-center text-gray-500">No allocations recorded yet for this BOM line.</td>
                                                                    </tr>
                                                                @endforelse
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                @if ($showAllocateModal)
                    <div class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0">
                        <div class="fixed inset-0 bg-gray-500/70" wire:click="closeAllocateModal"></div>
                        <div class="relative mb-6 bg-white rounded-lg overflow-hidden shadow-xl transform transition-all sm:w-full sm:max-w-xl sm:mx-auto">
                            <div class="px-6 py-4 border-b border-gray-200">
                                <h3 class="text-lg font-semibold text-gray-900">Allocate</h3>
                                <p class="mt-1 text-sm text-gray-600">{{ $activeBomMaterialCode }} - {{ $activeBomMaterialDescription }}</p>
                            </div>

                            <div class="px-6 py-4 space-y-4">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs text-gray-600 mb-1">Lot number</label>
                                        <select wire:model="activeBomLotNumber" class="w-full border-gray-300 rounded-md shadow-sm text-sm">
                                            <option value="">- select lot -</option>
                                            @foreach ($activeBomLotOptions as $lot)
                                                <option value="{{ $lot['lot_number'] }}">{{ $lot['lot_number'] }} ({{ rtrim(rtrim((string) $lot['quantity_outstanding'], '0'), '.') }} available)</option>
                                            @endforeach
                                        </select>
                                        @error('activeBomLotNumber') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </div>

                                    <div>
                                        <label class="block text-xs text-gray-600 mb-1">Actual qty</label>
                                        <input wire:model="activeBomActualQty" type="number" step="0.001" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
                                        @error('activeBomActualQty') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </div>
                                </div>

                                @if ($activeBomMessage)
                                    <div class="text-sm text-red-600">{{ $activeBomMessage }}</div>
                                @endif
                            </div>

                            <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-2">
                                <x-secondary-button type="button" wire:click="closeAllocateModal">Cancel</x-secondary-button>
                                <x-primary-button type="button" wire:click="allocateBomIngredient" :disabled="count($activeBomLotOptions) === 0">Allocate</x-primary-button>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Review & Complete --}}
                <div x-show="tab === 'review'" x-cloak class="space-y-4">
                    @if (count($completionIssues) > 0)
                        <div class="bg-amber-50 border border-amber-200 rounded-lg p-4">
                            <div class="font-medium text-amber-900 mb-2">Outstanding before completion:</div>
                            <ul class="list-disc list-inside text-sm text-amber-800 space-y-1">
                                @foreach ($completionIssues as $issue)
                                    <li>{{ $issue }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @else
                        <div class="bg-green-50 border border-green-200 rounded-lg p-4 text-sm text-green-800">
                            All mandatory data is present. This batch is ready for completion.
                        </div>
                    @endif

                    @if ($this->editable)
                        <x-primary-button wire:click="complete" wire:loading.attr="disabled" :disabled="count($completionIssues) > 0">
                            Complete batch
                        </x-primary-button>
                    @endif

                    @if ($batch->status === BatchRecord::STATUS_COMPLETED)
                        <div class="border-t border-gray-200 pt-4 mt-4 space-y-3">
                            <div class="font-medium text-gray-800">QA Review</div>
                            <div class="flex flex-wrap items-end gap-3">
                                <x-primary-button wire:click="approve" wire:loading.attr="disabled">Approve &amp; close</x-primary-button>
                                <div>
                                    <input wire:model="rejectReason" placeholder="Reason for rejection" class="border-gray-300 rounded-md shadow-sm text-sm" />
                                    @error('rejectReason') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                                </div>
                                <x-secondary-button wire:click="reject" type="button">Reject to production</x-secondary-button>
                            </div>
                        </div>
                    @endif

                    @if ($this->bookingEnabled && in_array($batch->status, [BatchRecord::STATUS_COMPLETED, BatchRecord::STATUS_CLOSED]))
                        <div class="border-t border-gray-200 pt-4 mt-4 space-y-3">
                            <div class="font-medium text-gray-800">WinMan Finished-Goods Booking</div>
                            @if ($bookFlash)
                                <div class="text-sm text-indigo-700">{{ $bookFlash }}</div>
                            @endif
                            @if ($batch->bookingLogs->where('booking_status', 'success')->isNotEmpty())
                                <div class="text-sm text-green-700">Already booked (Inventory {{ $batch->bookingLogs->firstWhere('booking_status', 'success')?->winman_inventory_id }}).</div>
                            @else
                                <div class="flex flex-wrap items-end gap-3">
                                    <div>
                                        <label class="block text-xs text-gray-600 mb-1">Quantity (kg)</label>
                                        <input wire:model="bookQuantityKg" type="number" step="0.001" class="border-gray-300 rounded-md shadow-sm text-sm w-32" />
                                        @error('bookQuantityKg') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-600 mb-1">Lot / IBC number</label>
                                        <input wire:model="bookLotNumber" class="border-gray-300 rounded-md shadow-sm text-sm" />
                                        @error('bookLotNumber') <span class="block text-xs text-red-600">{{ $message }}</span> @enderror
                                    </div>
                                    <x-primary-button wire:click="book" wire:loading.attr="disabled">Book to WinMan</x-primary-button>
                                </div>
                            @endif
                            @if ($batch->bookingLogs->isNotEmpty())
                                <table class="min-w-full divide-y divide-gray-200 text-sm mt-2">
                                    <thead class="text-left text-xs text-gray-500 uppercase"><tr><th class="py-1">Status</th><th class="py-1">Inventory</th><th class="py-1">Qty (kg)</th><th class="py-1">When</th></tr></thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($batch->bookingLogs->sortByDesc('id') as $log)
                                            <tr>
                                                <td class="py-1">{{ $log->booking_status }}</td>
                                                <td class="py-1">{{ $log->winman_inventory_id ?? '—' }}</td>
                                                <td class="py-1">{{ $log->quantity_booked_kg }}</td>
                                                <td class="py-1 text-gray-500">{{ $log->booking_date?->diffForHumans() }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
