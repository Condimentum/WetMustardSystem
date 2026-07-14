<?php

use App\Domains\Batch\Exceptions\BatchException;
use App\Domains\Batch\Jobs\ValidateBatchCompletionJob;
use App\Features\Batches\ApproveBatchQaFeature;
use App\Features\Batches\CompleteBatchFeature;
use App\Features\Batches\RejectBatchQaFeature;
use App\Features\Batches\GetAvailableIngredientLotsFeature;
use App\Features\Booking\BookFinishedGoodsFeature;
use App\Domains\WinMan\Exceptions\WinManException;
use App\Domains\WinMan\Jobs\FetchManufacturingOrderJob;
use App\Domains\WinMan\Jobs\ListIssuedLotsForWorkInProgressJob;
use App\Operations\AllocateBomIngredientOperation;
use App\Models\BatchRecord;
use App\Models\WinManIssueLog;
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

    public ?string $moProductDescription = null;

    public ?string $moReleaseDate = null;

    public ?string $moDueDate = null;

    public ?string $moWinManStatus = null;

    public bool $showAmendBatchForm = false;

    public bool $showStartOverConfirm = false;

    /** @var array<string, string> */
    public array $amendForm = [
        'planned_quantity' => '',
    ];

    public string $startOverQuantity = '';

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

    public function getMoQuantityOutstandingProperty(): float
    {
        return (float) ($this->batch->manufacturingOrder?->quantity_outstanding ?? 0);
    }

    public function getMoQuantityMadeProperty(): float
    {
        $made = $this->moPlannedQuantity - $this->moQuantityOutstanding;

        return $made > 0 ? $made : 0.0;
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

    public function getHasIssuedIngredientsProperty(): bool
    {
        return $this->batch->issueLogs
            ->where('issue_status', WinManIssueLog::STATUS_SUCCESS)
            ->isNotEmpty();
    }

    public function getCanResetBatchProperty(): bool
    {
        return ! $this->hasIssuedIngredients;
    }

    public function openAmendBatch(): void
    {
        if (! $this->canResetBatch) {
            return;
        }

        $this->showAmendBatchForm = true;
    }

    public function closeAmendBatch(): void
    {
        $this->showAmendBatchForm = false;
    }

    public function saveAmendBatch(): void
    {
        if (! $this->canResetBatch) {
            session()->flash('status', 'Batch details cannot be amended after ingredients are issued to WinMan.');

            return;
        }

        $validated = $this->validate([
            'amendForm.planned_quantity' => ['required', 'numeric', 'min:0.001'],
        ]);

        $this->batch->update([
            'planned_quantity' => (float) $validated['amendForm']['planned_quantity'],
        ]);

        $this->showAmendBatchForm = false;
        $this->reload();
        session()->flash('status', 'Batch details amended.');
    }

    public function openStartOverBatch(): void
    {
        if (! $this->canResetBatch) {
            session()->flash('status', 'Batch cannot be restarted after ingredients are issued to WinMan.');

            return;
        }

        $this->showStartOverConfirm = true;
        $this->startOverQuantity = '';
    }

    public function closeStartOverBatch(): void
    {
        $this->showStartOverConfirm = false;
        $this->startOverQuantity = '';
    }

    public function confirmStartOverBatch(): void
    {
        if (! $this->canResetBatch) {
            session()->flash('status', 'Batch cannot be restarted after ingredients are issued to WinMan.');

            return;
        }

        $validated = $this->validate([
            'startOverQuantity' => ['required', 'numeric', 'min:0.001'],
        ]);

        $newPlannedQty = (float) $validated['startOverQuantity'];

        DB::transaction(function (): void {
            $this->purgeBatchChildren();

            $this->batch->update([
                'status' => BatchRecord::STATUS_IN_PROGRESS,
                'planned_quantity' => $newPlannedQty,
                'completed_by' => null,
                'completed_at' => null,
            ]);
        });

        $this->showAmendBatchForm = false;
        $this->showStartOverConfirm = false;
        $this->startOverQuantity = '';
        $this->reload();
        session()->flash('status', 'Batch restarted. You can enter details again.');
    }

    public function deleteBatch(): void
    {
        if (! $this->canResetBatch) {
            session()->flash('status', 'Batch cannot be deleted after ingredients are issued to WinMan.');

            return;
        }

        $winmanMo = (int) ($this->batch->manufacturingOrder?->winman_manufacturing_order ?? 0);

        DB::transaction(function (): void {
            $this->purgeBatchChildren();
            $this->batch->delete();
        });

        if ($winmanMo > 0) {
            $this->redirectRoute('manufacturing-orders.show', ['winmanMo' => $winmanMo], navigate: true);

            return;
        }

        $this->redirectRoute('manufacturing-orders.search', navigate: true);
    }

    private function purgeBatchChildren(): void
    {
        $this->batch->issueLogs()->delete();
        $this->batch->bookingLogs()->delete();
        $this->batch->ingredientLots()->delete();
        $this->batch->processSteps()->delete();
        $this->batch->processParameters()->delete();
        $this->batch->metalDetectorChecks()->delete();

        foreach ($this->batch->packingRuns as $packingRun) {
            $packingRun->ibcs()->delete();
            $packingRun->hourlyChecks()->delete();
            $packingRun->weightChecks()->delete();
            $packingRun->pallets()->delete();
        }
        $this->batch->packingRuns()->delete();

        foreach ($this->batch->drumProcessingRuns as $drumRun) {
            foreach ($drumRun->pallets as $drumPallet) {
                $drumPallet->drumRecords()->delete();
            }
            $drumRun->pallets()->delete();
            $drumRun->palletRecords()->delete();
        }
        $this->batch->drumProcessingRuns()->delete();

        $this->batch->packagingLots()->delete();
        $this->batch->pallecons()->delete();
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
        $this->loadMoHeaderDates();

        if (trim($this->bookLotNumber) === '') {
            $this->bookLotNumber = $this->derivedLotNumber;
        }

        if (trim($this->bookQuantityKg) === '') {
            $defaultQty = (float) ($this->batch->planned_quantity ?? 0);
            if ($defaultQty > 0) {
                $this->bookQuantityKg = rtrim(rtrim((string) $defaultQty, '0'), '.');
            }
        }

        $this->amendForm = [
            'planned_quantity' => $this->formatQty((float) ($this->batch->planned_quantity ?? 0)),
        ];

        $this->completionIssues = app(ValidateBatchCompletionJob::class)($this->batch);
    }

    private function loadMoUnitOfMeasureDescription(): void
    {
        $this->moProductDescription = null;
        $this->moUnitOfMeasureDescription = $this->batch->manufacturingOrder?->winman_unit_of_measure_description;

        $winmanMo = (int) ($this->batch->manufacturingOrder?->winman_manufacturing_order ?? 0);
        $winmanMoId = trim((string) ($this->batch->manufacturingOrder?->mo_number
            ?? $this->batch->manufacturingOrder?->winman_manufacturing_order_id
            ?? ''));

        if ($winmanMo <= 0 && $winmanMoId === '') {
            return;
        }

        try {
            if ($winmanMo > 0) {
                $moData = app(FetchManufacturingOrderJob::class)($winmanMo);

                if ($moData !== null) {
                    $fetchedDescription = trim((string) $moData->productDescription);
                    $fetchedUomDescription = trim((string) ($moData->unitOfMeasureDescription ?? ''));

                    if ($fetchedDescription !== '') {
                        $this->moProductDescription = $fetchedDescription;
                    }

                    if ($fetchedUomDescription !== '') {
                        $this->moUnitOfMeasureDescription = $fetchedUomDescription;
                    }

                    if ($this->batch->manufacturingOrder !== null) {
                        $this->batch->manufacturingOrder->update([
                            'winman_unit_of_measure' => $moData->unitOfMeasure,
                            'winman_unit_of_measure_description' => $this->moUnitOfMeasureDescription,
                        ]);
                    }
                }
            }

            if ($this->moProductDescription !== null && filled($this->moUnitOfMeasureDescription)) {
                return;
            }

            $row = null;
            if ($winmanMoId !== '') {
                $row = DB::connection('winman')->selectOne(
                    'SELECT p.UnitOfMeasure, u.UnitOfMeasureDescription, p.ProductDescription
                     FROM ManufacturingOrders mo
                     JOIN Products p ON p.Product = mo.Product
                     LEFT JOIN UnitsOfMeasure u ON u.UnitOfMeasure = p.UnitOfMeasure
                     WHERE mo.ManufacturingOrderId = ?',
                    [$winmanMoId],
                );
            }

            if ($row === null && $winmanMo > 0) {
                $row = DB::connection('winman')->selectOne(
                    'SELECT p.UnitOfMeasure, u.UnitOfMeasureDescription, p.ProductDescription
                     FROM ManufacturingOrders mo
                     JOIN Products p ON p.Product = mo.Product
                     LEFT JOIN UnitsOfMeasure u ON u.UnitOfMeasure = p.UnitOfMeasure
                     WHERE mo.ManufacturingOrder = ?',
                    [$winmanMo],
                );
            }

            if ($row !== null) {
                $uomCode = isset($row->UnitOfMeasure) ? (int) $row->UnitOfMeasure : null;
                $uomDescription = isset($row->UnitOfMeasureDescription)
                    ? trim((string) $row->UnitOfMeasureDescription)
                    : '';
                $productDescription = isset($row->ProductDescription)
                    ? trim((string) $row->ProductDescription)
                    : '';

                if ($uomDescription !== '') {
                    $this->moUnitOfMeasureDescription = $uomDescription;
                }
                if ($productDescription !== '') {
                    $this->moProductDescription = $productDescription;
                }

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

    private function loadMoHeaderDates(): void
    {
        $this->moReleaseDate = $this->batch->manufacturingOrder?->winman_last_modified_date
            ? (string) $this->batch->manufacturingOrder->winman_last_modified_date->format('d/m/Y')
            : null;
        $this->moWinManStatus = $this->batch->manufacturingOrder?->winman_system_type
            ? strtoupper(trim((string) $this->batch->manufacturingOrder->winman_system_type))
            : null;
        $this->moDueDate = null;

        $winmanMo = (int) ($this->batch->manufacturingOrder?->winman_manufacturing_order ?? 0);

        if ($winmanMo <= 0) {
            return;
        }

        try {
            $row = DB::connection('winman')->selectOne(
                'SELECT DueDate, LastModifiedDate, SystemType
                 FROM ManufacturingOrders
                 WHERE ManufacturingOrder = ?',
                [$winmanMo],
            );

            if ($row !== null) {
                if (isset($row->DueDate) && $row->DueDate !== null) {
                    $this->moDueDate = Carbon::parse((string) $row->DueDate)->format('d/m/Y');
                }

                if (isset($row->LastModifiedDate) && $row->LastModifiedDate !== null) {
                    $this->moReleaseDate = Carbon::parse((string) $row->LastModifiedDate)->format('d/m/Y');
                }

                if (isset($row->SystemType) && $row->SystemType !== null) {
                    $this->moWinManStatus = strtoupper(trim((string) $row->SystemType));
                }

                if ($this->batch->manufacturingOrder !== null && $this->moWinManStatus !== null) {
                    $this->batch->manufacturingOrder->update([
                        'winman_system_type' => $this->moWinManStatus,
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
        @php
            $moStatus = strtoupper(trim((string) ($this->moWinManStatus ?? '')));
            $statusPill = match ($moStatus) {
                'C', 'CANCELLED', 'CANCELED' => ['bg' => '#fef2f2', 'border' => '#fca5a5', 'color' => '#dc2626', 'dot' => '#dc2626', 'label' => 'Cancelled'],
                'F' => ['bg' => '#eff6ff', 'border' => '#bfdbfe', 'color' => '#2563eb', 'dot' => '#2563eb', 'label' => 'Firm'],
                'R' => ['bg' => '#fffbeb', 'border' => '#fcd34d', 'color' => '#b45309', 'dot' => '#f59e0b', 'label' => 'Released'],
                'I' => ['bg' => '#ecfdf5', 'border' => '#86efac', 'color' => '#15803d', 'dot' => '#16a34a', 'label' => 'In Progress'],
                default => ['bg' => '#f3f4f6', 'border' => '#d1d5db', 'color' => '#4b5563', 'dot' => '#6b7280', 'label' => $moStatus !== '' ? $moStatus : 'Unknown'],
            };
        @endphp
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
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
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;">
                                <div style="width:32px;height:32px;background:#ecfdf5;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:0.9rem;">&#128230;</div>
                                <span style="font-size:0.72rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.05em;">MO Number</span>
                            </div>
                            <div style="font-size:1.05rem;font-weight:800;color:#16a34a;">{{ $batch->manufacturingOrder?->mo_number ?? '—' }}</div>
                        </div>

                        <div style="padding:0 20px;border-right:1px solid #e5e7eb;">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;">
                                <div style="width:32px;height:32px;background:#ecfdf5;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:0.9rem;">&#127981;</div>
                                <span style="font-size:0.72rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.05em;">Product</span>
                            </div>
                            <div style="font-size:1.05rem;font-weight:800;color:#1a1a2e;">{{ $batch->manufacturingOrder?->winman_product_id ?? '—' }}</div>
                        </div>

                        <div style="padding:0 20px;border-right:1px solid #e5e7eb;">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;">
                                <div style="width:32px;height:32px;background:#eff6ff;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:0.9rem;">&#128221;</div>
                                <span style="font-size:0.72rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.05em;">Product Description</span>
                            </div>
                            <div style="font-size:0.95rem;font-weight:700;color:#1a1a2e;line-height:1.35;">{{ $this->moProductDescription ?? '—' }}</div>
                        </div>

                        <div style="padding:0 0 0 20px;">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;">
                                <div style="width:32px;height:32px;background:#eff6ff;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:0.9rem;">&#128197;</div>
                                <span style="font-size:0.72rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.05em;">Release Date</span>
                            </div>
                            <div style="font-size:1.05rem;font-weight:800;color:#1a1a2e;">{{ $this->moReleaseDate ?? ($batch->production_date?->format('d/m/Y') ?? '—') }}</div>
                        </div>
                    </div>
                </div>

                <div style="background:#2d3f8f;border-radius:10px;overflow:hidden;margin-bottom:20px;">
                    <div style="padding:14px 20px;border-bottom:1px solid rgba(255,255,255,0.12);">
                        <span style="font-size:0.75rem;font-weight:800;color:#fff;text-transform:uppercase;letter-spacing:.12em;">Quantities</span>
                    </div>
                    <div style="overflow:auto hidden;background:#f8fafc;">
                        <div style="display:grid;grid-template-columns:repeat(6,minmax(150px,1fr));gap:0;min-width:900px;">
                            <div style="padding:22px 16px;text-align:center;border-right:1px solid #e5e7eb;">
                                <div style="width:44px;height:44px;background:#f59e0b;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;font-size:1.2rem;color:#fff;">&#128230;</div>
                                <div style="font-size:0.65rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;">On Order</div>
                                <div style="font-size:1.3rem;font-weight:900;color:#f59e0b;">{{ $this->formatQty($this->moPlannedQuantity) }}</div>
                            </div>

                            <div style="padding:22px 16px;text-align:center;border-right:1px solid #e5e7eb;">
                                <div style="width:44px;height:44px;background:#16a34a;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;font-size:1.2rem;color:#fff;">&#9989;</div>
                                <div style="font-size:0.65rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;">Made</div>
                                <div style="font-size:1.3rem;font-weight:900;color:#16a34a;">{{ $this->formatQty($this->moQuantityMade) }}</div>
                            </div>

                            <div style="padding:22px 16px;text-align:center;border-right:1px solid #e5e7eb;">
                                <div style="width:44px;height:44px;background:#2563eb;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;font-size:1.2rem;color:#fff;">&#128202;</div>
                                <div style="font-size:0.65rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;">Outstanding</div>
                                <div style="font-size:1.3rem;font-weight:900;color:#2563eb;">{{ $this->formatQty($this->moQuantityOutstanding) }}</div>
                            </div>

                            <div style="padding:22px 16px;text-align:center;border-right:1px solid #e5e7eb;">
                                <div style="width:44px;height:44px;background:#7c3aed;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;font-size:1.2rem;color:#fff;">&#128196;</div>
                                <div style="font-size:0.65rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;">Batches</div>
                                <div style="font-size:1.3rem;font-weight:900;color:#7c3aed;">{{ $this->moBatchCount }}</div>
                            </div>

                            <div style="padding:22px 16px;text-align:center;border-right:1px solid #e5e7eb;">
                                <div style="width:44px;height:44px;background:#6b7280;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;font-size:1.2rem;color:#fff;">&#128295;</div>
                                <div style="font-size:0.65rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;">Scrapped</div>
                                <div style="font-size:1.3rem;font-weight:900;color:#6b7280;">0</div>
                            </div>

                            <div style="padding:22px 16px;text-align:center;">
                                <div style="width:44px;height:44px;background:#dc2626;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;font-size:1.2rem;color:#fff;">&#10060;</div>
                                <div style="font-size:0.65rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px;">Cancelled</div>
                                <div style="font-size:1.3rem;font-weight:900;color:#dc2626;">{{ $batch->status === \App\Models\BatchRecord::STATUS_CANCELLED ? '1' : '0' }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div style="padding:0 32px 18px;">
                <a href="{{ route('manufacturing-orders.search') }}" wire:navigate style="font-size:0.88rem;color:#4f46e5;text-decoration:none;">&larr; MO Search</a>
            </div>
        </div>

        @unless ($this->editable)
            <div @class([
                'border text-sm rounded-lg px-4 py-3',
                'bg-blue-50 border-blue-200 text-blue-800' => ! $this->canResetBatch,
                'bg-emerald-50 border-emerald-200 text-emerald-800' => $this->canResetBatch,
            ])>
                @if ($this->canResetBatch)
                    This batch is <strong>{{ \Illuminate\Support\Str::headline($batch->status) }}</strong>. No ingredients have been issued to WinMan, so you can still amend it or start over.
                @else
                    This batch is <strong>{{ \Illuminate\Support\Str::headline($batch->status) }}</strong> and is read-only because ingredients have already been issued to WinMan.
                @endif
            </div>

            @if ($this->canResetBatch)
                <div class="flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        wire:click="openAmendBatch"
                        class="inline-flex items-center px-3 py-2 rounded-md bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm"
                    >
                        Amend Batch
                    </button>

                    @if ($batch->status === \App\Models\BatchRecord::STATUS_CANCELLED)
                        <button
                            type="button"
                            wire:click="openStartOverBatch"
                            class="inline-flex items-center px-3 py-2 rounded-md bg-emerald-50 hover:bg-emerald-100 text-emerald-700 text-sm"
                        >
                            Start Over
                        </button>
                    @endif

                    <button
                        type="button"
                        wire:click="deleteBatch"
                        onclick="return confirm('Delete this batch completely? This cannot be undone.')"
                        class="inline-flex items-center px-3 py-2 rounded-md bg-red-50 hover:bg-red-100 text-red-700 text-sm"
                    >
                        Delete Batch
                    </button>
                </div>
            @endif
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
                        <div class="flex flex-wrap items-center gap-3">
                            <a href="{{ $this->addBatchUrl }}" wire:navigate class="inline-flex items-center px-3 py-2 rounded-md bg-indigo-600 text-white text-sm hover:bg-indigo-500">Add Batch</a>
                        </div>
                    @else
                        <div class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2">
                            Add Batch is unavailable because this MO is not linked as Intermediate classification 30.
                        </div>
                    @endif

                    <div class="flex flex-wrap items-center gap-3">
                        @if ($this->canResetBatch)
                            <button
                                type="button"
                                wire:click="openAmendBatch"
                                class="inline-flex items-center px-3 py-2 rounded-md bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm"
                            >
                                Amend Batch
                            </button>

                            @if ($batch->status === \App\Models\BatchRecord::STATUS_CANCELLED)
                                <button
                                    type="button"
                                    wire:click="openStartOverBatch"
                                    class="inline-flex items-center px-3 py-2 rounded-md bg-emerald-50 hover:bg-emerald-100 text-emerald-700 text-sm"
                                >
                                    Start Over
                                </button>
                            @endif

                            <button
                                type="button"
                                wire:click="deleteBatch"
                                onclick="return confirm('Delete this batch completely? This cannot be undone.')"
                                class="inline-flex items-center px-3 py-2 rounded-md bg-red-50 hover:bg-red-100 text-red-700 text-sm"
                            >
                                Delete Batch
                            </button>
                        @else
                            <div class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2">
                                Amend/Start Over/Delete is blocked because ingredients have already been issued to WinMan.
                            </div>
                        @endif
                    </div>

                    @if ($showAmendBatchForm && $this->canResetBatch)
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 space-y-3">
                            <div class="text-sm font-semibold text-slate-700">Amend Batch Details</div>

                            <form wire:submit.prevent="saveAmendBatch" class="space-y-3">
                                <div class="max-w-sm">
                                    <label class="block text-xs text-slate-600 mb-1">Batch Quantity</label>
                                    <input type="number" step="0.001" min="0.001" wire:model="amendForm.planned_quantity" class="w-full border-slate-300 rounded-md shadow-sm text-sm" />
                                    @error('amendForm.planned_quantity') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    <div class="mt-1 text-xs text-slate-500">Current batch quantity: {{ $this->formatQty((float) ($batch->planned_quantity ?? 0)) }}</div>
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    <button type="button" wire:click="saveAmendBatch" class="inline-flex items-center px-3 py-2 rounded-md bg-indigo-600 hover:bg-indigo-500 text-white text-sm">Confirm Amendment</button>
                                    <button type="button" wire:click="closeAmendBatch" class="inline-flex items-center px-3 py-2 rounded-md bg-white hover:bg-slate-100 text-slate-700 border border-slate-300 text-sm">Cancel</button>
                                </div>
                            </form>
                        </div>
                    @endif

                    @if ($showStartOverConfirm && $this->canResetBatch)
                        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 space-y-3">
                            <div class="text-sm font-semibold text-amber-800">Start Over Confirmation</div>
                            <div class="text-sm text-amber-700">To restart this batch, retype the new batch quantity below. Existing allocations, issues, checks, and pallecons for this batch will be cleared.</div>

                            <form wire:submit="confirmStartOverBatch" class="space-y-3">
                                <div class="max-w-sm">
                                    <label class="block text-xs text-amber-700 mb-1">Retype Batch Quantity</label>
                                    <input type="number" step="0.001" min="0.001" wire:model="startOverQuantity" class="w-full border-amber-300 rounded-md shadow-sm text-sm" />
                                    @error('startOverQuantity') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    <button type="submit" class="inline-flex items-center px-3 py-2 rounded-md bg-emerald-600 hover:bg-emerald-500 text-white text-sm">Confirm Start Over</button>
                                    <button type="button" wire:click="closeStartOverBatch" class="inline-flex items-center px-3 py-2 rounded-md bg-white hover:bg-amber-100 text-amber-800 border border-amber-300 text-sm">Cancel</button>
                                </div>
                            </form>
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
