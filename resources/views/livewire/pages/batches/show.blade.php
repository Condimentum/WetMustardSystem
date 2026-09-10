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
use App\Models\PaperworkRow;
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
                            <a href="{{ route($this->packingRoute, $batch) }}" wire:navigate style="display:inline-flex;align-self:stretch;align-items:center;gap:8px;padding:0 18px;border-radius:8px;border:2px solid #cbd5e1;background:#fff;color:#334155;font-size:14px;font-weight:800;letter-spacing:.01em;line-height:1;text-decoration:none;white-space:nowrap;">
                                <span aria-hidden="true" style="font-size:14px;line-height:1;">&#129520;</span>
                                <span>{{ $label }}</span>
                            </a>
                        @else
                            <button
                                @click="tab = '{{ $key }}'"
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
                        <button @click="tab = 'allocation'" class="inline-flex items-center rounded-full bg-indigo-600 text-white text-sm font-semibold px-4 py-2 hover:bg-indigo-500">Continue to Ingredient Allocation →</button>
                    </div>
                </div>

                {{-- Unified Allocation Workspace --}}
                <div x-show="tab === 'allocation'" class="space-y-3">

                    @if ($winManDown)
                        <x-winman-offline-banner message="WinMan connection is currently unavailable. Scanned/entered lots are accepted without live verification and issues will be attempted when you allocate - double-check quantities carefully." />
                    @endif

                    @if ($batch->componentSnapshots->isEmpty())
                        <div style="background:#fff7ed;border:1px solid #fdba74;color:#9a3412;border-radius:12px;padding:12px 14px;font-size:14px;font-weight:600;">No component snapshot stored for this MO.</div>
                    @else
                        <div style="background:#fff;border:1px solid #dbe1ea;border-radius:16px;overflow:hidden;box-shadow:0 1px 2px rgba(15,23,42,0.05);">
                            <div class="overflow-x-auto">
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

                                                    <div class="border border-indigo-100 rounded-lg overflow-hidden bg-white">
                                                        <div class="overflow-x-auto">
                                                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                                                            <thead class="text-left text-xs text-slate-500 uppercase bg-slate-50">
                                                                <tr>
                                                                    <th class="px-3 py-2">Allocated Lot</th>
                                                                    <th class="px-3 py-2">Supplier Lot</th>
                                                                    <th class="px-3 py-2 text-right">Qty</th>
                                                                    <th class="px-3 py-2">UOM</th>
                                                                    <th class="px-3 py-2">WinMan</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody class="divide-y divide-gray-100">
                                                                @forelse ($expandedRows as $row)
                                                                    <tr>
                                                                        <td class="px-3 py-2">{{ $row['lot_number'] }}</td>
                                                                        <td class="px-3 py-2">{{ $row['supplier_lot_number'] }}</td>
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
                                                                    </tr>
                                                                @empty
                                                                    <tr>
                                                                        <td colspan="5" class="px-3 py-4 text-center text-gray-500">No allocations recorded yet for this BOM line.</td>
                                                                    </tr>
                                                                @endforelse
                                                            </tbody>
                                                        </table>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>
                            </div>
                        </div>
                    @endif

                    @if ($this->packingMode === 'pallecon')
                        <div class="px-6 pb-6">
                            <button @click="tab = 'signoff'" type="button" class="inline-flex items-center rounded-full bg-indigo-600 text-white text-sm font-semibold px-4 py-2 hover:bg-indigo-500">Continue to Ingredients Sign Off →</button>
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
                                    @if ($this->editable)
                                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 mb-4">
                                            <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;">
                                                @php
                                                    $submittedSignoff = $this->paperworkIngredientSignoff;
                                                    // Badges must reflect the ACTUAL lot signatures, not stale paperwork names.
                                                    $signoffTruthComplete = $this->ingredientSignoffComplete;
                                                    $fallbackWeigher = $batch->ingredientLots->first(fn ($lot) => $lot->weighed_by !== null)?->weighedBy?->name;
                                                    $fallbackTipper = $batch->ingredientLots->first(fn ($lot) => $lot->tipped_by !== null)?->tippedBy?->name;
                                                @endphp
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
    </div>
</div>
