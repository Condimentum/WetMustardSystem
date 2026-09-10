<?php

use App\Domains\Pallecon\Support\PalleconCapacity;
use App\Features\Booking\BookFinishedGoodsFeature;
use App\Features\Pallecon\AttachBatchFillFeature;
use App\Features\Pallecon\OpenPalleconFeature;
use App\Features\Pallecon\PrintPalleconLabelFeature;
use App\Features\Pallecon\SealPalleconFeature;
use App\Models\BatchRecord;
use App\Models\ManufacturingOrder;
use App\Models\Pallecon;
use App\Models\PalleconRecord;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Pallecon Workspace')] class extends Component {
    public int $winmanMo;

    public ?ManufacturingOrder $localOrder = null;

    public bool $showNewForm = false;

    /** @var array<string, string> */
    public array $newForm = [
        'ticket_number' => '',
        'top_seal_number' => '',
        'bottom_seal_number' => '',
        'liner_number' => '',
        'liner_batch_code' => '',
    ];

    public string $fillBatchId = '';
    public string $fillPalleconId = '';
    public string $fillWeight = '';
    public string $production_date = '';

    public ?int $sealingId = null;

    /** @var array<string, string> */
    public array $sealForm = [
        'final_weight' => '',
        'top_seal_number' => '',
        'bottom_seal_number' => '',
    ];

    public ?string $flash = null;
    public bool $flashError = false;

    /** @var array<string, mixed>|null */
    public ?array $winman_booking_preview = null;

    public function mount(int $winmanMo): void
    {
        $this->winmanMo = $winmanMo;
        $this->localOrder = ManufacturingOrder::query()
            ->where('winman_manufacturing_order', $winmanMo)
            ->first();
        $this->production_date = now()->toDateString();
    }

    #[Computed]
    public function limitKg(): float
    {
        return PalleconCapacity::limitKg();
    }

    #[Computed]
    public function bartenderEnabled(): bool
    {
        return (bool) config('services.bartender.enabled', false);
    }

    /**
     * Sealed/consumed pallecons whose every fill comes from a batch of THIS MO.
     * Shared containers (with a fill from another MO) are intentionally hidden.
     *
     * @return \Illuminate\Support\Collection<int, Pallecon>
     */
    #[Computed]
    public function completedContainers()
    {
        $moId = (int) ($this->localOrder?->id ?? 0);

        if ($moId <= 0) {
            return collect();
        }

        return Pallecon::query()
            ->whereIn('status', [Pallecon::STATUS_SEALED, Pallecon::STATUS_CONSUMED])
            ->with(['fills.batchRecord:id,batch_number,manufacturing_order_id'])
            ->orderByDesc('sealed_at')
            ->get()
            ->filter(function (Pallecon $pallecon) use ($moId): bool {
                if ($pallecon->fills->isEmpty()) {
                    return false;
                }

                return $pallecon->fills->every(
                    fn ($fill): bool => (int) ($fill->batchRecord?->manufacturing_order_id ?? 0) === $moId
                );
            })
            ->take(15)
            ->values();
    }

    /**
     * Batches of this MO as fill sources, with per-batch planned/filled/remaining
     * quantities. "remaining" is the hard cap for further fills from that batch.
     *
     * @return array<int, array{id:int, batch_number:string, status:string, signoff_complete:bool, planned_kg:float, filled_kg:float, remaining_kg:float}>
     */
    #[Computed]
    public function moBatches(): array
    {
        if ($this->localOrder === null) {
            return [];
        }

        $batches = BatchRecord::query()
            ->where('manufacturing_order_id', $this->localOrder->id)
            ->whereNotIn('status', [BatchRecord::STATUS_CANCELLED])
            ->orderBy('id')
            ->get();

        // Sign-off is a batch-level confirmation (powders/liquids/tipping names
        // recorded once per batch), not per-lot signatures.
        $confirmationCounts = \App\Models\PaperworkRow::query()
            ->whereIn('batch_record_id', $batches->pluck('id'))
            ->whereIn('row_key', [
                'ingredients_signoff.powders_weighed_by',
                'ingredients_signoff.liquids_weighed_by',
                'ingredients_signoff.tipping_batch_by',
            ])
            ->whereNotNull('value_text')
            ->where('value_text', '<>', '')
            ->selectRaw('batch_record_id, count(*) as confirmations')
            ->groupBy('batch_record_id')
            ->pluck('confirmations', 'batch_record_id');

        $filledByBatch = \App\Models\PalleconFill::query()
            ->whereIn('batch_record_id', $batches->pluck('id'))
            ->selectRaw('batch_record_id, COALESCE(SUM(fill_weight), 0) as filled')
            ->groupBy('batch_record_id')
            ->pluck('filled', 'batch_record_id');

        return $batches
            ->map(function (BatchRecord $batch) use ($confirmationCounts, $filledByBatch): array {
                $planned = (float) ($batch->planned_quantity ?? 0);
                $filled = (float) ($filledByBatch[$batch->id] ?? 0);

                return [
                    'id' => $batch->id,
                    'batch_number' => (string) $batch->batch_number,
                    'status' => (string) $batch->status,
                    'signoff_complete' => (int) ($confirmationCounts[$batch->id] ?? 0) >= 3,
                    'planned_kg' => $planned,
                    'filled_kg' => $filled,
                    'remaining_kg' => max($planned - $filled, 0.0),
                ];
            })
            ->all();
    }

    /** @return \Illuminate\Support\Collection<int, Pallecon> */
    #[Computed]
    public function openContainers()
    {
        return Pallecon::query()
            ->whereIn('status', [Pallecon::STATUS_OPEN, Pallecon::STATUS_FILLING])
            ->with(['fills.batchRecord:id,batch_number'])
            ->orderBy('opened_at')
            ->get();
    }

    public function openPallecon(): void
    {
        $validated = $this->validate([
            'newForm.ticket_number' => ['required', 'string', 'max:255'],
            'newForm.top_seal_number' => ['nullable', 'string', 'max:255'],
            'newForm.bottom_seal_number' => ['nullable', 'string', 'max:255'],
            'newForm.liner_number' => ['nullable', 'string', 'max:255'],
            'newForm.liner_batch_code' => ['nullable', 'string', 'max:255'],
        ])['newForm'];

        try {
            $pallecon = app(OpenPalleconFeature::class)([
                'serial_number' => $validated['ticket_number'],
                'top_seal_number' => $validated['top_seal_number'] ?: null,
                'bottom_seal_number' => $validated['bottom_seal_number'] ?: null,
                'liner_number' => $validated['liner_number'] ?: null,
                'liner_batch_code' => $validated['liner_batch_code'] ?: null,
                'mo_number' => $this->localOrder?->mo_number,
            ], auth()->user());

            $this->flash = 'Pallecon '.$pallecon->serial_number.' opened. Now add batch fills to it.';
            $this->flashError = false;
            $this->reset('newForm');
            $this->showNewForm = false;
            $this->fillPalleconId = (string) $pallecon->id;
        } catch (\Throwable $e) {
            $this->flash = $e->getMessage();
            $this->flashError = true;
        }

        unset($this->openContainers);
    }

    public function addFill(): void
    {
        $this->validate([
            'fillBatchId' => ['required', 'integer'],
            'fillPalleconId' => ['required', 'integer'],
            'fillWeight' => ['required', 'numeric', 'min:0.001'],
        ]);

        $this->flash = null;
        $this->winman_booking_preview = null;

        $batchMeta = collect($this->moBatches)->firstWhere('id', (int) $this->fillBatchId);

        if ($batchMeta === null) {
            $this->flash = 'Selected batch does not belong to this manufacturing order.';
            $this->flashError = true;

            return;
        }

        if (! $batchMeta['signoff_complete']) {
            $this->flash = 'Batch '.$batchMeta['batch_number'].' has not completed Ingredients Sign Off yet.';
            $this->flashError = true;

            return;
        }

        // The batch planned quantity is a hard cap - total fills from a batch
        // can never exceed it. Only enforced when a planned quantity is on record.
        $batchRemaining = (float) ($batchMeta['remaining_kg'] ?? 0);
        if ((float) ($batchMeta['planned_kg'] ?? 0) > 0 && (float) $this->fillWeight > $batchRemaining + 0.0001) {
            $this->flash = sprintf(
                'Only %s kg remaining on batch %s - the fill weight cannot exceed it.',
                rtrim(rtrim(number_format($batchRemaining, 3, '.', ''), '0'), '.') ?: '0',
                $batchMeta['batch_number'],
            );
            $this->flashError = true;

            return;
        }

        $batch = BatchRecord::with('manufacturingOrder', 'product')->findOrFail((int) $this->fillBatchId);

        try {
            $container = Pallecon::query()
                ->whereIn('status', [Pallecon::STATUS_OPEN, Pallecon::STATUS_FILLING])
                ->findOrFail((int) $this->fillPalleconId);

            $fill = app(AttachBatchFillFeature::class)($container, $batch, [
                'fill_weight' => $this->fillWeight,
            ], auth()->user());
        } catch (\Throwable $e) {
            $this->flash = $e->getMessage();
            $this->flashError = true;

            return;
        }

        $messages = ['Fill of '.$this->fillWeight.' kg from batch '.$batch->batch_number.' recorded against pallecon '.($container->serial_number ?? '#'.$container->id).'.'];
        $this->flashError = false;

        // Per-fill WinMan booking (unchanged wire protocol): book this fill's
        // weight to the source batch's MO.
        if ((bool) config('winman.booking.enabled', false)) {
            try {
                $bookedQuantity = (float) ($fill->fill_weight ?? 0);

                if ($bookedQuantity > 0) {
                    $palleconLike = new PalleconRecord([
                        'mo_number' => $batch->manufacturingOrder?->mo_number,
                        'ticket_number' => $container->serial_number,
                        'serial_number' => $container->serial_number,
                        'fill_weight' => $bookedQuantity,
                    ]);
                    $palleconLike->setRelation('batchRecord', $batch);
                    $palleconLike->id = $container->id;

                    $finished = now();
                    $expiry = $this->resolveBookingExpiryDate($batch, $palleconLike, $finished);
                    $lotNumber = $this->resolveWinManLotNumber($batch, $palleconLike);
                    $requestPreview = [
                        'manufacturing_order_id' => (string) ($batch->manufacturingOrder?->winman_manufacturing_order_id ?? $palleconLike->mo_number ?? ''),
                        'manufacturing_order_internal' => (int) ($batch->manufacturingOrder?->winman_manufacturing_order ?? 0),
                        'product_id' => (string) ($batch->manufacturingOrder?->winman_product_id ?? ''),
                        'quantity_kg' => $bookedQuantity,
                        'lot_number' => $lotNumber,
                        'finished_date' => $finished->format('Y-m-d H:i:s'),
                        'expiry_date' => $expiry->format('Y-m-d H:i:s'),
                        'pallecon_number' => (string) ($container->serial_number ?? ''),
                    ];

                    $log = app(BookFinishedGoodsFeature::class)(
                        $batch,
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
                        $messages[] = 'WinMan inventory created (Inventory '.($log->winman_inventory_id ?? '—').').';
                    } else {
                        $this->flashError = true;
                        $messages[] = 'WinMan booking '.$log->booking_status.': '.($log->error_message ?: 'unknown error').'.';
                    }
                }
            } catch (\Throwable $e) {
                $this->flashError = true;
                $this->winman_booking_preview = [
                    'booking_status' => 'failed',
                    'error_message' => $e->getMessage(),
                ];
                $messages[] = 'Fill saved, but WinMan booking failed: '.$e->getMessage();
            }
        }

        $this->flash = implode(' ', $messages);
        $this->fillWeight = '';
        unset($this->openContainers, $this->moBatches);
    }

    public function startSeal(int $palleconId): void
    {
        $pallecon = Pallecon::findOrFail($palleconId);
        $this->sealingId = $palleconId;
        $this->sealForm = [
            'final_weight' => (string) ($pallecon->filledWeight() ?: ''),
            'top_seal_number' => (string) ($pallecon->top_seal_number ?? ''),
            'bottom_seal_number' => (string) ($pallecon->bottom_seal_number ?? ''),
        ];
    }

    public function cancelSeal(): void
    {
        $this->sealingId = null;
        $this->reset('sealForm');
    }

    public function completePallecon(): void
    {
        if ($this->sealingId === null) {
            return;
        }

        $validated = $this->validate([
            'sealForm.final_weight' => ['required', 'numeric', 'min:0.001'],
            'sealForm.top_seal_number' => ['nullable', 'string', 'max:255'],
            'sealForm.bottom_seal_number' => ['nullable', 'string', 'max:255'],
        ])['sealForm'];

        $pallecon = Pallecon::findOrFail($this->sealingId);

        try {
            $sealed = app(SealPalleconFeature::class)($pallecon, $validated, auth()->user());
        } catch (\Throwable $e) {
            $this->flash = $e->getMessage();
            $this->flashError = true;

            return;
        }

        $messages = ['Pallecon '.$sealed->serial_number.' completed at '.$validated['final_weight'].' kg.'];
        $this->flashError = false;
        $this->sealingId = null;
        $this->reset('sealForm');

        if ($this->bartenderEnabled) {
            $printResult = $this->printLabelFor($sealed);
            $messages[] = $printResult;
        }

        $this->flash = implode(' ', $messages);
        unset($this->openContainers, $this->completedContainers);
    }

    public function printLabel(int $palleconId): void
    {
        $pallecon = Pallecon::findOrFail($palleconId);

        if (! in_array($pallecon->status, [Pallecon::STATUS_SEALED, Pallecon::STATUS_CONSUMED], true)) {
            $this->flash = 'Only completed pallecons can be labelled.';
            $this->flashError = true;

            return;
        }

        $this->flashError = false;
        $this->flash = $this->printLabelFor($pallecon);
    }

    /** Prints the container label from the recorded final weight; returns a status message. */
    private function printLabelFor(Pallecon $pallecon): string
    {
        if ($pallecon->isOnHold()) {
            $this->flashError = true;

            return 'Pallecon '.$pallecon->serial_number.' is on QA hold and cannot be labelled.';
        }

        $pallecon->loadMissing('fills.batchRecord.manufacturingOrder', 'fills.batchRecord.product');
        $primaryBatch = $pallecon->fills->sortBy('sequence')->first()?->batchRecord;

        if ($primaryBatch === null) {
            $this->flashError = true;

            return 'Pallecon has no contributing batch to label.';
        }

        // Label context = first contributing batch; weight = recorded final weight.
        $labelPallecon = new PalleconRecord([
            'mo_number' => $primaryBatch->manufacturingOrder?->mo_number,
            'ticket_number' => $pallecon->serial_number,
            'serial_number' => $pallecon->serial_number,
            'fill_weight' => (float) $pallecon->final_weight,
        ]);
        $labelPallecon->setRelation('batchRecord', $primaryBatch);

        try {
            app(PrintPalleconLabelFeature::class)($labelPallecon, 1, [
                'production_date' => $this->production_date !== '' ? $this->production_date : now()->toDateString(),
            ]);

            return 'Label sent to BarTender for pallecon '.$pallecon->serial_number.'.';
        } catch (\Throwable $e) {
            $this->flashError = true;

            return 'Label print failed: '.$e->getMessage();
        }
    }

    private function resolveWinManLotNumber(BatchRecord $batch, PalleconRecord $pallecon): string
    {
        $moId = trim((string) ($batch->manufacturingOrder?->winman_manufacturing_order_id
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
        $productionDate = $this->production_date !== ''
            ? Carbon::parse($this->production_date)
            : now();

        $yjjj = $productionDate->format('y');
        $yjjj = substr($yjjj, -1).str_pad((string) $productionDate->dayOfYear, 3, '0', STR_PAD_LEFT);

        return $yjjj.'00M96';
    }

    private function resolveBookingExpiryDate(BatchRecord $batch, PalleconRecord $pallecon, Carbon $fallbackBase): Carbon
    {
        $productionDate = $this->production_date !== ''
            ? Carbon::parse($this->production_date)
            : now();

        $previewPallecon = new PalleconRecord([
            'mo_number' => $batch->manufacturingOrder?->mo_number,
            'fill_weight' => (float) ($pallecon->fill_weight ?? 0),
        ]);
        $previewPallecon->setRelation('batchRecord', $batch);

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

            // SQL says shelf life in days: use exact day result.
            if ($bbeValue !== null && $bbeFormat === 'DDMMYYYY') {
                return $productionDate->copy()->addDays($bbeValue)->endOfDay();
            }

            // SQL says shelf life in months: use last day of target month.
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
            // Fall through to configured shelf-life fallback.
        }

        $shelfDays = (int) ($batch->product?->shelf_life_days ?? 180);

        return $fallbackBase->copy()->addDays($shelfDays)->endOfMonth();
    }
}; ?>

<div class="py-8">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        {{-- Workspace switcher --}}
        <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
            <div style="padding:10px 14px;background:linear-gradient(180deg,#f8fafc 0%,#f1f5f9 100%);">
                <nav style="display:flex;gap:8px;align-items:stretch;overflow:auto hidden;min-height:52px;">
                    <a href="{{ route('manufacturing-orders.workspace', ['winmanMo' => $winmanMo]) }}" wire:navigate style="display:inline-flex;align-items:center;gap:8px;padding:0 18px;border-radius:8px;border:2px solid #cbd5e1;background:#fff;color:#334155;font-size:14px;font-weight:800;text-decoration:none;white-space:nowrap;">
                        Batch Workspace
                    </a>
                    <span style="display:inline-flex;align-items:center;gap:8px;padding:0 18px;border-radius:8px;border:2px solid #4f46e5;background:#4f46e5;color:#fff;font-size:14px;font-weight:800;box-shadow:0 4px 12px rgba(79,70,229,.24);white-space:nowrap;">
                        Pallecon Workspace
                    </span>
                </nav>
            </div>
            <div class="px-5 py-3 text-sm text-slate-600 flex flex-wrap gap-x-6 gap-y-1">
                <span><span class="text-slate-400">MO:</span> <strong>{{ $localOrder?->mo_number ?? $winmanMo }}</strong></span>
                <span><span class="text-slate-400">Product:</span> <strong>{{ $localOrder?->winman_product_id ?? '—' }}</strong></span>
                <span><span class="text-slate-400">Batches:</span> <strong>{{ count($this->moBatches) }}</strong></span>
                <span><span class="text-slate-400">Capacity limit:</span> <strong>{{ number_format($this->limitKg, 0) }} kg</strong></span>
            </div>
        </div>

        @if ($flash)
            <div class="rounded-md p-3 text-sm {{ $flashError ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-green-50 text-green-700 border border-green-200' }}">
                {{ $flash }}
            </div>
        @endif

        @if ($winman_booking_preview)
            <div class="border border-emerald-200 rounded-lg p-4 bg-emerald-50 shadow-sm">
                <h3 class="text-sm font-semibold text-emerald-800 mb-3">WinMan Inventory Insert Preview (Last Fill)</h3>
                <div class="w-full rounded-lg border border-emerald-200 bg-white p-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3 text-sm">
                        <div><span class="text-slate-500">Status:</span> <span class="font-semibold text-slate-900">{{ $winman_booking_preview['booking_status'] ?? '—' }}</span></div>
                        <div><span class="text-slate-500">Inventory ID:</span> <span class="font-semibold text-slate-900">{{ $winman_booking_preview['winman_inventory_id'] ?? '—' }}</span></div>
                        <div><span class="text-slate-500">Booked At:</span> <span class="font-medium text-slate-900">{{ $winman_booking_preview['booked_at'] ?? '—' }}</span></div>
                        <div><span class="text-slate-500">MO ID:</span> <span class="font-medium text-slate-900">{{ $winman_booking_preview['manufacturing_order_id'] ?? '—' }}</span></div>
                        <div><span class="text-slate-500">Quantity (kg):</span> <span class="font-medium text-slate-900">{{ $winman_booking_preview['quantity_kg'] ?? '—' }}</span></div>
                        <div><span class="text-slate-500">Pallecon Number:</span> <span class="font-medium text-slate-900">{{ $winman_booking_preview['pallecon_number'] ?? '—' }}</span></div>
                        <div class="md:col-span-2"><span class="text-slate-500">Lot Number Sent:</span> <span class="font-medium text-slate-900">{{ $winman_booking_preview['lot_number'] ?? '—' }}</span></div>
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

        @php
            $fmtKg = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.') ?: '0';
            $selectedBatch = collect($this->moBatches)->firstWhere('id', (int) $fillBatchId);
        @endphp

        {{-- Batches available from this MO --}}
        <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5">
            <h2 class="text-sm font-semibold text-slate-800 mb-3">Batches &mdash; {{ $localOrder?->mo_number ?? $winmanMo }}</h2>

            @if (count($this->moBatches) === 0)
                <p class="text-sm text-slate-400">No batches created for this manufacturing order yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs uppercase text-slate-400">
                            <tr>
                                <th class="py-2 pr-3 w-8"></th>
                                <th class="py-2 pr-3">Batch</th>
                                <th class="py-2 pr-3">Status</th>
                                <th class="py-2 pr-3 text-right">Planned</th>
                                <th class="py-2 pr-3 text-right">Filled</th>
                                <th class="py-2 pr-3 text-right">Remaining</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($this->moBatches as $batchOption)
                                <tr class="{{ $batchOption['signoff_complete'] ? 'cursor-pointer hover:bg-indigo-50/40' : 'opacity-50' }}"
                                    @if ($batchOption['signoff_complete']) wire:click="$set('fillBatchId', '{{ $batchOption['id'] }}')" @endif>
                                    <td class="py-2 pr-3">
                                        <input type="radio" wire:model.live="fillBatchId" value="{{ $batchOption['id'] }}" @disabled(! $batchOption['signoff_complete'])
                                            class="text-indigo-600 focus:ring-indigo-500" />
                                    </td>
                                    <td class="py-2 pr-3 font-medium text-slate-800">{{ $batchOption['batch_number'] }}</td>
                                    <td class="py-2 pr-3">
                                        <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">{{ \Illuminate\Support\Str::headline($batchOption['status']) }}</span>
                                        @unless ($batchOption['signoff_complete'])
                                            <span class="ml-1 text-xs text-amber-700">sign-off pending</span>
                                        @endunless
                                    </td>
                                    <td class="py-2 pr-3 text-right">{{ $fmtKg($batchOption['planned_kg']) }}</td>
                                    <td class="py-2 pr-3 text-right">{{ $fmtKg($batchOption['filled_kg']) }}</td>
                                    <td class="py-2 pr-3 text-right font-semibold {{ $batchOption['remaining_kg'] <= 0.0001 ? 'text-emerald-600' : 'text-slate-800' }}">
                                        {{ $fmtKg($batchOption['remaining_kg']) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- New pallecon --}}
        <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-800">Pallecons</h2>
                <button type="button" wire:click="$toggle('showNewForm')" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-slate-900 text-white text-sm font-semibold hover:bg-slate-800">
                    <span class="text-base leading-none">+</span> New pallecon
                </button>
            </div>

            @if ($showNewForm)
                <div class="mt-4 grid grid-cols-1 md:grid-cols-5 gap-3 border-t border-slate-100 pt-4">
                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-slate-500 mb-1">Pallecon number *</label>
                        <input type="text" wire:model="newForm.ticket_number" class="w-full rounded-lg border-slate-300 text-sm" placeholder="e.g. PAL-00123" />
                        @error('newForm.ticket_number') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">Top seal (optional)</label>
                        <input type="text" wire:model="newForm.top_seal_number" class="w-full rounded-lg border-slate-300 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">Bottom seal (optional)</label>
                        <input type="text" wire:model="newForm.bottom_seal_number" class="w-full rounded-lg border-slate-300 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">Liner number (optional)</label>
                        <input type="text" wire:model="newForm.liner_number" class="w-full rounded-lg border-slate-300 text-sm" />
                    </div>
                    <div class="md:col-span-5">
                        <button type="button" wire:click="openPallecon" class="inline-flex items-center px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-500">Open pallecon</button>
                    </div>
                </div>
            @endif
        </div>

        {{-- Fill pallecon --}}
        <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5">
            <h2 class="text-sm font-semibold text-slate-800 mb-1">Fill pallecon</h2>
            @if ($selectedBatch)
                <p class="text-sm text-slate-600 mb-3">
                    From batch <strong>{{ $selectedBatch['batch_number'] }}</strong> &mdash;
                    <span class="font-semibold {{ $selectedBatch['remaining_kg'] <= 0.0001 ? 'text-emerald-600' : 'text-slate-900' }}">{{ $fmtKg($selectedBatch['remaining_kg']) }} kg</span> remaining
                    (the fill weight cannot exceed this).
                </p>
            @else
                <p class="text-sm text-amber-700 mb-3">Select a batch above to fill from.</p>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Fill weight (kg) *</label>
                    <input type="number" step="0.001" min="0.001"
                        @if ($selectedBatch) max="{{ $fmtKg($selectedBatch['remaining_kg']) }}" @endif
                        wire:model="fillWeight" class="w-full rounded-lg border-slate-300 text-sm" placeholder="e.g. 400" />
                    @error('fillWeight') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Pallecon *</label>
                    <select wire:model="fillPalleconId" class="w-full rounded-lg border-slate-300 text-sm">
                        <option value="">— select pallecon —</option>
                        @foreach ($this->openContainers as $container)
                            <option value="{{ $container->id }}">{{ $container->serial_number ?? 'Pallecon #'.$container->id }} — {{ number_format($container->filledWeight(), 1) }} kg so far</option>
                        @endforeach
                    </select>
                    @error('fillPalleconId') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Production date (booking expiry)</label>
                    <input type="date" wire:model="production_date" class="w-full rounded-lg border-slate-300 text-sm" />
                </div>
            </div>
            <div class="mt-4">
                <button type="button" wire:click="addFill" wire:loading.attr="disabled" @disabled(! $selectedBatch)
                    class="inline-flex items-center px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-500 disabled:opacity-40 disabled:cursor-not-allowed">Add to Pallecon</button>
                @error('fillBatchId') <span class="ml-2 text-xs text-red-600">{{ $message }}</span> @enderror
            </div>
        </div>

        {{-- Open containers board --}}
        <div>
            <h2 class="text-sm font-semibold text-slate-800 mb-3">Filling ({{ $this->openContainers->count() }})</h2>
            <div class="space-y-3">
                @forelse ($this->openContainers as $container)
                    @php
                        $filled = $container->filledWeight();
                        $pct = $this->limitKg > 0 ? min(100, round($filled / $this->limitKg * 100)) : 0;
                    @endphp
                    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <span class="font-semibold text-slate-900">{{ $container->serial_number ?? 'Pallecon #'.$container->id }}</span>
                                <span class="ml-2 text-xs px-2 py-0.5 rounded-full bg-amber-100 text-amber-800">{{ ucfirst($container->status) }}</span>
                            </div>
                            <div class="text-sm text-slate-500">{{ number_format($filled, 1) }} / {{ number_format($this->limitKg, 0) }} kg</div>
                        </div>
                        <div class="mt-2 h-2 w-full rounded-full bg-slate-100 overflow-hidden">
                            <div class="h-full bg-amber-400" style="width: {{ $pct }}%"></div>
                        </div>
                        <div class="mt-3 text-sm text-slate-600">
                            @forelse ($container->fills->sortBy('sequence') as $fill)
                                <div class="flex justify-between border-b border-slate-100 py-1">
                                    <span>#{{ $fill->sequence }} · {{ $fill->batchRecord?->batch_number ?? 'Batch '.$fill->batch_record_id }}</span>
                                    <span>{{ $fill->fill_weight !== null ? number_format((float) $fill->fill_weight, 1).' kg' : '—' }}</span>
                                </div>
                            @empty
                                <p class="text-slate-400 italic">No fills yet.</p>
                            @endforelse
                        </div>
                        @if ($sealingId === $container->id)
                            <div class="mt-4 border-t border-slate-100 pt-4 grid grid-cols-1 md:grid-cols-4 gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 mb-1">Final weight (kg) *</label>
                                    <input type="number" step="0.001" min="0.001" wire:model="sealForm.final_weight" class="w-full rounded-lg border-slate-300 text-sm" />
                                    @error('sealForm.final_weight') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 mb-1">Top seal (optional)</label>
                                    <input type="text" wire:model="sealForm.top_seal_number" class="w-full rounded-lg border-slate-300 text-sm" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 mb-1">Bottom seal (optional)</label>
                                    <input type="text" wire:model="sealForm.bottom_seal_number" class="w-full rounded-lg border-slate-300 text-sm" />
                                </div>
                                <div class="flex items-end gap-2">
                                    <button type="button" wire:click="completePallecon" class="inline-flex items-center px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-500">Complete{{ $this->bartenderEnabled ? ' & print label' : '' }}</button>
                                    <button type="button" wire:click="cancelSeal" class="inline-flex items-center px-3 py-2 rounded-lg bg-slate-100 text-slate-600 text-sm">Cancel</button>
                                </div>
                            </div>
                        @else
                            <div class="mt-4">
                                <button type="button" wire:click="startSeal({{ $container->id }})" @disabled($container->fills->isEmpty()) class="inline-flex items-center px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-500 disabled:opacity-40 disabled:cursor-not-allowed">Complete pallecon</button>
                                @if (! $this->bartenderEnabled)
                                    <span class="ml-3 text-xs text-amber-700">Label printing disabled (BARTENDER_ENABLED=false)</span>
                                @endif
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="bg-white border border-dashed border-slate-200 rounded-2xl p-6 text-center text-slate-400 text-sm">No pallecons currently filling. Use “New pallecon +” to open one.</div>
                @endforelse
            </div>
        </div>

        {{-- Completed containers (this MO only) --}}
        <div>
            <h2 class="text-sm font-semibold text-slate-800 mb-3">Completed &mdash; {{ $localOrder?->mo_number ?? $winmanMo }}</h2>
            <div class="space-y-3">
                @forelse ($this->completedContainers as $container)
                    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-4 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <span class="font-semibold text-slate-900">{{ $container->serial_number ?? 'Pallecon #'.$container->id }}</span>
                            @if ($container->isOnHold())
                                <span class="ml-2 text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-800">On hold</span>
                            @else
                                <span class="ml-2 text-xs px-2 py-0.5 rounded-full {{ $container->status === 'consumed' ? 'bg-slate-100 text-slate-600' : 'bg-emerald-100 text-emerald-800' }}">{{ ucfirst($container->status) }}</span>
                            @endif
                            <span class="ml-2 text-sm text-slate-500">{{ number_format((float) $container->final_weight, 1) }} kg · {{ $container->fills->count() }} batch(es) · {{ $container->fills->map(fn ($fill) => $fill->batchRecord?->batch_number)->filter()->implode(', ') }}</span>
                            @if ($container->isOnHold())
                                <div class="text-xs text-red-600 mt-1">{{ $container->hold_reason }}</div>
                            @endif
                        </div>
                        <div>
                            @if ($container->isOnHold())
                                <span class="text-xs text-red-700">Quarantined — labelling blocked</span>
                            @elseif ($this->bartenderEnabled)
                                <button type="button" wire:click="printLabel({{ $container->id }})" class="inline-flex items-center px-3 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-500">Print label</button>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="bg-white border border-dashed border-slate-200 rounded-2xl p-6 text-center text-slate-400 text-sm">No completed pallecons yet.</div>
                @endforelse
            </div>
        </div>

    </div>
</div>
