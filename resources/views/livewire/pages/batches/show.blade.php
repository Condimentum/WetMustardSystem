<?php

use App\Domains\Batch\Exceptions\BatchException;
use App\Domains\Batch\Jobs\ValidateBatchCompletionJob;
use App\Features\Batches\ApproveBatchQaFeature;
use App\Features\Batches\CompleteBatchFeature;
use App\Features\Batches\RejectBatchQaFeature;
use App\Features\Batches\GetAvailableIngredientLotsFeature;
use App\Features\Booking\BookFinishedGoodsFeature;
use App\Features\Pallecon\AddPalleconRecordFeature;
use App\Features\Pallecon\PrintPalleconLabelFeature;
use App\Domains\WinMan\Exceptions\WinManException;
use App\Domains\WinMan\Jobs\FetchManufacturingOrderJob;
use App\Domains\WinMan\Jobs\ListIssuedLotsForWorkInProgressJob;
use App\Operations\AllocateBomIngredientOperation;
use App\Support\FeatureSettings;
use App\Models\BatchRecord;
use App\Models\PalleconRecord;
use App\Models\PalleconSubmissionAudit;
use App\Models\WinManBookingLog;
use App\Models\WinManIssueLog;
use Illuminate\Support\Carbon;
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

    public string $activeBomAllocationMode = 'manual';

    public string $activeBomGrScanRaw = '';

    public ?string $activeBomLotNumber = null;

    public string $activeBomActualQty = '';

    public ?string $activeBomMessage = null;

    public ?string $activeBomScanDebug = null;

    public ?string $activeBomWinManLookupMessage = null;

    public bool $featureAllocationScannerEnabled = true;

    public bool $featureAllocationCameraAutostartEnabled = true;

    public bool $featureAllocationScanDebugEnabled = true;

    public bool $featureAllocationWinmanLookupEnabled = true;

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

    /** @var array<string, mixed> */
    public array $palleconForm = [
        'ticket_number' => '',
        'serial_number' => '',
        'top_seal_number' => '',
        'bottom_seal_number' => '',
        'liner_number' => '',
        'liner_batch_code' => '',
        'fill_weight' => '1',
        'start_time' => '',
        'finish_time' => '',
    ];

    public bool $bartender_enabled = false;

    public string $label_production_date = '';

    public ?string $print_message = null;

    public bool $print_failed = false;

    /** @var array<string, mixed>|null */
    public ?array $winman_booking_preview = null;

    public function mount(BatchRecord $batch): void
    {
        $this->batch = $batch;
        $this->featureAllocationScannerEnabled = FeatureSettings::enabled('allocation.scanner', true);
        $this->featureAllocationCameraAutostartEnabled = FeatureSettings::enabled('allocation.scanner_camera_autostart', true);
        $this->featureAllocationScanDebugEnabled = FeatureSettings::enabled('allocation.scanner_debug_panel', true);
        $this->featureAllocationWinmanLookupEnabled = FeatureSettings::enabled('allocation.scanner_winman_lookup', true);

        if (! $this->featureAllocationScannerEnabled && $this->activeBomAllocationMode === 'gr_scan') {
            $this->activeBomAllocationMode = 'manual';
        }

        $this->bartender_enabled = (bool) config('services.bartender.enabled', false);
        $this->label_production_date = now()->toDateString();
        $this->reload();
    }

    public function setAllocationMode(string $mode): void
    {
        if ($mode === 'gr_scan' && ! $this->featureAllocationScannerEnabled) {
            $this->activeBomAllocationMode = 'manual';

            return;
        }

        $this->activeBomAllocationMode = $mode === 'gr_scan' ? 'gr_scan' : 'manual';
    }

    public function openBomAllocation(int $componentSnapshotId, string $materialCode, string $materialDescription, ?string $suggestedQty = null): void
    {
        $this->activeBomComponentSnapshotId = $componentSnapshotId;
        $this->activeBomMaterialCode = trim($materialCode);
        $this->activeBomMaterialDescription = trim($materialDescription);
        $this->activeBomAllocationMode = 'manual';
        $this->activeBomGrScanRaw = '';
        $this->activeBomLotNumber = null;
        $this->activeBomActualQty = $suggestedQty !== null ? trim($suggestedQty) : '';
        $this->activeBomMessage = null;
        $this->activeBomScanDebug = null;
        $this->activeBomWinManLookupMessage = null;

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
        $this->activeBomAllocationMode = 'manual';
        $this->activeBomGrScanRaw = '';
        $this->activeBomLotNumber = null;
        $this->activeBomActualQty = '';
        $this->activeBomMessage = null;
        $this->activeBomScanDebug = null;
        $this->activeBomWinManLookupMessage = null;
        $this->activeBomHistoricalLots = [];
    }

    public function applyGrScanPayload(): void
    {
        if (! $this->featureAllocationScannerEnabled) {
            $this->activeBomMessage = 'Scanner view is disabled by project settings.';

            return;
        }

        $this->activeBomMessage = null;
        $this->activeBomWinManLookupMessage = null;
        $this->activeBomScanDebug = null;

        $raw = trim($this->activeBomGrScanRaw);
        if ($raw === '') {
            $this->activeBomMessage = 'Scan value is empty. Please scan again.';

            return;
        }

        $segments = array_map(static fn (string $value): string => trim($value), explode('^', $raw));
        $productId = $segments[0] ?? '';
        $productDescription = $segments[1] ?? '';
        $supplierLotNumber = $segments[2] ?? '';

        $this->activeBomScanDebug = sprintf(
            'Parsed scan -> ProductID: %s | ProductDescription: %s | SupplierLotNumber: %s',
            $productId !== '' ? $productId : '(empty)',
            $productDescription !== '' ? $productDescription : '(empty)',
            $supplierLotNumber !== '' ? $supplierLotNumber : '(empty)'
        );

        if ($productId === '' || $productDescription === '' || $supplierLotNumber === '') {
            $this->activeBomMessage = 'Invalid scan format. Expected ProductID^ProductDescription^SupplierLotNumber^';

            return;
        }

        $expectedCode = strtoupper(trim((string) $this->activeBomMaterialCode));
        $scannedCode = strtoupper($productId);
        if ($expectedCode !== '' && $scannedCode !== $expectedCode) {
            $this->activeBomMessage = 'Scanned ProductID does not match this BOM line.';

            return;
        }

        $this->activeBomLotNumber = $supplierLotNumber;

        $matchedWinManLot = null;
        if ($this->featureAllocationWinmanLookupEnabled) {
            try {
                // Explicitly re-check WinMan at scan time so operators can confirm the lot exists live.
                $winmanLots = app(GetAvailableIngredientLotsFeature::class)((string) $this->activeBomMaterialCode, 500);
                $matchedWinManLot = collect($winmanLots)
                    ->first(fn (array $lot): bool => strcasecmp((string) ($lot['lot_number'] ?? ''), $supplierLotNumber) === 0);
            } catch (\Throwable $e) {
                report($e);
                $this->activeBomWinManLookupMessage = 'WinMan lookup failed while validating this scan.';
                $this->activeBomMessage = 'Could not validate this scanned lot in WinMan right now. Try again.';

                return;
            }

            if ($matchedWinManLot === null) {
                $this->activeBomWinManLookupMessage = 'WinMan lookup: scanned supplier lot was not found for this ProductID.';
                $this->activeBomMessage = 'Scan captured, but this supplier lot is not currently available to issue.';

                return;
            }

            $this->activeBomWinManLookupMessage = 'WinMan lookup: supplier lot found and ready to issue.';
            $this->activeBomLotNumber = (string) ($matchedWinManLot['lot_number'] ?? $supplierLotNumber);
        } else {
            $this->activeBomWinManLookupMessage = 'WinMan lookup: skipped (disabled in project settings).';
        }

        $matchedLot = collect($this->activeBomLotOptions)
            ->contains(fn (array $lot): bool => strcasecmp((string) ($lot['lot_number'] ?? ''), (string) $this->activeBomLotNumber) === 0);

        if (! $matchedLot) {
            $this->activeBomLotOptions[] = [
                'lot_number' => (string) $this->activeBomLotNumber,
                'quantity_outstanding' => (float) ($matchedWinManLot['quantity_outstanding'] ?? 0),
            ];
        }

        $this->activeBomMessage = null;
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

        $batchAllowsNext = in_array((string) $this->batch->status, [
            BatchRecord::STATUS_COMPLETED,
            BatchRecord::STATUS_QA_REVIEW,
            BatchRecord::STATUS_CLOSED,
        ], true);

        return $classification === 30 && $winmanMo > 0 && $batchAllowsNext;
    }

    public function getCanAddBatchForMoProperty(): bool
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

        return route('manufacturing-orders.workspace', [
            'winmanMo' => (int) ($this->batch->manufacturingOrder?->winman_manufacturing_order ?? 0),
        ]);
    }

    public function getWorkspaceUrlProperty(): ?string
    {
        $winmanMo = (int) ($this->batch->manufacturingOrder?->winman_manufacturing_order ?? 0);

        if ($winmanMo <= 0) {
            return null;
        }

        return route('manufacturing-orders.workspace', [
            'winmanMo' => $winmanMo,
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

        $formatted = number_format($rounded, $precision, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
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

    public function getDisplayBatchReferenceProperty(): string
    {
        if ($this->packingMode !== 'pallecon') {
            return (string) ($this->batch->batch_number ?? '');
        }

        $bookingLog = $this->batch->bookingLogs
            ->where('booking_status', WinManBookingLog::STATUS_SUCCESS)
            ->sortByDesc('id')
            ->first();

        return trim((string) ($bookingLog?->lot_number ?? ''));
    }

    public function getDisplayBatchReferenceStyleProperty(): string
    {
        $length = mb_strlen(trim($this->displayBatchReference));

        $fontSize = match (true) {
            $length >= 28 => '18px',
            $length >= 22 => '22px',
            $length >= 16 => '26px',
            default => '34px',
        };

        return "margin-top:8px;font-size:{$fontSize};line-height:1.05;font-weight:800;color:#0f172a;min-height:36px;white-space:normal;overflow-wrap:anywhere;word-break:break-word;max-width:100%;";
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
        return ! $this->hasIssuedIngredients
            && $this->batch->status === BatchRecord::STATUS_IN_PROGRESS;
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
            $this->redirectRoute('manufacturing-orders.workspace', ['winmanMo' => $winmanMo], navigate: true);

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

    public function addPallecon(): void
    {
        if ((string) ($this->palleconForm['fill_weight'] ?? '') === '') {
            $this->syncPalleconDefaults();
        }

        $submittedToWinmanSuccessfully = false;
        $labelPreviewSnapshot = $this->labelPreview;

        $validated = $this->validate([
            'palleconForm.ticket_number' => ['required', 'string', 'max:255'],
            'palleconForm.serial_number' => ['nullable', 'string', 'max:255'],
            'palleconForm.top_seal_number' => ['nullable', 'string', 'max:255'],
            'palleconForm.bottom_seal_number' => ['nullable', 'string', 'max:255'],
            'palleconForm.liner_number' => ['nullable', 'string', 'max:255'],
            'palleconForm.liner_batch_code' => ['nullable', 'string', 'max:255'],
            'palleconForm.fill_weight' => ['required', 'numeric', 'min:0'],
            'palleconForm.start_time' => ['nullable', 'date'],
            'palleconForm.finish_time' => ['nullable', 'date'],
        ])['palleconForm'];

        $validated['start_time'] = $validated['start_time'] ? Carbon::parse($validated['start_time']) : null;
        $validated['finish_time'] = $validated['finish_time'] ? Carbon::parse($validated['finish_time']) : null;
        $validated['fill_weight'] = $validated['fill_weight'] !== '' ? $validated['fill_weight'] : null;

        $pallecon = app(AddPalleconRecordFeature::class)($this->batch, $validated, auth()->user());

        $messages = [];
        $this->print_message = null;
        $this->print_failed = false;

        if ((bool) config('winman.booking.enabled', false)) {
            try {
                $bookedQuantity = (float) ($pallecon->fill_weight ?? 0);

                if ($bookedQuantity > 0) {
                    $finished = now();
                    $expiry = $this->resolveBookingExpiryDate($pallecon, $finished);
                    $lotNumber = $this->resolveWinManLotNumber($pallecon);
                    $requestPreview = [
                        'manufacturing_order_id' => (string) ($this->batch->manufacturingOrder?->winman_manufacturing_order_id ?? $pallecon->mo_number ?? ''),
                        'manufacturing_order_internal' => (int) ($this->batch->manufacturingOrder?->winman_manufacturing_order ?? 0),
                        'product_id' => (string) ($this->batch->manufacturingOrder?->winman_product_id ?? ''),
                        'quantity_kg' => $bookedQuantity,
                        'lot_number' => $lotNumber,
                        'finished_date' => $finished->format('Y-m-d H:i:s'),
                        'expiry_date' => $expiry->format('Y-m-d H:i:s'),
                        'pallecon_number' => (string) ($pallecon->ticket_number ?? ''),
                    ];

                    $log = app(BookFinishedGoodsFeature::class)(
                        $this->batch,
                        $bookedQuantity,
                        $lotNumber,
                        [$lotNumber],
                        $finished,
                        $expiry,
                        auth()->user(),
                        true,
                    );

                    $this->winman_booking_preview = $requestPreview + [
                        'booking_status' => (string) $log->booking_status,
                        'winman_inventory_id' => $log->winman_inventory_id !== null ? (string) $log->winman_inventory_id : null,
                        'error_message' => $log->error_message,
                        'booked_at' => $log->booking_date?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s'),
                        'booked_quantity_kg_logged' => $log->quantity_booked_kg !== null ? (string) $log->quantity_booked_kg : null,
                        'booked_quantity_traded_units' => $log->quantity_booked_traded_units !== null ? (string) $log->quantity_booked_traded_units : null,
                        'logged_lot_number' => $log->lot_number,
                    ];

                    if ($log->booking_status === 'success') {
                        $submittedToWinmanSuccessfully = true;
                        $messages[] = 'WinMan inventory created (Inventory '.($log->winman_inventory_id ?? '—').').';
                    } else {
                        $this->print_failed = true;
                        $messages[] = 'WinMan booking '.$log->booking_status.': '.($log->error_message ?: 'unknown error').'.';
                    }
                } else {
                    $this->winman_booking_preview = [
                        'booking_status' => 'skipped',
                        'error_message' => 'Fill weight is zero.',
                    ];
                    $messages[] = 'WinMan booking skipped because fill weight is zero.';
                }
            } catch (\Throwable $e) {
                $this->print_failed = true;
                $this->winman_booking_preview = [
                    'booking_status' => 'failed',
                    'error_message' => $e->getMessage(),
                ];
                $messages[] = 'Pallecon saved, but WinMan booking failed: '.$e->getMessage();
            }
        }

        if ($this->bartender_enabled) {
            try {
                $result = app(PrintPalleconLabelFeature::class)($pallecon, 1, [
                    'production_date' => $this->label_production_date,
                ]);
                $messages[] = $this->formatPrintMessage($result);
            } catch (\Throwable $e) {
                $this->print_failed = true;
                $messages[] = 'Pallecon saved, but label print failed: '.$e->getMessage();
            }
        }

        if ($messages !== []) {
            $this->print_message = implode(' ', $messages);
        }

        PalleconSubmissionAudit::create([
            'batch_record_id' => $this->batch->id,
            'pallecon_record_id' => $pallecon->id,
            'submitted_by' => auth()->id(),
            'submitted_at' => now(),
            'booking_status' => (string) ($this->winman_booking_preview['booking_status'] ?? 'not_attempted'),
            'print_status' => $this->bartender_enabled
                ? ($this->print_failed ? 'failed' : 'success')
                : 'disabled',
            'winman_preview' => $this->winman_booking_preview,
            'label_preview' => is_array($labelPreviewSnapshot) ? $labelPreviewSnapshot : null,
        ]);

        if ($submittedToWinmanSuccessfully && $this->batch->status === BatchRecord::STATUS_IN_PROGRESS) {
            app(\App\Domains\Batch\Jobs\CompleteBatchJob::class)($this->batch, auth()->user());
            $messages[] = 'Batch status changed to Completed.';

            if ($messages !== []) {
                $this->print_message = implode(' ', $messages);
            }
        }

        $this->reset('palleconForm');
        $this->syncPalleconDefaults();
        $this->reload();
        $this->dispatch('switch-batch-tab', tab: 'packing');
    }

    private function resolveWinManLotNumber(PalleconRecord $pallecon): string
    {
        $moId = trim((string) ($this->batch->manufacturingOrder?->winman_manufacturing_order_id
            ?? $pallecon->mo_number
            ?? 'MO'));
        $palleconNumber = trim((string) ($pallecon->ticket_number ?? ''));
        $labelStyleLot = $this->resolveLabelStyleLotNumber();

        $moId = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $moId));
        $palleconNumber = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $palleconNumber));

        if ($moId === '') {
            $moId = 'MO';
        }

        if ($palleconNumber === '') {
            $palleconNumber = 'P'.$pallecon->id;
        }

        $fullLot = trim($moId.' '.$palleconNumber.' '.$labelStyleLot);

        return substr($fullLot, 0, 100);
    }

    private function resolveLabelStyleLotNumber(): string
    {
        $productionDate = $this->label_production_date !== ''
            ? Carbon::parse($this->label_production_date)
            : now();

        $yjjj = $productionDate->format('y');
        $yjjj = substr($yjjj, -1).str_pad((string) $productionDate->dayOfYear, 3, '0', STR_PAD_LEFT);

        return $yjjj.'00M96';
    }

    private function resolveBookingExpiryDate(PalleconRecord $pallecon, Carbon $fallbackBase): Carbon
    {
        $productionDate = $this->label_production_date !== ''
            ? Carbon::parse($this->label_production_date)
            : now();

        $previewPallecon = new PalleconRecord([
            'mo_number' => $this->batch->manufacturingOrder?->mo_number,
            'fill_weight' => (float) ($pallecon->fill_weight ?? 0),
        ]);
        $previewPallecon->setRelation('batchRecord', $this->batch);

        try {
            $payload = app(PrintPalleconLabelFeature::class)->buildPrintPayload($previewPallecon, 1, [
                'production_date' => $productionDate->toDateString(),
            ]);

            $sources = is_array($payload['options']['named_data_sources'] ?? null)
                ? $payload['options']['named_data_sources']
                : [];

            $bbeFormat = strtoupper(trim((string) ($sources['BBEformat'] ?? '')));
            $bbeValue = isset($sources['BBE']) && is_numeric((string) $sources['BBE'])
                ? max(1, (int) $sources['BBE'])
                : null;

            if ($bbeValue !== null && $bbeFormat === 'DDMMYYYY') {
                return $productionDate->copy()->addDays($bbeValue)->endOfDay();
            }

            if ($bbeValue !== null && $bbeFormat === 'MMYYYY') {
                return $productionDate->copy()->addMonthsNoOverflow($bbeValue)->endOfMonth();
            }

            $bestBeforeRaw = trim((string) ($sources['BestBeforeEnd'] ?? ''));

            if ($bestBeforeRaw !== '') {
                if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $bestBeforeRaw) === 1) {
                    return Carbon::createFromFormat('d/m/Y', $bestBeforeRaw)->endOfDay();
                }

                if (preg_match('/^\d{2}\/\d{4}$/', $bestBeforeRaw) === 1) {
                    return Carbon::createFromFormat('m/Y', $bestBeforeRaw)->endOfMonth();
                }

                return Carbon::parse($bestBeforeRaw)->endOfDay();
            }
        } catch (\Throwable) {
        }

        $shelfDays = (int) ($this->batch->product?->shelf_life_days ?? 180);

        return $fallbackBase->copy()->addDays($shelfDays)->endOfMonth();
    }

    /** @param array<string, mixed> $result */
    private function formatPrintMessage(array $result): string
    {
        $requestId = isset($result['printRequestID']) ? (string) $result['printRequestID'] : null;
        $messages = isset($result['messages']) && is_array($result['messages']) ? $result['messages'] : [];
        $printer = null;

        foreach ($messages as $message) {
            if (! is_string($message)) {
                continue;
            }

            if (preg_match('/Printer:\s*(.+)$/m', $message, $matches) === 1) {
                $printer = trim($matches[1]);
                break;
            }
        }

        $parts = ['Pallecon saved and BarTender sent the job to the spooler.'];

        if ($requestId) {
            $parts[] = 'Request ID: '.$requestId.'.';
        }

        if ($printer) {
            $parts[] = 'Printer: '.$printer.'.';
        }

        return implode(' ', $parts);
    }

    /** @return array<string, mixed> */
    public function getLabelPreviewProperty(): array
    {
        $fillWeightInput = (string) ($this->palleconForm['fill_weight'] ?? '1');
        $fillWeight = is_numeric($fillWeightInput) ? (float) $fillWeightInput : 1.0;
        $productionDate = $this->label_production_date !== '' ? $this->label_production_date : now()->toDateString();

        $previewPallecon = new PalleconRecord([
            'mo_number' => $this->batch->manufacturingOrder?->mo_number,
            'fill_weight' => $fillWeight,
        ]);
        $previewPallecon->setRelation('batchRecord', $this->batch);

        try {
            $payload = app(PrintPalleconLabelFeature::class)->buildPrintPayload($previewPallecon, 1, [
                'production_date' => $productionDate,
            ]);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        $sources = is_array($payload['options']['named_data_sources'] ?? null)
            ? $payload['options']['named_data_sources']
            : [];

        $lotNumberLabelStyle = null;
        $datePacked = isset($sources['DatePacked']) ? trim((string) $sources['DatePacked']) : '';

        if ($datePacked !== '') {
            try {
                $datePackedCarbon = Carbon::parse($datePacked);
                $yjjj = $datePackedCarbon->format('y');
                $yjjj = substr($yjjj, -1).str_pad((string) $datePackedCarbon->dayOfYear, 3, '0', STR_PAD_LEFT);
                $lotNumberLabelStyle = $yjjj.'00M96';
            } catch (\Throwable) {
                $lotNumberLabelStyle = null;
            }
        }

        return [
            'fill_weight' => $sources['FillWeight'] ?? null,
            'date_of_production' => $sources['DateOfProduction'] ?? null,
            'best_before_end' => $sources['BestBeforeEnd'] ?? null,
            'manufacturing_order' => $sources['ManufacturingOrder'] ?? null,
            'product_id' => $sources['ProductId'] ?? ($sources['ProductID'] ?? null),
            'product_description' => $sources['ProductDescription'] ?? null,
            'lot_number' => $sources['LotNumber'] ?? ($sources['BatchCode'] ?? null),
            'lot_number_label_style' => $lotNumberLabelStyle,
            'winman_lot_number' => trim((string) (($sources['ManufacturingOrder'] ?? 'MO').' '.trim((string) ($this->palleconForm['ticket_number'] ?? '')).' '.($lotNumberLabelStyle ?? ''))),
            'batch_code' => $sources['BatchCode'] ?? null,
            'barcode' => $sources['Barcode'] ?? null,
            'ingredients' => $sources['Ingredients'] ?? null,
            'storage' => $sources['HandInstruct'] ?? null,
            'origin' => $sources['CountryId'] ?? null,
            'weight' => $sources['Weight'] ?? null,
            'energy_kj' => $sources['EnergyKJ'] ?? null,
            'energy_kcal' => $sources['EnergyKcal'] ?? null,
            'fat' => $sources['Fat'] ?? null,
            'saturates' => $sources['Saturates'] ?? ($sources['Saturatess'] ?? null),
            'carbohydrates' => $sources['TotalCarbohydrates'] ?? null,
            'sugars' => $sources['OfWhichSugar'] ?? null,
            'fibre' => $sources['Fibre'] ?? null,
            'protein' => $sources['Protein'] ?? null,
            'salt' => $sources['Salt'] ?? null,
        ];
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
            'pallecons.checkedBy',
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

        $this->syncPalleconDefaults();

        $this->completionIssues = app(ValidateBatchCompletionJob::class)($this->batch);
    }

    private function syncPalleconDefaults(): void
    {
        $defaultFillWeight = $this->formatQty((float) ($this->batch->planned_quantity ?? 0));

        $this->palleconForm['fill_weight'] = $defaultFillWeight !== '' ? $defaultFillWeight : '0';
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
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6" x-data="{ tab: @js(in_array((string) request()->query('tab', 'allocation'), ['batch', 'allocation', 'packing'], true) ? (string) request()->query('tab', 'allocation') : 'allocation') }" x-on:switch-batch-tab.window="tab = $event.detail.tab">

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
                'I' => ['bg' => '#ecfdf5', 'border' => '#86efac', 'color' => '#15803d', 'dot' => '#16a34a', 'label' => 'Issued'],
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
                        <div style="display:grid;grid-template-columns:repeat(4,minmax(170px,1fr));gap:0;min-width:700px;">
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
                @elseif ($batch->status === \App\Models\BatchRecord::STATUS_COMPLETED || $batch->status === \App\Models\BatchRecord::STATUS_QA_REVIEW || $batch->status === \App\Models\BatchRecord::STATUS_CLOSED)
                    This batch is <strong>{{ \Illuminate\Support\Str::headline($batch->status) }}</strong>. It is now read-only on this screen.
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
        <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
            <div style="padding:0 14px;background:linear-gradient(180deg,#f8fafc 0%,#f1f5f9 100%);border-bottom:1px solid #dbe1ea;">
                <nav style="display:flex;gap:8px;align-items:stretch;overflow:auto hidden;min-height:62px;">
                    @if ($this->workspaceUrl)
                        <a href="{{ $this->workspaceUrl }}" wire:navigate style="display:inline-flex;align-self:stretch;align-items:center;gap:8px;padding:0 18px;border-radius:8px;border:2px solid #cbd5e1;background:#fff;color:#334155;font-size:14px;font-weight:800;letter-spacing:.01em;line-height:1;text-decoration:none;white-space:nowrap;">
                            <span aria-hidden="true">&larr;</span>
                            <span>Back to Workspace</span>
                        </a>
                    @endif
                    @foreach (['allocation' => 'Ingredient Allocation', 'packing' => $this->packingLabel] as $key => $label)
                        @if ($key === 'packing' && $this->packingMode !== 'pallecon')
                            <a href="{{ route($this->packingRoute, $batch) }}" wire:navigate
                                :style="tab === '{{ $key }}'
                                    ? 'display:inline-flex;align-self:stretch;align-items:center;gap:8px;padding:0 18px;border-radius:8px;border:2px solid #4f46e5;background:#4f46e5;color:#fff;font-size:14px;font-weight:800;letter-spacing:.01em;line-height:1;text-decoration:none;box-shadow:0 4px 12px rgba(79,70,229,.24);white-space:nowrap;'
                                    : 'display:inline-flex;align-self:stretch;align-items:center;gap:8px;padding:0 18px;border-radius:8px;border:2px solid #cbd5e1;background:#fff;color:#334155;font-size:14px;font-weight:800;letter-spacing:.01em;line-height:1;text-decoration:none;white-space:nowrap;'"
                            >
                                <span aria-hidden="true" style="font-size:14px;line-height:1;">&#129520;</span>
                                <span>{{ $label }}</span>
                            </a>
                        @else
                            <button @click="tab = '{{ $key }}'"
                                :style="tab === '{{ $key }}'
                                    ? 'display:inline-flex;align-self:stretch;align-items:center;gap:8px;padding:0 18px;border-radius:8px;border:2px solid #4f46e5;background:#4f46e5;color:#fff;font-size:14px;font-weight:800;letter-spacing:.01em;line-height:1;box-shadow:0 4px 12px rgba(79,70,229,.24);white-space:nowrap;'
                                    : 'display:inline-flex;align-self:stretch;align-items:center;gap:8px;padding:0 18px;border-radius:8px;border:2px solid #cbd5e1;background:#fff;color:#334155;font-size:14px;font-weight:800;letter-spacing:.01em;line-height:1;white-space:nowrap;'"
                                type="button"
                            >
                                <span>{{ $label }}</span>
                            </button>
                        @endif
                    @endforeach
                </nav>
            </div>

            <div class="px-0 pb-0">
                {{-- Batch --}}
                <div x-show="tab === 'batch'" class="space-y-4 p-6">
                    @php
                        $batchStatusStyles = match ($batch->status) {
                            \App\Models\BatchRecord::STATUS_IN_PROGRESS => ['bg' => '#fef9c3', 'border' => '#fde68a', 'color' => '#92400e', 'dot' => '#f59e0b'],
                            \App\Models\BatchRecord::STATUS_COMPLETED => ['bg' => '#dcfce7', 'border' => '#86efac', 'color' => '#166534', 'dot' => '#22c55e'],
                            \App\Models\BatchRecord::STATUS_QA_REVIEW => ['bg' => '#ede9fe', 'border' => '#c4b5fd', 'color' => '#5b21b6', 'dot' => '#8b5cf6'],
                            \App\Models\BatchRecord::STATUS_CLOSED => ['bg' => '#f1f5f9', 'border' => '#cbd5e1', 'color' => '#334155', 'dot' => '#64748b'],
                            default => ['bg' => '#fee2e2', 'border' => '#fca5a5', 'color' => '#991b1b', 'dot' => '#ef4444'],
                        };
                    @endphp
                    <div style="background:#fff;border:1px solid #dbe1ea;border-radius:16px;overflow:hidden;box-shadow:0 1px 2px rgba(15,23,42,0.05);">
                        <div style="padding:14px 22px;">
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0;align-items:stretch;">
                                <div style="padding:0 26px 0 0;min-width:220px;border-right:1px solid #dbe1ea;">
                                    <div style="font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#64748b;font-weight:700;">Batch Number</div>
                                    <div style="{{ $this->displayBatchReferenceStyle }}" title="{{ $this->displayBatchReference }}">{{ $this->displayBatchReference }}</div>
                                </div>

                                <div style="padding:0 26px;border-right:1px solid #dbe1ea;">
                                    <div style="font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#64748b;font-weight:700;">Batch Qty</div>
                                    <div style="margin-top:8px;font-size:34px;line-height:1.05;font-weight:800;color:#0f172a;">{{ $this->formatQty((float) ($batch->planned_quantity ?? 0)) }}</div>
                                </div>

                                <div style="padding:0 26px;border-right:1px solid #dbe1ea;display:flex;flex-direction:column;justify-content:center;">
                                    <div style="font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#64748b;font-weight:700;">Status</div>
                                    <span style="margin-top:10px;display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:999px;border:1px solid {{ $batchStatusStyles['border'] }};background:{{ $batchStatusStyles['bg'] }};color:{{ $batchStatusStyles['color'] }};font-size:14px;font-weight:700;width:max-content;">
                                        <span style="height:8px;width:8px;border-radius:999px;background:{{ $batchStatusStyles['dot'] }};display:inline-block;"></span>
                                        {{ \Illuminate\Support\Str::headline($batch->status) }}
                                    </span>
                                </div>

                                <div style="padding:0 26px;display:flex;flex-direction:column;justify-content:center;">
                                    <div style="font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#64748b;font-weight:700;">Date Produced</div>
                                    <div style="margin-top:8px;font-size:24px;line-height:1.1;font-weight:800;color:#0f172a;">{{ $batch->production_date?->format('d/m/Y') ?? '—' }}</div>
                                </div>

                            </div>

                            @if ($this->canResetBatch || ($batch->status === \App\Models\BatchRecord::STATUS_CANCELLED && $this->canResetBatch))
                                <div style="margin-top:16px;padding-top:16px;border-top:1px solid #dbe1ea;display:flex;justify-content:flex-end;gap:12px;flex-wrap:wrap;">
                                    @if ($this->canResetBatch)
                                        <button
                                            type="button"
                                            wire:click="openAmendBatch"
                                            style="display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:12px;border:2px solid #a5b4fc;background:#fff;color:#4f46e5;font-size:14px;font-weight:700;"
                                        >
                                            <span aria-hidden="true">&#9998;</span>
                                            <span>Amend Batch</span>
                                        </button>

                                        <button
                                            type="button"
                                            wire:click="deleteBatch"
                                            onclick="return confirm('Delete this batch completely? This cannot be undone.')"
                                            style="display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:12px;border:2px solid #fca5a5;background:#fff;color:#dc2626;font-size:14px;font-weight:700;"
                                        >
                                            <span aria-hidden="true">&#128465;</span>
                                            <span>Delete Batch</span>
                                        </button>
                                    @endif

                                    @if ($batch->status === \App\Models\BatchRecord::STATUS_CANCELLED && $this->canResetBatch)
                                        <button
                                            type="button"
                                            wire:click="openStartOverBatch"
                                            style="display:inline-flex;align-items:center;padding:10px 18px;border-radius:12px;border:2px solid #86efac;background:#ecfdf5;color:#15803d;font-size:14px;font-weight:700;"
                                        >
                                            Start Over
                                        </button>
                                    @endif
                                </div>
                            @endif

                            @if ($this->canAddBatch)
                                <div style="margin-top:16px;padding-top:16px;border-top:1px solid #dbe1ea;display:flex;justify-content:flex-end;">
                                    <a href="{{ $this->addBatchUrl }}" wire:navigate style="display:inline-flex;align-items:center;padding:10px 18px;border-radius:12px;background:#4f46e5;color:#fff;font-size:14px;font-weight:700;text-decoration:none;">Add Batch</a>
                                </div>
                            @endif
                        </div>
                    </div>

                        @if ($this->moIsOverAllocated)
                            <div class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2">
                                Planned batch total is above MO quantity. Create additional batches only if this is intentional.
                            </div>
                        @endif

                        @if (! $this->canAddBatch)
                            <div class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2">
                                {{ $this->canAddBatchForMo
                                    ? 'Add Batch is unavailable until this batch has been completed.'
                                    : 'Add Batch is unavailable because this MO is not linked as Intermediate classification 30.' }}
                            </div>
                        @endif

                        @if (! $this->canResetBatch)
                            <div class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2">
                                {{ $batch->status === \App\Models\BatchRecord::STATUS_COMPLETED || $batch->status === \App\Models\BatchRecord::STATUS_QA_REVIEW || $batch->status === \App\Models\BatchRecord::STATUS_CLOSED
                                    ? 'Amend/Start Over/Delete is unavailable because this batch is no longer in progress.'
                                    : 'Amend/Start Over/Delete is blocked because ingredients have already been issued to WinMan.' }}
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
                        <button @click="tab = 'allocation'" class="inline-flex items-center rounded-full bg-indigo-600 text-white text-sm font-semibold px-4 py-2 hover:bg-indigo-500">Continue to Ingredient Allocation →</button>
                    </div>
                </div>

                {{-- Unified Allocation Workspace --}}
                <div x-show="tab === 'allocation'" class="space-y-3">

                    @if ($batch->componentSnapshots->isEmpty())
                        <div style="background:#fff7ed;border:1px solid #fdba74;color:#9a3412;border-radius:12px;padding:12px 14px;font-size:14px;font-weight:600;">No component snapshot stored for this MO.</div>
                    @else
                        <div style="background:#fff;border:1px solid #dbe1ea;border-radius:16px;overflow:hidden;box-shadow:0 1px 2px rgba(15,23,42,0.05);">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="text-left text-xs text-slate-500 uppercase bg-slate-50">
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
                                            class="cursor-pointer hover:bg-indigo-50/40 transition-colors">
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
                                                    class="inline-flex items-center px-2.5 py-1.5 rounded-md text-xs font-semibold border border-indigo-200 bg-indigo-50 text-indigo-700 hover:bg-indigo-100">
                                                    {{ strtoupper((string) $bomLine->item_type) === 'C' && $this->editable ? 'Allocate' : 'View' }}
                                                </button>
                                            </td>
                                        </tr>

                                        @if ($activeBomComponentSnapshotId === (int) $bomLine->id)
                                            <tr class="bg-indigo-50/40">
                                                <td colspan="6" class="px-3 py-3 space-y-3">
                                                    <div class="text-sm font-semibold text-indigo-900">{{ $activeBomMaterialCode }} - {{ $activeBomMaterialDescription }}</div>

                                                    @if ($activeBomMessage)
                                                        <div class="text-xs text-indigo-700">{{ $activeBomMessage }}</div>
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

                                                    <div class="border border-indigo-100 rounded-lg overflow-hidden bg-white">
                                                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                                                            <thead class="text-left text-xs text-slate-500 uppercase bg-slate-50">
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

                @if ($this->packingMode === 'pallecon')
                    <div x-show="tab === 'packing'" class="space-y-6 p-6">
                        @if ($print_message)
                            <div class="rounded-md p-3 text-sm {{ $print_failed ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-green-50 text-green-700 border border-green-200' }}">
                                {{ $print_message }}
                            </div>
                        @endif

                        @if ($winman_booking_preview)
                            <div class="border border-emerald-200 rounded-lg p-4 bg-emerald-50 shadow-sm">
                                <h3 class="text-sm font-semibold text-emerald-800 mb-3">WinMan Inventory Insert Preview (Last Add)</h3>

                                <div class="w-full rounded-lg border border-emerald-200 bg-white p-4">
                                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3 text-sm">
                                        <div><span class="text-slate-500">Status:</span> <span class="font-semibold text-slate-900">{{ $winman_booking_preview['booking_status'] ?? '—' }}</span></div>
                                        <div><span class="text-slate-500">Inventory ID:</span> <span class="font-semibold text-slate-900">{{ $winman_booking_preview['winman_inventory_id'] ?? '—' }}</span></div>
                                        <div><span class="text-slate-500">Booked At:</span> <span class="font-medium text-slate-900">{{ $winman_booking_preview['booked_at'] ?? '—' }}</span></div>
                                        <div><span class="text-slate-500">MO ID:</span> <span class="font-medium text-slate-900">{{ $winman_booking_preview['manufacturing_order_id'] ?? '—' }}</span></div>
                                        <div><span class="text-slate-500">MO Internal:</span> <span class="font-medium text-slate-900">{{ $winman_booking_preview['manufacturing_order_internal'] ?? '—' }}</span></div>
                                        <div><span class="text-slate-500">Product ID:</span> <span class="font-medium text-slate-900">{{ $winman_booking_preview['product_id'] ?? '—' }}</span></div>
                                        <div><span class="text-slate-500">Pallecon Number:</span> <span class="font-medium text-slate-900">{{ $winman_booking_preview['pallecon_number'] ?? '—' }}</span></div>
                                        <div><span class="text-slate-500">Quantity (kg):</span> <span class="font-medium text-slate-900">{{ $winman_booking_preview['quantity_kg'] ?? '—' }}</span></div>
                                        <div class="md:col-span-2"><span class="text-slate-500">Lot Number Sent:</span> <span class="font-medium text-slate-900">{{ $winman_booking_preview['lot_number'] ?? '—' }}</span></div>
                                        <div class="md:col-span-2"><span class="text-slate-500">Lot Number Logged:</span> <span class="font-medium text-slate-900">{{ $winman_booking_preview['logged_lot_number'] ?? '—' }}</span></div>
                                        <div><span class="text-slate-500">Finished Date:</span> <span class="font-medium text-slate-900">{{ $winman_booking_preview['finished_date'] ?? '—' }}</span></div>
                                        <div><span class="text-slate-500">Expiry Date:</span> <span class="font-medium text-slate-900">{{ $winman_booking_preview['expiry_date'] ?? '—' }}</span></div>
                                        <div><span class="text-slate-500">Logged Qty (kg):</span> <span class="font-medium text-slate-900">{{ $winman_booking_preview['booked_quantity_kg_logged'] ?? '—' }}</span></div>
                                        <div><span class="text-slate-500">Logged Qty (TU):</span> <span class="font-medium text-slate-900">{{ $winman_booking_preview['booked_quantity_traded_units'] ?? '—' }}</span></div>
                                        @if (! empty($winman_booking_preview['error_message']))
                                            <div class="md:col-span-2 xl:col-span-4 text-red-700">
                                                <span class="text-slate-500">Error:</span> {{ $winman_booking_preview['error_message'] }}
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if ($batch->status === \App\Models\BatchRecord::STATUS_IN_PROGRESS)
                            <form wire:submit="addPallecon" class="bg-white shadow-sm rounded-xl border border-slate-200 overflow-hidden">
                                <div style="padding:14px 18px;border-bottom:1px solid #dbe1ea;background:linear-gradient(180deg,#eef2ff 0%,#f8fafc 100%);">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-indigo-700">Pallecon Entry</div>
                                    <div class="text-sm text-slate-600 mt-1">Capture packing identifiers, fill weight, and label/booking parameters.</div>
                                </div>
                                <div class="p-6 space-y-4">
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                        <div>
                                            <label class="block text-xs text-gray-600 mb-1">Pallecon Number</label>
                                            <input type="text" wire:model.live.debounce.300ms="palleconForm.ticket_number" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
                                            @error('palleconForm.ticket_number') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                        </div>

                                        <div>
                                            <label class="block text-xs text-gray-600 mb-1">Fill weight (kg)</label>
                                            <input type="number" step="0.001" min="0" wire:model="palleconForm.fill_weight" readonly class="w-full border-gray-300 rounded-md shadow-sm text-sm bg-slate-50 text-slate-700 cursor-not-allowed" />
                                            <p class="mt-1 text-xs text-slate-500">Derived from the batch planned quantity and locked for this output size.</p>
                                            @error('palleconForm.fill_weight') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                        </div>

                                        <div>
                                            <label class="block text-xs text-gray-600 mb-1">Label production date</label>
                                            <input type="date" wire:model.live="label_production_date" class="border-gray-300 rounded-md shadow-sm text-sm" />
                                            @if (! $bartender_enabled)
                                                <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-1">
                                                    Printing is disabled. Set <strong>BARTENDER_ENABLED=true</strong> in the environment to enable automatic label printing on save.
                                                </p>
                                            @else
                                                <p class="text-xs text-slate-600 bg-slate-100 border border-slate-200 rounded px-2 py-1">
                                                    Label printing is automatic when you click Add pallecon.
                                                </p>
                                            @endif
                                        </div>
                                    </div>

                                    <div>
                                        <x-primary-button type="submit" class="w-full justify-center">Add Pallecon</x-primary-button>
                                    </div>
                                </div>
                            </form>
                        @endif

                        <div class="border border-slate-200 rounded-xl overflow-hidden bg-slate-50 shadow-sm">
                            <div style="padding:14px 18px;border-bottom:1px solid #dbe1ea;background:linear-gradient(180deg,#f8fafc 0%,#f1f5f9 100%);">
                                <h3 class="text-sm font-semibold text-slate-700">Label Preview (Before Print)</h3>
                            </div>
                            <div class="p-4">
                                @if (isset($this->labelPreview['error']))
                                    <p class="text-xs text-red-700">Preview unavailable: {{ $this->labelPreview['error'] }}</p>
                                @else
                                    <div class="w-full rounded-lg border border-slate-200 bg-white p-4 space-y-4">
                                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3 text-sm">
                                            <div><span class="text-slate-500">Fill Weight:</span> <span class="font-medium text-slate-900">{{ $this->labelPreview['fill_weight'] ?? '—' }}</span></div>
                                            <div><span class="text-slate-500">Production Date:</span> <span class="font-medium text-slate-900">{{ $this->labelPreview['date_of_production'] ?? '—' }}</span></div>
                                            <div><span class="text-slate-500">Best Before End:</span> <span class="font-medium text-slate-900">{{ $this->labelPreview['best_before_end'] ?? '—' }}</span></div>
                                            <div><span class="text-slate-500">MO:</span> <span class="font-medium text-slate-900">{{ $this->labelPreview['manufacturing_order'] ?? '—' }}</span></div>
                                            <div><span class="text-slate-500">Product ID:</span> <span class="font-medium text-slate-900">{{ $this->labelPreview['product_id'] ?? '—' }}</span></div>
                                            <div><span class="text-slate-500">Lot Number (Label Style):</span> <span class="font-medium text-slate-900">{{ $this->labelPreview['lot_number_label_style'] ?? '—' }}</span></div>
                                            <div class="md:col-span-2 xl:col-span-2"><span class="text-slate-500">WinMan Lot Number:</span> <span class="font-medium text-slate-900">{{ $this->labelPreview['winman_lot_number'] ?? '—' }}</span></div>
                                            <div class="md:col-span-2 xl:col-span-2"><span class="text-slate-500">Product Description:</span> <span class="font-medium text-slate-900">{{ $this->labelPreview['product_description'] ?? '—' }}</span></div>
                                            <div><span class="text-slate-500">Barcode:</span> <span class="font-medium text-slate-900">{{ $this->labelPreview['barcode'] ?? '—' }}</span></div>
                                        </div>

                                        <div class="text-sm">
                                            <div class="text-slate-500">Additional product text fields are sent to the label payload.</div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif

                @if ($showAllocateModal)
                    <div
                        class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0"
                        x-data="{
                            mode: @entangle('activeBomAllocationMode').live,
                            scannerEnabled: @js($featureAllocationScannerEnabled),
                            cameraAutostartEnabled: @js($featureAllocationCameraAutostartEnabled),
                            modalVisible: @entangle('showAllocateModal').live,
                            scanRaw: @entangle('activeBomGrScanRaw').live,
                            cameraRunning: false,
                            scannerError: '',
                            stream: null,
                            detector: null,
                            scanTimer: null,
                            init() {
                                this.$watch('mode', async (value) => {
                                    if (value === 'gr_scan' && this.modalVisible && this.scannerEnabled && this.cameraAutostartEnabled) {
                                        await this.startCamera();
                                        return;
                                    }

                                    this.stopCamera();
                                });

                                this.$watch('modalVisible', async (value) => {
                                    if (!value) {
                                        this.stopCamera();
                                        return;
                                    }

                                    if (value && this.mode === 'gr_scan' && this.scannerEnabled && this.cameraAutostartEnabled) {
                                        await this.startCamera();
                                    }
                                });

                                if (this.modalVisible && this.mode === 'gr_scan' && this.scannerEnabled && this.cameraAutostartEnabled) {
                                    this.startCamera();
                                }
                            },
                            async startCamera() {
                                if (!this.scannerEnabled) {
                                    this.scannerError = 'Scanner view is disabled by project settings.';
                                    return;
                                }

                                if (this.cameraRunning) {
                                    return;
                                }

                                this.scannerError = '';

                                if (!('BarcodeDetector' in window)) {
                                    this.scannerError = 'Camera scanning is not supported by this browser. Use manual scan entry below.';
                                    return;
                                }

                                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                                    this.scannerError = 'Camera is unavailable on this device/browser.';
                                    return;
                                }

                                try {
                                    this.detector = new BarcodeDetector({
                                        formats: ['qr_code', 'data_matrix', 'code_128', 'code_39', 'ean_13', 'ean_8', 'upc_a', 'upc_e'],
                                    });

                                    this.stream = await navigator.mediaDevices.getUserMedia({
                                        video: {
                                            facingMode: { ideal: 'environment' },
                                        },
                                        audio: false,
                                    });

                                    this.$refs.grScanVideo.srcObject = this.stream;
                                    await this.$refs.grScanVideo.play();
                                    this.cameraRunning = true;
                                    this.scanLoop();
                                } catch (error) {
                                    this.scannerError = 'Unable to start camera. Check browser camera permissions.';
                                    this.stopCamera();
                                }
                            },
                            async scanLoop() {
                                if (!this.cameraRunning || !this.detector || !this.$refs.grScanVideo) {
                                    return;
                                }

                                try {
                                    const results = await this.detector.detect(this.$refs.grScanVideo);
                                    const value = (results[0]?.rawValue ?? '').trim();

                                    if (value !== '') {
                                        this.scanRaw = value;
                                        await this.$wire.applyGrScanPayload();
                                        this.stopCamera();
                                        return;
                                    }
                                } catch (error) {
                                    this.scannerError = 'Camera is running, but this scan could not be decoded yet.';
                                }

                                this.scanTimer = window.setTimeout(() => this.scanLoop(), 350);
                            },
                            stopCamera() {
                                if (this.scanTimer) {
                                    window.clearTimeout(this.scanTimer);
                                    this.scanTimer = null;
                                }

                                if (this.stream) {
                                    this.stream.getTracks().forEach((track) => track.stop());
                                    this.stream = null;
                                }

                                if (this.$refs.grScanVideo) {
                                    this.$refs.grScanVideo.srcObject = null;
                                }

                                this.cameraRunning = false;
                            },
                        }"
                    >
                        <div class="fixed inset-0 bg-gray-500/70" wire:click="closeAllocateModal"></div>
                        <div class="relative mb-6 bg-white rounded-lg overflow-hidden shadow-xl transform transition-all sm:w-full sm:max-w-xl sm:mx-auto">
                            <div class="px-6 py-4 border-b border-gray-200">
                                <h3 class="text-lg font-semibold text-gray-900">Allocate</h3>
                                <p class="mt-1 text-sm text-gray-600">{{ $activeBomMaterialCode }} - {{ $activeBomMaterialDescription }}</p>
                            </div>

                            <div class="px-6 py-4 space-y-4">
                                <div>
                                    <label class="block text-xs text-gray-600 mb-1">Allocation view</label>
                                    <div class="inline-flex rounded-md border border-gray-300 overflow-hidden">
                                        <button
                                            type="button"
                                            wire:click="setAllocationMode('manual')"
                                            class="px-4 py-2 text-sm font-medium"
                                            :class="{ 'bg-slate-800 text-white': mode === 'manual', 'bg-white text-slate-700': mode !== 'manual' }"
                                        >
                                            Dropdown
                                        </button>
                                        @if ($featureAllocationScannerEnabled)
                                            <button
                                                type="button"
                                                wire:click="setAllocationMode('gr_scan')"
                                                class="px-4 py-2 text-sm font-medium border-l border-gray-300"
                                                :class="{ 'bg-slate-800 text-white': mode === 'gr_scan', 'bg-white text-slate-700': mode !== 'gr_scan' }"
                                            >
                                                Scanner
                                            </button>
                                        @endif
                                    </div>
                                    @if (! $featureAllocationScannerEnabled)
                                        <p class="mt-1 text-xs text-gray-500">Scanner view is disabled in project settings.</p>
                                    @endif
                                </div>

                                @if ($activeBomAllocationMode === 'gr_scan' && $featureAllocationScannerEnabled)
                                    <div class="rounded-md border border-indigo-200 bg-indigo-50 p-3 space-y-2">
                                        <div class="rounded-md border border-indigo-300 bg-black/90 overflow-hidden" x-show="cameraRunning" x-transition>
                                            <video x-ref="grScanVideo" playsinline muted autoplay class="block w-full h-52 object-cover"></video>
                                        </div>

                                        <div class="flex items-center justify-end gap-2">
                                            <x-secondary-button type="button" x-show="!cameraRunning" @click="startCamera">Open camera</x-secondary-button>
                                            <x-secondary-button type="button" x-show="cameraRunning" @click="stopCamera">Stop camera</x-secondary-button>
                                        </div>

                                        <div x-show="scannerError" class="text-xs text-rose-700" x-text="scannerError"></div>

                                        <label class="block text-xs text-indigo-800 font-medium">GR scan value</label>
                                        <input wire:model.defer="activeBomGrScanRaw" type="text" class="w-full border-indigo-300 rounded-md shadow-sm text-sm" placeholder="ProductID^ProductDescription^SupplierLotNumber^" />
                                        <div class="flex items-center justify-between gap-2">
                                            <p class="text-xs text-indigo-700">Scan format: ProductID^ProductDescription^SupplierLotNumber^</p>
                                            <x-secondary-button type="button" wire:click="applyGrScanPayload">Apply scan</x-secondary-button>
                                        </div>

                                        @if ($featureAllocationScanDebugEnabled && $activeBomScanDebug)
                                            <div class="text-xs text-indigo-900 bg-white/70 border border-indigo-200 rounded px-2 py-1">
                                                {{ $activeBomScanDebug }}
                                            </div>
                                        @endif

                                        @if ($activeBomWinManLookupMessage)
                                            <div class="text-xs text-slate-800 bg-white/80 border border-slate-200 rounded px-2 py-1">
                                                {{ $activeBomWinManLookupMessage }}
                                            </div>
                                        @endif

                                        <div>
                                            <label class="block text-xs text-gray-700 mb-1">Scanned lot</label>
                                            <input type="text" value="{{ (string) ($activeBomLotNumber ?? '') }}" readonly class="w-full border-gray-300 rounded-md shadow-sm text-sm bg-gray-100 text-gray-700" placeholder="No lot scanned yet" />
                                        </div>
                                    </div>
                                @endif

                                @if ($activeBomAllocationMode === 'manual')
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
                                @else
                                    <div>
                                        <label class="block text-xs text-gray-600 mb-1">Actual qty</label>
                                        <input wire:model="activeBomActualQty" type="number" step="0.001" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
                                        @error('activeBomActualQty') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </div>
                                @endif

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

            </div>
        </div>
    </div>
</div>
