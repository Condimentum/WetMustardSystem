<?php

use App\Domains\Audit\Jobs\RecordErrorLogJob;
use App\Domains\Batch\Exceptions\BatchException;
use App\Domains\Batch\Jobs\ValidateBatchCompletionJob;
use App\Features\Batches\ApproveBatchQaFeature;
use App\Features\Batches\CompleteBatchFeature;
use App\Features\Batches\SignIngredientLotFeature;
use App\Features\Batches\RejectBatchQaFeature;
use App\Features\Batches\GetAvailableIngredientLotsFeature;
use App\Features\Booking\BookFinishedGoodsFeature;
use App\Domains\WinMan\Exceptions\WinManException;
use App\Domains\WinMan\Jobs\FetchManufacturingOrderJob;
use App\Domains\WinMan\Jobs\ListIssuedLotsForWorkInProgressJob;
use App\Domains\WinMan\Support\WinManHealthCheck;
use App\Operations\AllocateBomIngredientOperation;
use App\Support\FeatureSettings;
use App\Models\BatchRecord;
use App\Models\LabelPrintLog;
use App\Models\PaperworkRow;
use App\Models\PalleconFill;
use App\Models\User;
use App\Models\WinManIssueLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
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

    public bool $winManDown = false;

    public bool $preferScannerOnMobile = false;

    /** @var array<int, array{lot_number:string,quantity:float,last_effective_date:?string}> */
    public array $activeBomHistoricalLots = [];

    public bool $showAllocateModal = false;

    public string $rejectReason = '';

    public string $bookQuantityKg = '';

    public string $bookLotNumber = '';

    public ?string $bookFlash = null;

    public string $powdersWeighedOperatorId = '';

    public string $liquidsWeighedOperatorId = '';

    public string $tippingBatchOperatorId = '';

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
        $this->featureAllocationScannerEnabled = FeatureSettings::enabled('allocation.scanner', true);
        $this->featureAllocationCameraAutostartEnabled = FeatureSettings::enabled('allocation.scanner_camera_autostart', true);
        $this->featureAllocationScanDebugEnabled = FeatureSettings::enabled('allocation.scanner_debug_panel', true);
        $this->featureAllocationWinmanLookupEnabled = FeatureSettings::enabled('allocation.scanner_winman_lookup', true);

        // Auto-fallback to unverified manual entry when WinMan is unreachable,
        // regardless of the admin toggle above (resilience tier 2).
        $this->winManDown = ! app(WinManHealthCheck::class)->isUp();
        if ($this->winManDown) {
            $this->featureAllocationWinmanLookupEnabled = false;
        }

        $this->preferScannerOnMobile = $this->featureAllocationScannerEnabled && $this->isMobileUserAgent();

        if (! $this->featureAllocationScannerEnabled && $this->activeBomAllocationMode === 'gr_scan') {
            $this->activeBomAllocationMode = 'manual';
        }

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
        $this->activeBomAllocationMode = $this->preferScannerOnMobile ? 'gr_scan' : 'manual';
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

    protected function isMobileUserAgent(): bool
    {
        $agent = strtolower((string) request()->userAgent());
        if ($agent === '') {
            return false;
        }

        foreach (['android', 'iphone', 'ipad', 'ipod', 'mobile', 'windows phone'] as $needle) {
            if (str_contains($agent, $needle)) {
                return true;
            }
        }

        return false;
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

        // Read-only batches have nothing left to allocate - the scanner/manual
        // entry modal would be dead weight, so "View" just expands the inline
        // row of what was already issued, same as clicking the row itself.
        if ($this->editable) {
            $this->showAllocateModal = true;
        }
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
        } elseif ($this->winManDown) {
            $this->activeBomWinManLookupMessage = 'WinMan lookup: skipped (WinMan is currently unavailable - lot accepted unverified).';
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
            app(RecordErrorLogJob::class)($e, 'batches.show.bom-allocation');
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

    #[Computed]
    public function signoffOperators()
    {
        return User::query()
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Every label print attempt for this batch - either printed with this
     * batch as the pallecon's primary (first-filled) batch, or printed for a
     * pallecon this batch contributed a fill to. View-only history.
     */
    #[Computed]
    public function labelPrintHistory()
    {
        $palleconIds = PalleconFill::query()
            ->where('batch_record_id', $this->batch->id)
            ->pluck('pallecon_id');

        return LabelPrintLog::query()
            ->where('batch_record_id', $this->batch->id)
            ->when($palleconIds->isNotEmpty(), fn ($query) => $query->orWhereIn('pallecon_id', $palleconIds))
            ->with('printedBy:id,name', 'pallecon:id,serial_number')
            ->orderByDesc('printed_at')
            ->get();
    }

    /** Every WinMan finished-goods booking attempt recorded for this batch. View-only history. */
    #[Computed]
    public function winmanBookingHistory()
    {
        return $this->batch->bookingLogs()
            ->with('pallecon:id,serial_number')
            ->orderByDesc('booking_date')
            ->get();
    }

    public function getIngredientSignoffCompleteProperty(): bool
    {
        if ($this->packingMode !== 'pallecon') {
            return true;
        }

        // Sign-off is a batch-level confirmation of actions performed outside
        // the app, recorded once per batch (not per ingredient lot).
        $confirmations = $this->paperworkIngredientSignoff;

        return $confirmations['powders'] !== ''
            && $confirmations['liquids'] !== ''
            && $confirmations['tipping'] !== '';
    }

    public function getIngredientSignoffReadyLotCountProperty(): int
    {
        return $this->batch->ingredientLots
            ->filter(fn ($lot): bool => ! blank($lot->lot_number) && $lot->actual_quantity !== null)
            ->count();
    }

    /** @return array{powders:string,liquids:string,tipping:string} */
    public function getPaperworkIngredientSignoffProperty(): array
    {
        $rows = PaperworkRow::query()
            ->where('batch_record_id', $this->batch->id)
            ->whereIn('row_key', [
                'ingredients_signoff.powders_weighed_by',
                'ingredients_signoff.liquids_weighed_by',
                'ingredients_signoff.tipping_batch_by',
            ])
            ->get()
            ->keyBy('row_key');

        return [
            'powders' => trim((string) ($rows['ingredients_signoff.powders_weighed_by']->value_text ?? '')),
            'liquids' => trim((string) ($rows['ingredients_signoff.liquids_weighed_by']->value_text ?? '')),
            'tipping' => trim((string) ($rows['ingredients_signoff.tipping_batch_by']->value_text ?? '')),
        ];
    }

    /** @return array{mill_gap:string,p1_speed:string,p2_speed:string} */
    public function getPaperworkProcessSettingsProperty(): array
    {
        $rows = PaperworkRow::query()
            ->where('batch_record_id', $this->batch->id)
            ->whereIn('row_key', [
                'process_settings.mill_gap_size_used',
                'process_settings.p1_speed_used',
                'process_settings.p2_speed_used',
            ])
            ->get()
            ->keyBy('row_key');

        return [
            'mill_gap' => trim((string) ($rows['process_settings.mill_gap_size_used']->value_text ?? '')),
            'p1_speed' => trim((string) ($rows['process_settings.p1_speed_used']->value_text ?? '')),
            'p2_speed' => trim((string) ($rows['process_settings.p2_speed_used']->value_text ?? '')),
        ];
    }

    public function applyBulkIngredientSignoff(): void
    {
        $this->authorizeEditable();

        $powdersOperatorId = (int) trim($this->powdersWeighedOperatorId);
        $liquidsOperatorId = (int) trim($this->liquidsWeighedOperatorId);
        $tippedOperatorId = (int) trim($this->tippingBatchOperatorId);

        if ($powdersOperatorId <= 0 || $liquidsOperatorId <= 0 || $tippedOperatorId <= 0) {
            session()->flash('status', 'Select Powders Weighed, Liquids Weighed, and Tipping Batch before applying Ingredients Sign Off.');
            $this->dispatch('ingredient-signoff-failed');

            return;
        }

        $powdersOperator = User::query()->find($powdersOperatorId);
        if ($powdersOperator === null) {
            session()->flash('status', 'Selected Powders Weighed operator is invalid.');
            $this->dispatch('ingredient-signoff-failed');

            return;
        }

        $liquidsOperator = User::query()->find($liquidsOperatorId);
        if ($liquidsOperator === null) {
            session()->flash('status', 'Selected Liquids Weighed operator is invalid.');
            $this->dispatch('ingredient-signoff-failed');

            return;
        }

        $tippedOperator = User::query()->find($tippedOperatorId);
        if ($tippedOperator === null) {
            session()->flash('status', 'Selected Tipped By operator is invalid.');
            $this->dispatch('ingredient-signoff-failed');

            return;
        }

        $lots = $this->batch->ingredientLots
            ->filter(fn ($lot): bool => ! blank($lot->lot_number) && $lot->actual_quantity !== null)
            ->values();

        if ($lots->isEmpty()) {
            session()->flash('status', 'No allocated ingredient lots are ready for sign-off yet.');
            $this->dispatch('ingredient-signoff-failed');

            return;
        }

        $wasReplacement = $this->ingredientSignoffComplete;

        // Batch-level confirmation of actions performed outside the app - one
        // record per batch, not per ingredient lot. No WinMan round trip here.
        try {
            $this->upsertIngredientSignoffPaperworkRow(
                'ingredients_signoff.powders_weighed_by',
                'Powders Weighed By',
                900,
                $powdersOperator,
            );
            $this->upsertIngredientSignoffPaperworkRow(
                'ingredients_signoff.liquids_weighed_by',
                'Liquids Weighed By',
                901,
                $liquidsOperator,
            );
            $this->upsertIngredientSignoffPaperworkRow(
                'ingredients_signoff.tipping_batch_by',
                'Tipping Batch By',
                902,
                $tippedOperator,
            );

            foreach ([
                ['ingredients_signoff_powders', 'Powders weighed confirmed (action performed outside DBMTS)', $powdersOperator],
                ['ingredients_signoff_liquids', 'Liquids weighed confirmed (action performed outside DBMTS)', $liquidsOperator],
                ['ingredients_signoff_tipping', 'Batch tipping confirmed (action performed outside DBMTS)', $tippedOperator],
            ] as [$purpose, $meaning, $operator]) {
                app(\App\Domains\Signature\Jobs\RecordElectronicSignatureJob::class)($this->batch, $purpose, $operator, $meaning);
            }

            app(\App\Domains\Audit\Jobs\RecordAuditEntryJob::class)(
                $this->batch,
                $wasReplacement ? 'ingredients_signoff_override' : 'ingredients_signoff',
                auth()->user(),
                'ingredients_signoff',
                null,
                "Powders: {$powdersOperator->name}; Liquids: {$liquidsOperator->name}; Tipping: {$tippedOperator->name}",
            );
        } catch (\Throwable $e) {
            app(RecordErrorLogJob::class)($e, 'batches.show.bulk-signoff');
            session()->flash('status', 'Ingredients Sign Off failed: '.$e->getMessage());
            $this->dispatch('ingredient-signoff-failed');

            return;
        }

        // $wasReplacement above primed the per-request computed cache with the
        // pre-write state; bust it so this same render reflects the rows we just
        // wrote (Submitted state + Reset button, no need to submit twice).
        unset($this->paperworkIngredientSignoff, $this->ingredientSignoffComplete);

        $this->completionIssues = app(ValidateBatchCompletionJob::class)($this->batch);

        $message = $wasReplacement
            ? 'Ingredients Sign Off replaced.'
            : 'Ingredients Sign Off recorded.';

        if ($this->ingredientSignoffComplete) {
            $message .= ' You can now complete the batch, then record fills in the Pallecon Workspace.';
        }

        session()->flash('status', $message);
        $this->dispatch('ingredient-signoff-submitted');
    }

    public function resetIngredientSignoff(): void
    {
        $this->authorizeEditable();

        // Persistent reset: blank the saved confirmations so the operator must
        // reselect and resubmit. Audited; previous signatures remain on record.
        PaperworkRow::query()
            ->where('batch_record_id', $this->batch->id)
            ->whereIn('row_key', [
                'ingredients_signoff.powders_weighed_by',
                'ingredients_signoff.liquids_weighed_by',
                'ingredients_signoff.tipping_batch_by',
            ])
            ->update([
                'value_text' => null,
                'status' => 'pending',
                'completed_at' => null,
                'completed_by' => null,
                'updated_by' => auth()->id(),
            ]);

        app(\App\Domains\Audit\Jobs\RecordAuditEntryJob::class)(
            $this->batch,
            'ingredients_signoff_reset',
            auth()->user(),
            'ingredients_signoff',
            'submitted',
            'reset for reselection',
        );

        $this->reset([
            'powdersWeighedOperatorId',
            'liquidsWeighedOperatorId',
            'tippingBatchOperatorId',
        ]);

        // Drop any cached computed state so this render shows the empty
        // dropdowns again immediately.
        unset($this->paperworkIngredientSignoff, $this->ingredientSignoffComplete);

        $this->completionIssues = app(ValidateBatchCompletionJob::class)($this->batch);
        session()->flash('status', 'Ingredients Sign Off reset. Select all operators again and resubmit.');
    }

    private function upsertIngredientSignoffPaperworkRow(string $rowKey, string $rowLabel, int $rowOrder, User $operator): void
    {
        $batchColumnIndex = (int) (PaperworkRow::query()
            ->where('batch_record_id', $this->batch->id)
            ->value('batch_column_index') ?? 1);

        PaperworkRow::query()->updateOrCreate(
            [
                'batch_record_id' => $this->batch->id,
                'row_key' => $rowKey,
            ],
            [
                'manufacturing_order_id' => $this->batch->manufacturing_order_id,
                'manufacturing_order_ref' => (string) ($this->batch->manufacturingOrder?->winman_manufacturing_order_id ?? $this->batch->manufacturingOrder?->mo_number ?? ''),
                'batch_number' => (string) ($this->batch->batch_number ?? ''),
                'batch_column_index' => $batchColumnIndex,
                'product_id' => $this->batch->product_id,
                'recipe_code' => $this->batch->manufacturingOrder?->recipe_code,
                'recipe_revision' => null,
                'row_label' => $rowLabel,
                'row_order' => $rowOrder,
                'value_text' => (string) $operator->name,
                'value_number' => null,
                'value_bool' => null,
                'value_datetime' => null,
                'unit' => null,
                'status' => 'completed',
                'completed_at' => now(),
                'completed_by' => $operator->id,
                'entered_by' => auth()->id(),
                'entered_at' => now(),
                'updated_by' => auth()->id(),
            ],
        );
    }

    private function upsertTextPaperworkRow(string $rowKey, string $rowLabel, int $rowOrder, string $valueText): void
    {
        $batchColumnIndex = (int) (PaperworkRow::query()
            ->where('batch_record_id', $this->batch->id)
            ->value('batch_column_index') ?? 1);

        PaperworkRow::query()->updateOrCreate(
            [
                'batch_record_id' => $this->batch->id,
                'row_key' => $rowKey,
            ],
            [
                'manufacturing_order_id' => $this->batch->manufacturing_order_id,
                'manufacturing_order_ref' => (string) ($this->batch->manufacturingOrder?->winman_manufacturing_order_id ?? $this->batch->manufacturingOrder?->mo_number ?? ''),
                'batch_number' => (string) ($this->batch->batch_number ?? ''),
                'batch_column_index' => $batchColumnIndex,
                'product_id' => $this->batch->product_id,
                'recipe_code' => $this->batch->manufacturingOrder?->recipe_code,
                'recipe_revision' => null,
                'row_label' => $rowLabel,
                'row_order' => $rowOrder,
                'value_text' => $valueText,
                'value_number' => null,
                'value_bool' => null,
                'value_datetime' => null,
                'unit' => null,
                'status' => 'completed',
                'completed_at' => now(),
                'completed_by' => auth()->id(),
                'entered_by' => auth()->id(),
                'entered_at' => now(),
                'updated_by' => auth()->id(),
            ],
        );
    }

    public function getPackingLabelProperty(): string
    {
        return match ($this->packingMode) {
            'pallecon' => 'Pallecon Workspace',
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
        // Batch Reference is app-only; WinMan references belong to pallecons.
        return (string) ($this->batch->batch_number ?? '');
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
            app(RecordErrorLogJob::class)($e, 'batches.show.complete');
            $this->completionIssues = $e->issues;

            return;
        }

        session()->flash('status', 'Batch '.$this->batch->batch_number.' completed. Record pallecon fills in the Pallecon Workspace.');

        $winmanMo = (int) ($this->batch->manufacturingOrder?->winman_manufacturing_order ?? 0);

        if ($winmanMo > 0) {
            $this->redirectRoute('manufacturing-orders.workspace', ['winmanMo' => $winmanMo], navigate: true);

            return;
        }

        $this->reload();
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
        // Detach this batch's fills only; shared containers may hold other batches.
        $this->batch->palleconFills()->delete();
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
            app(RecordErrorLogJob::class)($e, 'batches.show.book-finished-goods');
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
<style>
    @keyframes ingredient-signoff-pulse {
        0%, 100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0); }
        50% { box-shadow: 0 0 0 6px rgba(245, 158, 11, 0.22); }
    }

    @keyframes ingredient-signoff-submitted {
        from { opacity: 0; transform: translateY(4px) scale(0.98); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }

    .ingredient-signoff-submit {
        transition: min-width 220ms ease, background-color 260ms ease, transform 160ms ease, box-shadow 260ms ease;
    }

    .ingredient-signoff-submit--idle {
        background: #1e293b;
    }

    .ingredient-signoff-submit--idle:hover {
        background: #334155;
        transform: translateY(-1px);
    }

    .ingredient-signoff-submit--loading {
        min-width: 132px;
        background: #1e293b;
        transform: scaleX(0.82);
    }

    .ingredient-signoff-submit--success {
        min-width: 158px;
        background: #059669;
        box-shadow: 0 0 0 5px rgba(5, 150, 105, 0.12);
        animation: ingredient-signoff-success-settle 350ms ease-out;
    }

    .ingredient-signoff-spinner {
        width: 16px;
        height: 16px;
        border: 2px solid rgba(255, 255, 255, 0.35);
        border-top-color: #fff;
        border-radius: 50%;
        animation: ingredient-signoff-spin 700ms linear infinite;
    }

    .ingredient-signoff-check {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 20px;
        height: 20px;
        border: 2px solid #fff;
        border-radius: 50%;
        font-size: 13px;
        line-height: 1;
        animation: ingredient-signoff-check-pop 280ms ease-out;
    }

    @keyframes ingredient-signoff-spin {
        to { transform: rotate(360deg); }
    }

    @keyframes ingredient-signoff-success-settle {
        0% { transform: scaleX(0.82); }
        65% { transform: scaleX(1.04); }
        100% { transform: scaleX(1); }
    }

    @keyframes ingredient-signoff-check-pop {
        from { opacity: 0; transform: scale(0.35) rotate(-45deg); }
        to { opacity: 1; transform: scale(1) rotate(0); }
    }
</style>

    @php
        $requestedTab = (string) request()->query('tab', 'allocation');
        $allowedTabs = ['batch', 'allocation'];
        if ($this->packingMode === 'pallecon') {
            $allowedTabs[] = 'signoff';
        }

        $initialTab = in_array($requestedTab, $allowedTabs, true) ? $requestedTab : 'allocation';
    @endphp

    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6" x-data="{ tab: @js($initialTab) }" x-on:switch-batch-tab.window="tab = $event.detail.tab">

        @if (session('status'))
            <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-lg px-4 py-3">
                {{ session('status') }}
            </div>
        @endif

        {{-- Header --}}
        <x-mo-header
            :system-type="$this->moWinManStatus ?? ''"
            :mo-number="$batch->manufacturingOrder?->mo_number ?? '—'"
            :product="$batch->manufacturingOrder?->winman_product_id ?? '—'"
            :description="$this->moProductDescription ?? '—'"
            date-label="Release Date"
            :date-value="$this->moReleaseDate ?? ($batch->production_date?->format('d/m/Y') ?? '—')"
            :planned="$this->moPlannedQuantity"
            :made="$this->moQuantityMade"
            :outstanding="$this->moQuantityOutstanding"
            :batches="$this->moBatchCount"
            :fmt="fn (float $v): string => $this->formatQty($v)"
            style="margin-bottom:16px;"
        ><a href="{{ route('manufacturing-orders.search') }}" wire:navigate style="font-size:0.88rem;color:#4f46e5;text-decoration:none;">&larr; MO Search</a></x-mo-header>

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
        <x-batch-allocation-styles />
        <div class="ba-shell">
            <div class="ba-tabs-wrap">
                <nav class="ba-tabs">
                    @if ($this->workspaceUrl)
                        <a href="{{ $this->workspaceUrl }}" wire:navigate class="ba-tab ba-tab--back">
                            <span>Back to Workspace</span>
                        </a>
                    @endif
                    @php
                        $batchTabs = ['allocation' => 'Ingredient Allocation'];
                        if ($this->packingMode === 'pallecon') {
                            $batchTabs['signoff'] = 'Ingredients Sign Off';
                        } else {
                            $batchTabs['packing'] = $this->packingLabel;
                        }
                    @endphp

                    @foreach ($batchTabs as $key => $label)
                        @if ($key === 'packing')
                            <a href="{{ route($this->packingRoute, $batch) }}" wire:navigate class="ba-tab">
                                <span>{{ $label }}</span>
                            </a>
                        @else
                            <button
                                @click="tab = '{{ $key }}'"
                                class="ba-tab"
                                :class="tab === '{{ $key }}' && 'ba-tab--active'"
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
                                    <div style="font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#64748b;font-weight:700;">Batch Reference</div>
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
                        <button @click="tab = 'allocation'" class="ba-btn ba-btn--lg">Continue to Ingredient Allocation →</button>
                    </div>
                </div>

                {{-- Unified Allocation Workspace --}}
                <div x-show="tab === 'allocation'" class="space-y-3" style="padding:14px;">

                    @if ($winManDown)
                        <x-winman-offline-banner message="WinMan connection is currently unavailable. Scanned/entered lots are accepted without live verification and issues will be attempted when you allocate - double-check quantities carefully." />
                    @endif

                    @if ($batch->componentSnapshots->isEmpty())
                        <div style="background:#fff7ed;border:1px solid #fdba74;color:#9a3412;border-radius:12px;padding:12px 14px;font-size:14px;font-weight:600;">No component snapshot stored for this MO.</div>
                    @else
                        <div class="ba-list">
                            <div class="ba-grid ba-head">
                                <div style="grid-area:badge;">Type</div>
                                <div class="ba-prod">Product</div>
                                <div class="ba-desc">Description</div>
                                <div class="ba-out">Outstanding</div>
                                <div class="ba-alloc">Allocated</div>
                                <div class="ba-act"></div>
                            </div>
                            <div class="ba-rows">
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
                                                    'lot_id' => $lot?->id,
                                                    'lot_number' => (string) ($log->lot_number ?? ($lot?->lot_number ?? '—')),
                                                    'supplier_lot_number' => (string) ($lot?->supplier_lot_number ?? '—'),
                                                    'quantity' => (float) ($log->quantity_issued ?? ($lot?->actual_quantity ?? 0)),
                                                    'uom' => (string) ($lot?->uom ?? 'kg'),
                                                    'issue_status' => (string) ($log->issue_status ?? ''),
                                                    'error_message' => (string) ($log->error_message ?? ''),
                                                    'weighed_by' => $lot?->weighedBy?->name,
                                                    'weighed_at' => $lot?->weighed_at?->format('d/m H:i'),
                                                    'tipped_by' => $lot?->tippedBy?->name,
                                                    'tipped_at' => $lot?->tipped_at?->format('d/m H:i'),
                                                ];
                                            })->values();

                                            if ($allocatedPreviewRows->isEmpty()) {
                                                $allocatedPreviewRows = $allocatedLots->map(function ($lot) use ($batch): array {
                                                    $issueLog = $batch->issueLogs
                                                        ->where('batch_ingredient_lot_id', $lot->id)
                                                        ->sortByDesc('id')
                                                        ->first();

                                                    return [
                                                        'lot_id' => $lot->id,
                                                        'lot_number' => (string) ($lot->lot_number ?? '—'),
                                                        'supplier_lot_number' => (string) ($lot->supplier_lot_number ?? '—'),
                                                        'quantity' => (float) ($lot->actual_quantity ?? 0),
                                                        'uom' => (string) ($lot->uom ?? 'kg'),
                                                        'issue_status' => (string) ($issueLog?->issue_status ?? ''),
                                                        'error_message' => (string) ($issueLog?->error_message ?? ''),
                                                        'weighed_by' => $lot->weighedBy?->name,
                                                        'weighed_at' => $lot->weighed_at?->format('d/m H:i'),
                                                        'tipped_by' => $lot->tippedBy?->name,
                                                        'tipped_at' => $lot->tipped_at?->format('d/m H:i'),
                                                    ];
                                                })->values();
                                            }

                                            $allocatedQty = (float) $allocatedPreviewRows->sum('quantity');
                                            // quantity_issued on the snapshot is MO-wide, not per batch. Only
                                            // trust it as an "allocated" fallback on a single-batch MO - otherwise
                                            // an earlier batch's WinMan issues zero out this batch's outstanding.
                                            $snapshotIssuedQty = abs((float) ($bomLine->quantity_issued ?? 0));
                                            if ($allocatedQty <= 0 && $snapshotIssuedQty > 0 && $this->moBatchCount <= 1) {
                                                $allocatedQty = $snapshotIssuedQty;
                                            }

                                            // formatQty() trims via number_format; a bare (string) cast + rtrim
                                            // '0' would turn 150 into "15" / 90 into "9".
                                            $allocatedQtyDisplay = $this->formatQty($allocatedQty);

                                            $requiredForBatch = round(abs((float) ($bomLine->quantity ?? 0)) * $this->batchScaleRatio, 3);
                                            $outstandingForBatch = max($requiredForBatch - $allocatedQty, 0.0);
                                            $outstandingForBatchDisplay = $this->formatQty($outstandingForBatch);

                                            $allocatedLotCount = $allocatedPreviewRows->count();
                                        @endphp
                                        @php
                                            $itemType = strtoupper(trim((string) $bomLine->item_type));
                                            // Shield metal: gold for the made item, silver for components, bronze otherwise.
                                            $shield = match ($itemType) {
                                                'M' => ['#fbe7a1', '#d4a93e', '#8a6420', '#5a3f10'],
                                                'C' => ['#f4f5f7', '#b8bdc6', '#6b7280', '#374151'],
                                                default => ['#f1d2ae', '#b9814a', '#7a4e24', '#4a2e12'],
                                            };
                                            $isOpenRow = $activeBomComponentSnapshotId === (int) $bomLine->id;
                                        @endphp
                                        <div
                                            wire:click="toggleBomAllocationRow({{ $bomLine->id }}, @js($componentCode), @js((string) $bomLine->component_description), @js($outstandingForBatchDisplay))"
                                            @class(['ba-row ba-grid', 'ba-row--open' => $isOpenRow])>
                                            <svg class="ba-badge" viewBox="0 0 40 46" role="img" aria-label="Type {{ $bomLine->item_type }}">
                                                <defs>
                                                    <linearGradient id="ba-shield-{{ $bomLine->id }}" x1="0" y1="0" x2="1" y2="1">
                                                        <stop offset="0" stop-color="{{ $shield[0] }}"/>
                                                        <stop offset=".55" stop-color="{{ $shield[1] }}"/>
                                                        <stop offset="1" stop-color="{{ $shield[2] }}"/>
                                                    </linearGradient>
                                                </defs>
                                                <path d="M20 2 L37 8 V22 C37 33 29 41 20 44 C11 41 3 33 3 22 V8 Z" fill="url(#ba-shield-{{ $bomLine->id }})" stroke="{{ $shield[3] }}" stroke-width="1.5"/>
                                                <path d="M20 6 L33 10.5 V22 C33 31 27 37.5 20 40 C13 37.5 7 31 7 22 V10.5 Z" fill="none" stroke="rgba(255,255,255,.55)" stroke-width="1"/>
                                                <text x="20" y="28.5" text-anchor="middle" font-family="Georgia, serif" font-size="17" font-weight="700" fill="{{ $shield[3] }}">{{ $bomLine->item_type }}</text>
                                            </svg>
                                            <div class="ba-prod" data-label="Product">{{ $componentCode }}</div>
                                            <div class="ba-desc">{{ $bomLine->component_description }}</div>
                                            <div class="ba-out" data-label="Outstanding">{{ $outstandingForBatchDisplay }}</div>
                                            <div class="ba-alloc" data-label="Allocated">
                                                {{ $allocatedQtyDisplay }}
                                                @if ($allocatedLotCount > 0)
                                                    <small>{{ $allocatedLotCount }} lot{{ $allocatedLotCount === 1 ? '' : 's' }}</small>
                                                @endif
                                            </div>
                                            <div class="ba-act">
                                                <button
                                                    wire:click.stop="openAllocateModal({{ $bomLine->id }}, @js($componentCode), @js((string) $bomLine->component_description), @js($outstandingForBatchDisplay))"
                                                    type="button"
                                                    class="ba-btn">
                                                    {{ $itemType === 'C' && $this->editable ? 'Allocate' : 'View' }}
                                                </button>
                                            </div>
                                        </div>

                                        @if ($isOpenRow)
                                            <div class="ba-detail">
                                                    <div class="ba-detail-title">{{ $activeBomMaterialCode }} - {{ $activeBomMaterialDescription }}</div>

                                                    @if ($activeBomMessage)
                                                        <div class="ba-detail-msg">{{ $activeBomMessage }}</div>
                                                    @endif

                                                    @php
                                                        $expandedRows = $allocatedPreviewRows;

                                                        if ($expandedRows->isEmpty() && count($activeBomHistoricalLots) > 0) {
                                                            $expandedRows = collect($activeBomHistoricalLots)->map(static fn (array $history): array => [
                                                                'lot_id' => null,
                                                                'lot_number' => (string) $history['lot_number'],
                                                                'supplier_lot_number' => '—',
                                                                'quantity' => abs((float) $history['quantity']),
                                                                'uom' => 'kg',
                                                                'issue_status' => 'success',
                                                                'error_message' => '',
                                                                'weighed_by' => null,
                                                                'weighed_at' => null,
                                                                'tipped_by' => null,
                                                                'tipped_at' => null,
                                                            ]);
                                                        }
                                                    @endphp

                                                    <div class="ba-lots">
                                                        <div class="ba-lots-grid ba-lots-head">
                                                            <div>Allocated Lot</div>
                                                            <div>Supplier Lot</div>
                                                            <div class="ba-num">Qty</div>
                                                            <div>UOM</div>
                                                            <div>WinMan</div>
                                                        </div>
                                                        @forelse ($expandedRows as $row)
                                                            <div class="ba-lots-grid">
                                                                <div>{{ $row['lot_number'] }}</div>
                                                                <div>{{ $row['supplier_lot_number'] }}</div>
                                                                <div class="ba-num">{{ rtrim(rtrim((string) $row['quantity'], '0'), '.') }}</div>
                                                                <div>{{ $row['uom'] }}</div>
                                                                <div>
                                                                    @if ($row['issue_status'] === 'success')
                                                                        <span class="text-green-700">Issued</span>
                                                                    @elseif ($row['issue_status'] === 'rejected')
                                                                        <span class="text-amber-700" title="{{ $row['error_message'] }}">Rejected</span>
                                                                    @elseif ($row['issue_status'] === 'failed')
                                                                        <span class="text-red-700" title="{{ $row['error_message'] }}">Failed</span>
                                                                    @else
                                                                        <span class="text-gray-500">-</span>
                                                                    @endif
                                                                </div>
                                                            </div>
                                                        @empty
                                                            <div style="padding:16px 14px;text-align:center;color:#8a7a5c;">No allocations recorded yet for this BOM line.</div>
                                                        @endforelse
                                                    </div>
                                            </div>
                                        @endif
                                    @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($this->packingMode === 'pallecon')
                        <div class="ba-foot">
                            <button @click="tab = 'signoff'" type="button" class="ba-btn ba-btn--lg">Continue to Ingredients Sign Off →</button>
                        </div>
                    @endif
                </div>

                @if ($this->packingMode === 'pallecon')
                    <div x-show="tab === 'signoff'" class="space-y-6 p-6">
                        @php
                            $packingSignoffRows = $batch->ingredientLots
                                ->sortBy([
                                    ['material_code', 'asc'],
                                    ['material_description', 'asc'],
                                    ['lot_number', 'asc'],
                                    ['id', 'asc'],
                                ])
                                ->values();
                        @endphp

                        <div class="bg-white shadow-sm rounded-xl border border-slate-200 overflow-hidden">
                            <div style="padding:14px 18px;border-bottom:1px solid #dbe1ea;background:linear-gradient(180deg,#f8fafc 0%,#f1f5f9 100%);">
                                <h3 class="text-sm font-semibold text-slate-700">Ingredients Sign Off</h3>
                                <p class="text-xs text-slate-500 mt-1">Complete this tab before moving to Pallecon Packing.</p>
                            </div>

                            <div class="p-4">
                                @if ($packingSignoffRows->isEmpty())
                                    <p class="text-sm text-slate-500">No ingredient lots allocated yet. Complete Ingredient Allocation first.</p>
                                @else
                                    @php
                                        $submittedSignoff = $this->paperworkIngredientSignoff;
                                        // Badges must reflect the ACTUAL lot signatures, not stale paperwork names.
                                        $signoffTruthComplete = $this->ingredientSignoffComplete;
                                        $fallbackWeigher = $batch->ingredientLots->first(fn ($lot) => $lot->weighed_by !== null)?->weighedBy?->name;
                                        $fallbackTipper = $batch->ingredientLots->first(fn ($lot) => $lot->tipped_by !== null)?->tippedBy?->name;
                                    @endphp

                                    @if ($this->editable || $signoffTruthComplete)
                                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 mb-4">
                                            <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;">
                                                @foreach ([
                                                    'powdersWeighedOperatorId' => ['label' => 'Powders Weighed', 'class' => '', 'submitted_key' => 'powders'],
                                                    'liquidsWeighedOperatorId' => ['label' => 'Liquids Weighed', 'class' => '', 'submitted_key' => 'liquids'],
                                                    'tippingBatchOperatorId' => ['label' => 'Tipping Batch', 'class' => '', 'submitted_key' => 'tipping'],
                                                ] as $field => $signoff)
                                                    @php
                                                        $submittedName = trim((string) ($submittedSignoff[$signoff['submitted_key']] ?? ''));
                                                        if ($submittedName === '') {
                                                            $submittedName = (string) ($signoff['submitted_key'] === 'tipping' ? $fallbackTipper : $fallbackWeigher);
                                                        }
                                                    @endphp
                                                    <div class="{{ $signoff['class'] }}">
                                                        <label class="block text-xs text-slate-600 mb-1">{{ $signoff['label'] }}</label>
                                                        @if ($signoffTruthComplete)
                                                            <div class="flex min-h-[38px] items-center rounded-md border border-emerald-300 bg-emerald-50 px-3 text-sm text-emerald-800 shadow-sm">
                                                                <span class="flex items-center gap-2 font-semibold">
                                                                    <span class="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-500 text-xs text-white" aria-hidden="true">✓</span>
                                                                    Submitted <span class="font-normal text-emerald-700">{{ $submittedName }}</span>
                                                                </span>
                                                            </div>
                                                        @else
                                                            {{-- Keep the dropdowns editable right up to Submit - no intermediate "Selected" step. --}}
                                                            <select wire:model="{{ $field }}" class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                                                                <option value="">Select operator</option>
                                                                @foreach ($this->signoffOperators as $operator)
                                                                    <option value="{{ $operator->id }}" @selected((string) $this->{$field} === (string) $operator->id)>{{ $operator->name }}</option>
                                                                @endforeach
                                                            </select>
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>

                                            @if ($this->editable)
                                                <div class="mt-3 flex items-center justify-end gap-3" x-data="{
                                                    state: 'idle',
                                                    successTimer: null,
                                                    submit() { this.state = 'loading'; },
                                                    failed() { this.state = 'idle'; },
                                                    submitted() {
                                                        this.state = 'success';
                                                        clearTimeout(this.successTimer);
                                                        this.successTimer = setTimeout(() => { this.state = 'idle'; }, 1700);
                                                    },
                                                }" x-on:ingredient-signoff-submitted.window="submitted()" x-on:ingredient-signoff-failed.window="failed()">
                                                    @if ($signoffTruthComplete)
                                                        <button type="button" wire:click="resetIngredientSignoff" wire:loading.attr="disabled" class="inline-flex h-10 items-center rounded-md border border-amber-300 bg-amber-50 px-4 text-sm font-semibold text-amber-800 hover:bg-amber-100" title="Reset clears the saved sign-off so operators can be reselected; the reset and resubmission are both audited.">Reset sign-off</button>
                                                    @else
                                                        <button type="button" wire:click="applyBulkIngredientSignoff" @click="submit()" wire:loading.attr="disabled" wire:target="applyBulkIngredientSignoff" class="ingredient-signoff-submit inline-flex h-10 min-w-[220px] items-center justify-center overflow-hidden rounded-md px-4 text-sm font-semibold text-white" x-bind:class="state === 'success' ? 'ingredient-signoff-submit--success' : (state === 'loading' ? 'ingredient-signoff-submit--loading' : 'ingredient-signoff-submit--idle')">
                                                            <span x-show="state === 'idle'" x-transition.opacity.duration.150ms>Submit Ingredients Sign Off</span>
                                                            <span x-cloak x-show="state === 'loading'" x-transition.opacity.duration.150ms class="flex items-center gap-2">
                                                                <span class="ingredient-signoff-spinner"></span>
                                                                Submitting
                                                            </span>
                                                            <span x-cloak x-show="state === 'success'" x-transition.opacity.duration.150ms class="flex items-center gap-2">
                                                                <span class="ingredient-signoff-check" aria-hidden="true">✓</span>
                                                                Submitted
                                                            </span>
                                                        </button>
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                    @endif

                                    <div class="mt-4 flex flex-col items-end gap-2">
                                        @if (! $this->ingredientSignoffComplete)
                                            <div class="w-full rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-amber-900" style="animation:ingredient-signoff-pulse 1.7s ease-in-out infinite;">
                                                <div class="flex items-center gap-3">
                                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-amber-500 text-base font-black text-white" aria-hidden="true">!</span>
                                                    <span class="text-sm font-bold">Make sure you signed off all ingredients before completing batch.</span>
                                                </div>
                                            </div>
                                        @endif

                                        @if ($this->editable)
                                            <button
                                                type="button"
                                                wire:click="complete"
                                                @disabled(! $this->ingredientSignoffComplete)
                                                class="inline-flex items-center rounded-full px-4 py-2 text-sm font-semibold {{ $this->ingredientSignoffComplete ? 'bg-emerald-600 text-white hover:bg-emerald-500' : 'bg-slate-200 text-slate-500 cursor-not-allowed' }}"
                                            >
                                                Complete Batch &amp; Return to MO →
                                            </button>
                                            <p class="text-xs text-slate-500">Pallecon filling, WinMan booking and labels happen in the Pallecon Workspace on the MO screen.</p>
                                        @endif
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
                            isMobileViewport: false,
                            scanRaw: @entangle('activeBomGrScanRaw').live,
                            scanReviewOpen: false,
                            scanPreview: {
                                productId: '',
                                productDescription: '',
                                supplierLotNumber: '',
                                quantity: '',
                                location: '',
                                line: '',
                                productionDate: '',
                                expiryDate: '',
                                notes: '',
                                valid: false,
                            },
                            cameraRunning: false,
                            scannerError: '',
                            stream: null,
                            detector: null,
                            scanTimer: null,
                            applyMobileDefaultMode() {
                                if (this.isMobileViewport && this.scannerEnabled) {
                                    this.mode = 'gr_scan';
                                }
                            },
                            init() {
                                this.isMobileViewport = window.matchMedia('(max-width: 767px)').matches;

                                const viewportListener = (event) => {
                                    this.isMobileViewport = event.matches;
                                    if (event.matches && this.modalVisible) {
                                        this.applyMobileDefaultMode();
                                    }
                                };

                                const mediaQuery = window.matchMedia('(max-width: 767px)');
                                if (typeof mediaQuery.addEventListener === 'function') {
                                    mediaQuery.addEventListener('change', viewportListener);
                                } else if (typeof mediaQuery.addListener === 'function') {
                                    mediaQuery.addListener(viewportListener);
                                }

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

                                    this.applyMobileDefaultMode();

                                    if (value && this.mode === 'gr_scan' && this.scannerEnabled && this.cameraAutostartEnabled) {
                                        await this.startCamera();
                                    }
                                });

                                this.applyMobileDefaultMode();

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
                                        this.stopCamera();
                                        this.reviewCurrentScan();
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
                            parsePreview(raw) {
                                const value = (raw ?? '').trim();
                                const segments = value.split('^').map((entry) => entry.trim());
                                const productId = segments[0] ?? '';
                                const productDescription = segments[1] ?? '';
                                const supplierLotNumber = segments[2] ?? '';
                                const metadata = segments.slice(3).join(' ').trim();
                                const fullText = [supplierLotNumber, metadata].filter(Boolean).join(' ');

                                const read = (pattern) => {
                                    const match = fullText.match(pattern);
                                    return (match?.[1] ?? '').trim();
                                };

                                return {
                                    productId,
                                    productDescription,
                                    supplierLotNumber,
                                    quantity: read(/(?:^|\b)(?:qty|quantity)\s*[:=]\s*([^\s^,;]+)/i),
                                    location: read(/(?:^|\b)(?:loc|location|bin|warehouse|wh)\s*[:=]\s*([^\^,;]+)/i),
                                    line: read(/(?:^|\b)line\s*[:=]\s*([^\s^,;]+)/i),
                                    productionDate: read(/(?:^|\b)(?:pd|prod(?:uction)?\s*date)\s*[:=]\s*([^\s^,;]+)/i),
                                    expiryDate: read(/(?:^|\b)(?:ed|exp(?:iry)?\s*date|bb(?:e)?)\s*[:=]\s*([^\s^,;]+)/i),
                                    notes: metadata,
                                    valid: productId !== '' && productDescription !== '' && supplierLotNumber !== '',
                                };
                            },
                            reviewCurrentScan() {
                                this.scannerError = '';
                                this.scanPreview = this.parsePreview(this.scanRaw);

                                if (!this.scanPreview.valid) {
                                    this.scannerError = 'Invalid scan format. Expected ProductID^ProductDescription^SupplierLotNumber^';
                                    this.scanReviewOpen = false;
                                    return;
                                }

                                this.scanReviewOpen = true;
                            },
                            async confirmScanReview() {
                                if (!this.scanPreview.valid) {
                                    return;
                                }

                                this.scanReviewOpen = false;
                                await this.$wire.applyGrScanPayload();
                            },
                            retryScanReview() {
                                this.scanReviewOpen = false;
                                this.scanRaw = '';
                                this.scannerError = '';

                                if (this.scannerEnabled && this.cameraAutostartEnabled) {
                                    this.startCamera();
                                }
                            },
                            cancelScanReview() {
                                this.scanReviewOpen = false;
                            },
                        }"
                    >
                        <div class="fixed inset-0 bg-gray-500/70" wire:click="closeAllocateModal"></div>
                        <div class="relative mb-6 bg-white rounded-lg overflow-hidden shadow-xl transform transition-all sm:w-full sm:max-w-xl sm:mx-auto">
                            <div class="px-6 py-4 border-b border-gray-200">
                                <h3 class="text-lg font-semibold text-gray-900">Allocate</h3>
                                <p class="mt-1 text-sm text-gray-600">{{ $activeBomMaterialCode }} - {{ $activeBomMaterialDescription }}</p>
                            </div>

                            <div class="px-6 py-4 space-y-4" :class="{ 'pointer-events-none opacity-50': scanReviewOpen }">
                                <div>
                                    <label class="block text-xs text-gray-600 mb-1">Allocation view</label>
                                    <div class="inline-flex rounded-md border border-gray-300 overflow-hidden" x-show="!isMobileViewport">
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
                                    <div class="rounded-lg border border-slate-200 bg-white p-4 space-y-4 shadow-sm">
                                        <div class="flex items-center justify-between gap-2">
                                            <p class="text-xs font-semibold tracking-wide uppercase text-slate-700">QR Scanner</p>
                                            <div class="flex items-center gap-2">
                                                <x-secondary-button type="button" x-show="!cameraRunning" @click="startCamera">Open camera</x-secondary-button>
                                                <x-secondary-button type="button" x-show="cameraRunning" @click="stopCamera">Stop camera</x-secondary-button>
                                            </div>
                                        </div>

                                        <div class="rounded-md border border-slate-300 bg-black/90 overflow-hidden" x-show="cameraRunning" x-transition>
                                            <video x-ref="grScanVideo" playsinline muted autoplay class="block w-full h-52 object-cover"></video>
                                        </div>

                                        <div x-show="scannerError" class="text-xs text-rose-700" x-text="scannerError"></div>

                                        <p class="text-xs text-slate-500">Point camera to the QR code. A confirmation popup will appear after a successful read.</p>

                                        @if ($featureAllocationScanDebugEnabled && $activeBomScanDebug)
                                            <details class="rounded-md border border-slate-200 bg-slate-50 px-2 py-1">
                                                <summary class="cursor-pointer text-xs text-slate-600">Technical scan details</summary>
                                                <div class="mt-2 text-xs text-slate-700">
                                                    {{ $activeBomScanDebug }}
                                                </div>
                                            </details>
                                        @endif

                                        @if ($activeBomWinManLookupMessage)
                                            <div class="text-xs text-slate-800 bg-white/80 border border-slate-200 rounded px-2 py-1">
                                                {{ $activeBomWinManLookupMessage }}
                                            </div>
                                        @endif

                                        <div class="pt-1" x-show="isMobileViewport">
                                            <button
                                                type="button"
                                                wire:click="setAllocationMode('manual')"
                                                class="text-xs text-slate-600 underline decoration-slate-400 underline-offset-2"
                                            >
                                                Switch to Manual Lot Allocation
                                            </button>
                                        </div>
                                    </div>
                                @endif

                                <div
                                    x-show="scanReviewOpen"
                                    x-cloak
                                    class="fixed inset-0 z-[100] flex items-center justify-center px-4"
                                >
                                    <div class="absolute inset-0 bg-slate-900/45" @click="cancelScanReview"></div>
                                    <div class="relative w-full max-w-lg rounded-xl bg-white border border-slate-200 shadow-2xl overflow-hidden">
                                        <div class="px-5 py-4 border-b border-slate-200 bg-slate-50">
                                            <h4 class="text-sm font-semibold text-slate-900">Confirm scanned QR</h4>
                                            <p class="mt-1 text-xs text-slate-600">Please check the extracted details before confirming.</p>
                                        </div>

                                        <div class="px-5 py-4 space-y-3 text-sm">
                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                <div>
                                                    <p class="text-xs text-slate-500">Product ID</p>
                                                    <p class="font-medium text-slate-900" x-text="scanPreview.productId || '—'"></p>
                                                </div>
                                                <div>
                                                    <p class="text-xs text-slate-500">Supplier Lot</p>
                                                    <p class="font-medium text-slate-900" x-text="scanPreview.supplierLotNumber || '—'"></p>
                                                </div>
                                            </div>

                                            <div>
                                                <p class="text-xs text-slate-500">Description</p>
                                                <p class="font-medium text-slate-900" x-text="scanPreview.productDescription || '—'"></p>
                                            </div>

                                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                                <div>
                                                    <p class="text-xs text-slate-500">Qty</p>
                                                    <p class="font-medium text-slate-900" x-text="scanPreview.quantity || '—'"></p>
                                                </div>
                                                <div>
                                                    <p class="text-xs text-slate-500">Location</p>
                                                    <p class="font-medium text-slate-900" x-text="scanPreview.location || '—'"></p>
                                                </div>
                                                <div>
                                                    <p class="text-xs text-slate-500">Line</p>
                                                    <p class="font-medium text-slate-900" x-text="scanPreview.line || '—'"></p>
                                                </div>
                                                <div>
                                                    <p class="text-xs text-slate-500">Expiry</p>
                                                    <p class="font-medium text-slate-900" x-text="scanPreview.expiryDate || '—'"></p>
                                                </div>
                                            </div>

                                            <template x-if="scanPreview.notes">
                                                <div class="rounded-md bg-slate-50 border border-slate-200 px-3 py-2">
                                                    <p class="text-[11px] text-slate-500 mb-1">Additional payload</p>
                                                    <p class="text-xs text-slate-700 break-words" x-text="scanPreview.notes"></p>
                                                </div>
                                            </template>
                                        </div>

                                        <div class="px-5 py-4 border-t border-slate-200 flex items-center justify-end gap-2">
                                            <x-secondary-button type="button" @click="cancelScanReview">Cancel</x-secondary-button>
                                            <x-secondary-button type="button" @click="retryScanReview">Retry</x-secondary-button>
                                            <x-primary-button type="button" @click="confirmScanReview">Confirm</x-primary-button>
                                        </div>
                                    </div>
                                </div>

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

                                    <div x-show="isMobileViewport && scannerEnabled">
                                        <button
                                            type="button"
                                            wire:click="setAllocationMode('gr_scan')"
                                            class="text-xs text-slate-600 underline decoration-slate-400 underline-offset-2"
                                        >
                                            Back to Scanner
                                        </button>
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

                            <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-2" :class="{ 'hidden': scanReviewOpen }">
                                <x-secondary-button type="button" wire:click="closeAllocateModal">Cancel</x-secondary-button>
                                <x-primary-button type="button" wire:click="allocateBomIngredient" :disabled="count($activeBomLotOptions) === 0">Allocate</x-primary-button>
                            </div>
                        </div>
                    </div>
                @endif

            </div>
        </div>

        @php
            $labelPrintHistory = $this->labelPrintHistory;
            $winmanBookingHistory = $this->winmanBookingHistory;
        @endphp

        @if ($labelPrintHistory->isNotEmpty() || $winmanBookingHistory->isNotEmpty())
            <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden" x-data="{ expandedLabel: null }">
                <div style="padding:14px 18px;border-bottom:1px solid #dbe1ea;background:linear-gradient(180deg,#f8fafc 0%,#f1f5f9 100%);">
                    <div class="text-base font-semibold text-gray-800">Label &amp; WinMan History</div>
                    <p class="text-xs text-slate-500 mt-1">View only - what was printed for this batch's pallecon(s) and what was sent to WinMan when finished goods were booked.</p>
                </div>

                <div class="p-4 md:p-6 space-y-6">
                    @if ($labelPrintHistory->isNotEmpty())
                        <div>
                            <h3 class="text-sm font-semibold text-slate-700 mb-2">Labels Printed</h3>
                            <div class="border border-slate-200 rounded-lg overflow-hidden">
                                <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead class="text-left text-xs text-slate-500 uppercase bg-slate-50">
                                        <tr>
                                            <th class="px-3 py-2">Printed</th>
                                            <th class="px-3 py-2">Pallecon</th>
                                            <th class="px-3 py-2 text-right">Weight (kg)</th>
                                            <th class="px-3 py-2">Status</th>
                                            <th class="px-3 py-2">Printed By</th>
                                            <th class="px-3 py-2 text-right"></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($labelPrintHistory as $log)
                                            <tr class="cursor-pointer hover:bg-indigo-50/40" @click="expandedLabel = expandedLabel === {{ $log->id }} ? null : {{ $log->id }}">
                                                <td class="px-3 py-2 whitespace-nowrap">{{ $log->printed_at?->format('d/m/Y H:i') }}</td>
                                                <td class="px-3 py-2">{{ $log->pallecon?->serial_number ?? '—' }}</td>
                                                <td class="px-3 py-2 text-right">{{ $log->fill_weight !== null ? rtrim(rtrim((string) $log->fill_weight, '0'), '.') : '—' }}</td>
                                                <td class="px-3 py-2">
                                                    @if ($log->status === \App\Models\LabelPrintLog::STATUS_SUCCESS)
                                                        <span class="text-green-700 font-semibold">Printed</span>
                                                    @else
                                                        <span class="text-red-700 font-semibold" title="{{ $log->error_message }}">Failed</span>
                                                    @endif
                                                </td>
                                                <td class="px-3 py-2">{{ $log->printedBy?->name ?? '—' }}</td>
                                                <td class="px-3 py-2 text-right text-xs text-indigo-600">Details</td>
                                            </tr>
                                            <tr x-show="expandedLabel === {{ $log->id }}" class="bg-indigo-50/40">
                                                <td colspan="6" class="px-3 py-3">
                                                    @if ($log->status !== \App\Models\LabelPrintLog::STATUS_SUCCESS && $log->error_message)
                                                        <div class="text-xs text-red-700 mb-2">{{ $log->error_message }}</div>
                                                    @endif
                                                    @if (is_array($log->label_data) && $log->label_data !== [])
                                                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-x-4 gap-y-1 text-xs">
                                                            @foreach ($log->label_data as $field => $value)
                                                                @if ($value !== null && $value !== '')
                                                                    <div>
                                                                        <span class="text-slate-400">{{ $field }}:</span>
                                                                        <span class="text-slate-700">{{ is_scalar($value) ? $value : json_encode($value) }}</span>
                                                                    </div>
                                                                @endif
                                                            @endforeach
                                                        </div>
                                                    @else
                                                        <div class="text-xs text-slate-400">No label data recorded for this print.</div>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                </div>
                            </div>
                        </div>
                    @endif

                    @if ($winmanBookingHistory->isNotEmpty())
                        <div>
                            <h3 class="text-sm font-semibold text-slate-700 mb-2">Sent to WinMan</h3>
                            <div class="border border-slate-200 rounded-lg overflow-hidden">
                                <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead class="text-left text-xs text-slate-500 uppercase bg-slate-50">
                                        <tr>
                                            <th class="px-3 py-2">Booked</th>
                                            <th class="px-3 py-2">Pallecon</th>
                                            <th class="px-3 py-2">Lot Number</th>
                                            <th class="px-3 py-2 text-right">Qty (kg)</th>
                                            <th class="px-3 py-2 text-right">Qty (TU)</th>
                                            <th class="px-3 py-2">Inventory ID</th>
                                            <th class="px-3 py-2">Status</th>
                                            <th class="px-3 py-2">Booked By</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($winmanBookingHistory as $log)
                                            <tr>
                                                <td class="px-3 py-2 whitespace-nowrap">{{ $log->booking_date?->format('d/m/Y H:i') }}</td>
                                                <td class="px-3 py-2">{{ $log->pallecon?->serial_number ?? '—' }}</td>
                                                <td class="px-3 py-2 font-mono text-xs">{{ $log->lot_number ?? '—' }}</td>
                                                <td class="px-3 py-2 text-right">{{ $log->quantity_booked_kg !== null ? rtrim(rtrim((string) $log->quantity_booked_kg, '0'), '.') : '—' }}</td>
                                                <td class="px-3 py-2 text-right">{{ $log->quantity_booked_traded_units !== null ? rtrim(rtrim((string) $log->quantity_booked_traded_units, '0'), '.') : '—' }}</td>
                                                <td class="px-3 py-2">{{ $log->winman_inventory_id ?? '—' }}</td>
                                                <td class="px-3 py-2">
                                                    @if ($log->booking_status === \App\Models\WinManBookingLog::STATUS_SUCCESS)
                                                        <span class="text-green-700 font-semibold">Success</span>
                                                    @elseif ($log->booking_status === \App\Models\WinManBookingLog::STATUS_REJECTED)
                                                        <span class="text-amber-700 font-semibold" title="{{ $log->error_message }}">Rejected</span>
                                                    @else
                                                        <span class="text-red-700 font-semibold" title="{{ $log->error_message }}">Failed</span>
                                                    @endif
                                                </td>
                                                <td class="px-3 py-2">{{ $log->booking_user ?? '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
