<?php

use App\Domains\Pallecon\Support\PalleconCapacity;
use App\Domains\Pallecon\Support\PalleconFilling;
use App\Domains\Printing\Support\BarTenderPrintPortalClient;
use App\Features\Pallecon\AttachBatchFillFeature;
use App\Features\Pallecon\PrintPalleconLabelFeature;
use App\Features\Pallecon\SealPalleconFeature;
use App\Models\BatchRecord;
use App\Models\LabelPrintLog;
use App\Models\ManufacturingOrder;
use App\Models\Pallecon;
use App\Models\PalleconFill;
use App\Models\PalleconRecord;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Pallecon Workspace')] class extends Component {
    public int $winmanMo;

    public ?ManufacturingOrder $localOrder = null;

    /** When set (?pallecon=), the page is scoped to this one pallecon. */
    public ?int $palleconId = null;

    /** Pallecon number, edited inside the open pallecon - stays editable early and auto-saves. */
    public array $containerForm = [
        'serial_number' => '',
    ];

    public string $production_date = '';

    public ?int $sealingId = null;

    /** @var array<string, string> */
    public array $sealForm = [
        'final_weight' => '',
        'top_seal_number' => '',
        'bottom_seal_number' => '',
        'liner_number' => '',
    ];

    public ?string $flash = null;
    public bool $flashError = false;

    /** Per-fill WinMan booking results, populated on Complete / Retry. @var array<int, array<string, mixed>> */
    public array $winman_booking_results = [];

    public function mount(int $winmanMo): void
    {
        $this->winmanMo = $winmanMo;
        $this->localOrder = ManufacturingOrder::query()
            ->where('winman_manufacturing_order', $winmanMo)
            ->first();
        $this->palleconId = request()->integer('pallecon') ?: null;
        $this->production_date = now()->toDateString();
        $this->syncContainerForm();

        // Carried over from completePallecon()'s redirect, so the confirmation
        // cards are still visible on the page the user lands on.
        if (session()->has('status')) {
            $this->flash = (string) session('status');
            $this->flashError = false;
        }
        if (session()->has('winman_booking_results')) {
            $this->winman_booking_results = (array) session('winman_booking_results');
        }
    }

    /** Mirror the pallecon's number into the editable form. */
    private function syncContainerForm(): void
    {
        $container = $this->activeContainer;

        $this->containerForm = [
            'serial_number' => (string) ($container?->serial_number ?? ''),
        ];
    }

    /** Auto-saves the pallecon number as soon as it changes - no explicit save step. */
    public function updatedContainerFormSerialNumber(): void
    {
        $this->updateSerialNumber();
    }

    #[Computed]
    public function capacityKg(): float
    {
        return PalleconCapacity::capacityKg();
    }

    #[Computed]
    public function bartenderEnabled(): bool
    {
        return (bool) config('services.bartender.enabled', false);
    }

    #[Computed]
    public function winmanBookingEnabled(): bool
    {
        return (bool) config('winman.booking.enabled', false);
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
            ->with(['fills.batchRecord:id,batch_number,manufacturing_order_id', 'winmanBookingLogs'])
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
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function moBatches(): array
    {
        return $this->localOrder === null
            ? []
            : app(PalleconFilling::class)->fillableBatches($this->localOrder);
    }

    /**
     * The pallecon this page works on. When ?pallecon= is given it is that one
     * (must belong to this MO); otherwise the single open/filling pallecon for
     * this MO, if any.
     */
    #[Computed]
    public function activeContainer(): ?Pallecon
    {
        $moId = (int) ($this->localOrder?->id ?? 0);

        if ($moId <= 0) {
            return null;
        }

        $belongsToMo = fn (Pallecon $pallecon): bool => (int) ($pallecon->manufacturing_order_id ?? 0) === $moId
            || $pallecon->fills->contains(
                fn ($fill): bool => (int) ($fill->batchRecord?->manufacturing_order_id ?? 0) === $moId
            );

        if ($this->palleconId !== null) {
            $pallecon = Pallecon::query()
                ->with(['fills.batchRecord:id,batch_number,manufacturing_order_id', 'winmanBookingLogs', 'labelPrintLogs.printedBy', 'sealedBy'])
                ->find($this->palleconId);

            return ($pallecon !== null && $belongsToMo($pallecon)) ? $pallecon : null;
        }

        return Pallecon::query()
            ->whereIn('status', [Pallecon::STATUS_OPEN, Pallecon::STATUS_FILLING])
            ->where(fn ($query) => $query
                ->where('manufacturing_order_id', $moId)
                ->orWhereHas('fills.batchRecord', fn ($sub) => $sub->where('manufacturing_order_id', $moId)))
            ->with(['fills.batchRecord:id,batch_number,manufacturing_order_id'])
            ->orderByDesc('opened_at')
            ->first();
    }

    /**
     * Issues a batch's entire remaining planned quantity into the active pallecon,
     * auto-capped to whatever room is left in the container. Local bookkeeping
     * only - WinMan is never touched here, only at completePallecon().
     */
    public function issueBatchToPallecon(int $batchId): void
    {
        $this->flash = null;

        $container = $this->activeContainer;
        if ($container === null || ! $container->isOpenForFilling()) {
            $this->flash = 'No pallecon selected. Create one on the MO Workspace first.';
            $this->flashError = true;

            return;
        }

        $batchMeta = collect($this->moBatches)->firstWhere('id', $batchId);

        if (($batchMeta['status'] ?? null) === BatchRecord::STATUS_COMPLETED) {
            $this->flash = 'Batch '.($batchMeta['batch_number'] ?? $batchId).' is completed and can no longer be issued to a pallecon.';
            $this->flashError = true;

            return;
        }

        $error = app(PalleconFilling::class)->signOffError($batchMeta);
        if ($error !== null) {
            $this->flash = $error;
            $this->flashError = true;

            return;
        }

        $batchRemaining = (float) ($batchMeta['remaining_kg'] ?? 0);
        if ($batchRemaining <= 0.0001) {
            $this->flash = 'Batch '.$batchMeta['batch_number'].' has nothing left to issue.';
            $this->flashError = true;

            return;
        }

        $palleconRemaining = PalleconCapacity::remainingKg($container);
        $issueWeight = $palleconRemaining === null ? $batchRemaining : min($batchRemaining, $palleconRemaining);

        if ($issueWeight <= 0.0001) {
            $this->flash = 'Pallecon '.($container->serial_number ?? '#'.$container->id).' is already full.';
            $this->flashError = true;

            return;
        }

        $batch = BatchRecord::with('manufacturingOrder', 'product')->findOrFail($batchId);

        try {
            app(AttachBatchFillFeature::class)($container, $batch, [
                'fill_weight' => $issueWeight,
            ], auth()->user());
        } catch (\Throwable $e) {
            $this->flash = $e->getMessage();
            $this->flashError = true;

            return;
        }

        $this->flashError = false;

        if ($issueWeight < $batchRemaining - 0.0001) {
            $outstanding = $batchRemaining - $issueWeight;
            $this->flash = sprintf(
                'Issued %s kg from batch %s - pallecon %s is now full. %s kg still outstanding on this batch.',
                $this->fmtKg($issueWeight),
                $batch->batch_number,
                $container->serial_number ?? '#'.$container->id,
                $this->fmtKg($outstanding),
            );
        } else {
            $this->flash = sprintf(
                'Issued %s kg from batch %s to pallecon %s.',
                $this->fmtKg($issueWeight),
                $batch->batch_number,
                $container->serial_number ?? '#'.$container->id,
            );
        }

        unset($this->activeContainer, $this->moBatches);
    }

    private function fmtKg(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }

    public function updateSerialNumber(): void
    {
        $container = $this->activeContainer;
        if ($container === null) {
            return;
        }

        $data = $this->validate([
            'containerForm.serial_number' => ['nullable', 'string', 'max:255'],
        ])['containerForm'];

        $serial = trim((string) ($data['serial_number'] ?? ''));
        if ($serial !== '' && $serial !== (string) $container->serial_number
            && Pallecon::query()->where('serial_number', $serial)->whereIn('status', Pallecon::ACTIVE_STATUSES)->where('id', '!=', $container->id)->exists()) {
            $this->flash = 'Pallecon number "'.$serial.'" is already in use by another active container.';
            $this->flashError = true;

            return;
        }

        $container->update([
            'serial_number' => $serial !== '' ? $serial : null,
        ]);

        $this->flash = 'Pallecon number saved.';
        $this->flashError = false;
        unset($this->activeContainer);
        $this->syncContainerForm();
    }

    /**
     * Books a sealed pallecon's final weight to WinMan as a single transaction,
     * returning a labelled result row (used to render one confirmation card).
     *
     * @return array<string, mixed>
     */
    private function bookContainerToWinMan(Pallecon $container, string $productionDate, ?User $user): array
    {
        $result = app(PalleconFilling::class)->bookContainer($container, $productionDate, $user);

        return [
            'pallecon_id' => $container->id,
            'serial_number' => (string) ($container->serial_number ?? '#'.$container->id),
            'fill_weight' => (float) $container->final_weight,
            'preview' => $result['preview'],
            'messages' => $result['messages'],
            'error' => $result['error'],
        ];
    }

    public function startSeal(int $palleconId): void
    {
        $pallecon = Pallecon::findOrFail($palleconId);
        $this->sealingId = $palleconId;
        $this->sealForm = [
            'final_weight' => (string) ($pallecon->filledWeight() ?: ''),
            'top_seal_number' => (string) ($pallecon->top_seal_number ?? ''),
            'bottom_seal_number' => (string) ($pallecon->bottom_seal_number ?? ''),
            'liner_number' => (string) ($pallecon->liner_number ?? ''),
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
            'sealForm.liner_number' => ['nullable', 'string', 'max:255'],
        ])['sealForm'];

        $pallecon = Pallecon::findOrFail($this->sealingId);

        try {
            $sealed = app(SealPalleconFeature::class)($pallecon, $validated, auth()->user());
        } catch (\Throwable $e) {
            $this->flash = $e->getMessage();
            $this->flashError = true;

            return;
        }

        // On seal, the pallecon Reference is written back in the WinMan lot format
        // "{MO WinMan id} {pallecon number} {yjjj}00M96".
        $reference = app(PalleconFilling::class)->sealReference(
            $sealed,
            $this->localOrder,
            (string) ($sealed->production_date?->toDateString() ?? $this->production_date),
        );
        $sealed->forceFill(['winman_reference' => $reference])->save();

        $messages = ['Pallecon '.($sealed->serial_number ?? '#'.$sealed->id).' completed at '.$validated['final_weight'].' kg. Reference '.$reference.'.'];
        $this->flashError = false;
        $this->sealingId = null;
        $this->reset('sealForm');

        // WinMan is only ever written to here, once, when the container is
        // physically sealed - never on an individual "Issue to Pallecon". One
        // pallecon is one physical unit, so it books as a single transaction
        // even when several batches contributed fills to it.
        $productionDate = (string) ($sealed->production_date?->toDateString() ?? $this->production_date);
        $bookingResult = $this->bookContainerToWinMan($sealed, $productionDate, auth()->user());
        $this->winman_booking_results = $bookingResult['preview'] !== null ? [$bookingResult] : [];

        if ($this->winman_booking_results !== []) {
            $messages[] = $bookingResult['error']
                ? 'WinMan booking failed - see below.'
                : 'WinMan booking: pallecon booked.';
        }

        if ($this->bartenderEnabled) {
            $printResult = $this->printLabelFor($sealed);
            $messages[] = $printResult;
        }

        // The pallecon is now sealed - nothing left to fill, seal or rename on it.
        // Show the confirmation once more (carried via session across the
        // redirect) and drop back to the unscoped Pallecon Workspace instead of
        // leaving the user parked on a now-read-only container.
        session()->flash('status', implode(' ', $messages));
        session()->flash('winman_booking_results', $this->winman_booking_results);

        $this->redirectRoute('manufacturing-orders.pallecons', ['winmanMo' => $this->winmanMo], navigate: true);
    }

    /** Re-attempts the WinMan booking for a completed pallecon that never booked successfully. */
    public function retryWinManBooking(int $palleconId): void
    {
        $pallecon = Pallecon::with(['fills.batchRecord.manufacturingOrder', 'fills.batchRecord.product', 'winmanBookingLogs'])
            ->findOrFail($palleconId);

        if (! in_array($pallecon->status, [Pallecon::STATUS_SEALED, Pallecon::STATUS_CONSUMED], true)) {
            $this->flash = 'Only completed pallecons can be retried.';
            $this->flashError = true;

            return;
        }

        if ($pallecon->isWinManBooked()) {
            $this->flash = 'Nothing to retry - this pallecon is already booked.';
            $this->flashError = false;

            return;
        }

        $productionDate = (string) ($pallecon->production_date?->toDateString() ?? $this->production_date);
        $result = $this->bookContainerToWinMan($pallecon, $productionDate, auth()->user());
        $this->winman_booking_results = $result['preview'] !== null ? [$result] : [];

        $this->flash = $result['error']
            ? 'Retry failed - see below.'
            : 'Retried: pallecon booked.';
        $this->flashError = $result['error'];

        unset($this->completedContainers);
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

        $productionDate = $this->production_date !== '' ? $this->production_date : now()->toDateString();
        $logAttributes = [
            'pallecon_id' => $pallecon->id,
            'batch_record_id' => $primaryBatch->id,
            'printed_by' => auth()->id(),
            'label_type' => 'pallecon',
            'serial_number' => $pallecon->serial_number,
            'fill_weight' => (float) $pallecon->final_weight,
            'production_date' => $productionDate,
            'printed_at' => now(),
        ];

        try {
            $payload = app(PrintPalleconLabelFeature::class)->buildPrintPayload($labelPallecon, 1, [
                'production_date' => $productionDate,
            ]);

            app(BarTenderPrintPortalClient::class)->printFromLibrary(
                (string) $payload['label'],
                is_array($payload['options'] ?? null) ? $payload['options'] : [],
            );

            LabelPrintLog::create($logAttributes + [
                'status' => LabelPrintLog::STATUS_SUCCESS,
                'label_data' => $payload['options']['named_data_sources'] ?? null,
            ]);

            return 'Label sent to BarTender for pallecon '.$pallecon->serial_number.'.';
        } catch (\Throwable $e) {
            $this->flashError = true;

            LabelPrintLog::create($logAttributes + [
                'status' => LabelPrintLog::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            return 'Label print failed: '.$e->getMessage();
        }
    }

}; ?>

<div class="py-8">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        {{-- Header --}}
        <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
            <div style="padding:10px 14px;background:linear-gradient(180deg,#f8fafc 0%,#f1f5f9 100%);display:flex;justify-content:flex-end;">
                <a href="{{ route('dashboard') }}" wire:navigate style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;font-size:12px;font-weight:800;text-decoration:none;">
                    Back to Main Menu
                </a>
            </div>
            <div class="px-5 py-3 text-sm text-slate-600 flex flex-wrap gap-x-6 gap-y-1">
                <span><span class="text-slate-400">MO:</span> <strong>{{ $localOrder?->mo_number ?? $winmanMo }}</strong></span>
                <span><span class="text-slate-400">Product:</span> <strong>{{ $localOrder?->winman_product_id ?? '—' }}</strong></span>
                <span><span class="text-slate-400">Batches:</span> <strong>{{ count($this->moBatches) }}</strong></span>
                <span><span class="text-slate-400">Physical capacity:</span> <strong>{{ number_format($this->capacityKg, 0) }} kg</strong></span>
            </div>
        </div>

        @if ($flash)
            <div class="rounded-md p-3 text-sm {{ $flashError ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-green-50 text-green-700 border border-green-200' }}">
                {{ $flash }}
            </div>
        @endif

        @if ($winman_booking_results !== [])
            <div class="space-y-3">
                @foreach ($winman_booking_results as $result)
                    @php $preview = $result['preview']; @endphp
                    <div class="border rounded-lg p-4 shadow-sm {{ $result['error'] ? 'border-red-200 bg-red-50' : 'border-emerald-200 bg-emerald-50' }}">
                        <h3 class="text-sm font-semibold mb-3 {{ $result['error'] ? 'text-red-800' : 'text-emerald-800' }}">
                            WinMan Booking &mdash; pallecon {{ $result['serial_number'] }} ({{ number_format($result['fill_weight'], 1) }} kg)
                        </h3>
                        <div class="w-full rounded-lg border {{ $result['error'] ? 'border-red-200' : 'border-emerald-200' }} bg-white p-4">
                            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3 text-sm">
                                <div><span class="text-slate-500">Status:</span> <span class="font-semibold text-slate-900">{{ $preview['booking_status'] ?? '—' }}</span></div>
                                <div><span class="text-slate-500">Inventory ID:</span> <span class="font-semibold text-slate-900">{{ $preview['winman_inventory_id'] ?? '—' }}</span></div>
                                <div><span class="text-slate-500">Booked At:</span> <span class="font-medium text-slate-900">{{ $preview['booked_at'] ?? '—' }}</span></div>
                                <div><span class="text-slate-500">MO ID:</span> <span class="font-medium text-slate-900">{{ $preview['manufacturing_order_id'] ?? '—' }}</span></div>
                                <div><span class="text-slate-500">Quantity (kg):</span> <span class="font-medium text-slate-900">{{ $preview['quantity_kg'] ?? '—' }}</span></div>
                                <div><span class="text-slate-500">Pallecon Number:</span> <span class="font-medium text-slate-900">{{ $preview['pallecon_number'] ?? '—' }}</span></div>
                                <div class="md:col-span-2"><span class="text-slate-500">Lot Number Sent:</span> <span class="font-medium text-slate-900">{{ $preview['lot_number'] ?? '—' }}</span></div>
                                <div><span class="text-slate-500">Finished Date:</span> <span class="font-medium text-slate-900">{{ $preview['finished_date'] ?? '—' }}</span></div>
                                <div><span class="text-slate-500">Expiry Date:</span> <span class="font-medium text-slate-900">{{ $preview['expiry_date'] ?? '—' }}</span></div>
                                <div><span class="text-slate-500">Logged Qty (kg):</span> <span class="font-medium text-slate-900">{{ $preview['booked_quantity_kg_logged'] ?? '—' }}</span></div>
                                <div><span class="text-slate-500">Logged Qty (TU):</span> <span class="font-medium text-slate-900">{{ $preview['booked_quantity_traded_units'] ?? '—' }}</span></div>
                                @if (! empty($preview['error_message']))
                                    <div class="md:col-span-2 xl:col-span-4 text-red-700">
                                        <span class="text-slate-500">Error:</span> {{ $preview['error_message'] }}
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @php
            $fmtKg = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.') ?: '0';
            $active = $this->activeContainer;
            $activeRemaining = $active ? PalleconCapacity::remainingKg($active) : null;
            // Scoped to a single already-sealed pallecon (?pallecon=): nothing left
            // to fill, edit or seal on it - show a read-only summary instead of the
            // full MO-wide workspace.
            $isCompletedPalleconView = $this->palleconId !== null
                && $active !== null
                && in_array($active->status, [Pallecon::STATUS_SEALED, Pallecon::STATUS_CONSUMED], true);
        @endphp

        @if ($isCompletedPalleconView)
            {{-- Completed pallecon: read-only summary --}}
            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <div>
                        <span class="font-semibold text-slate-900 text-lg">{{ $active->serial_number ?? 'Pallecon #'.$active->id }}</span>
                        <span class="ml-2 text-xs px-2 py-0.5 rounded-full {{ $active->status === 'consumed' ? 'bg-slate-100 text-slate-600' : 'bg-emerald-100 text-emerald-800' }}">{{ ucfirst($active->status) }}</span>
                        @if ($active->isOnHold())
                            <span class="ml-2 text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-800">On hold</span>
                        @endif
                    </div>
                    <div class="text-sm text-slate-500">
                        {{ $active->final_weight !== null ? number_format((float) $active->final_weight, 1).' kg' : '—' }}
                        @if ($active->sealed_at)
                            · sealed {{ $active->sealed_at->format('d/m/Y H:i') }}
                        @endif
                        @if ($active->sealedBy)
                            by {{ $active->sealedBy->name }}
                        @endif
                    </div>
                </div>

                @if ($active->isOnHold())
                    <p class="text-xs text-red-600 mb-4">{{ $active->hold_reason }}</p>
                @endif

                <dl class="grid grid-cols-2 sm:grid-cols-4 gap-x-6 gap-y-2 text-sm mb-5">
                    <div><dt class="text-xs text-slate-400">MO</dt><dd class="font-medium text-slate-800">{{ $localOrder?->mo_number ?? $winmanMo }}</dd></div>
                    <div><dt class="text-xs text-slate-400">WinMan reference</dt><dd class="font-mono text-slate-800">{{ $active->winman_reference ?: '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-400">Top / bottom seal</dt><dd class="text-slate-800">{{ $active->top_seal_number ?: '—' }} / {{ $active->bottom_seal_number ?: '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-400">Liner number</dt><dd class="text-slate-800">{{ $active->liner_number ?: '—' }}</dd></div>
                </dl>

                <h3 class="text-xs font-semibold uppercase text-slate-400 mb-2">Batches used</h3>
                <div class="overflow-x-auto mb-5">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs uppercase text-slate-400">
                            <tr>
                                <th class="py-2 pr-3">Batch</th>
                                <th class="py-2 pr-3 text-right">Weight</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($active->fills->sortBy('sequence') as $fill)
                                <tr>
                                    <td class="py-2 pr-3 font-medium text-slate-800">{{ $fill->batchRecord?->batch_number ?? 'Batch '.$fill->batch_record_id }}</td>
                                    <td class="py-2 pr-3 text-right">{{ $fill->fill_weight !== null ? number_format((float) $fill->fill_weight, 1).' kg' : '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="py-2 text-slate-400 italic">No fills recorded.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @php $lastBooking = $active->winmanBookingLogs->sortByDesc('id')->first(); @endphp
                <h3 class="text-xs font-semibold uppercase text-slate-400 mb-2">WinMan booking</h3>
                <div class="mb-5">
                    @if ($lastBooking === null)
                        <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">Not booked</span>
                    @else
                        <div class="flex flex-wrap items-center gap-2 mb-1">
                            @if ($lastBooking->booking_status === 'success')
                                <span class="text-xs px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800">Booked &middot; Inventory {{ $lastBooking->winman_inventory_id ?? '—' }}</span>
                            @else
                                <span class="text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-800">{{ \Illuminate\Support\Str::headline($lastBooking->booking_status) }}</span>
                            @endif
                            <span class="text-xs text-slate-500">lot {{ $lastBooking->lot_number }} &middot; {{ number_format((float) $lastBooking->quantity_booked_kg, 1) }} kg &middot; {{ $lastBooking->booking_date?->format('d/m/Y H:i') }}</span>
                        </div>
                        @if ($lastBooking->error_message)
                            <p class="text-xs text-red-600">{{ $lastBooking->error_message }}</p>
                        @endif
                    @endif
                </div>

                <h3 class="text-xs font-semibold uppercase text-slate-400 mb-2">Label prints</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs uppercase text-slate-400">
                            <tr>
                                <th class="py-2 pr-3">Printed</th>
                                <th class="py-2 pr-3">Type</th>
                                <th class="py-2 pr-3">By</th>
                                <th class="py-2 pr-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($active->labelPrintLogs->sortByDesc('printed_at') as $log)
                                <tr>
                                    <td class="py-2 pr-3 text-slate-600">{{ $log->printed_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                    <td class="py-2 pr-3 text-slate-600">{{ \Illuminate\Support\Str::headline($log->label_type ?? '—') }}</td>
                                    <td class="py-2 pr-3 text-slate-600">{{ $log->printedBy?->name ?? '—' }}</td>
                                    <td class="py-2 pr-3">
                                        @if ($log->status === 'success')
                                            <span class="text-xs px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800">Printed</span>
                                        @else
                                            <span class="text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-800">Failed</span>
                                            @if ($log->error_message)
                                                <span class="block text-xs text-red-600 mt-0.5">{{ $log->error_message }}</span>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="py-2 text-slate-400 italic">No label prints recorded.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @else

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
                                <th class="py-2 pr-3">Batch</th>
                                <th class="py-2 pr-3">Status</th>
                                <th class="py-2 pr-3 text-right">Planned</th>
                                <th class="py-2 pr-3 text-right">Filled</th>
                                <th class="py-2 pr-3 text-right">Remaining</th>
                                <th class="py-2 pr-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($this->moBatches as $batchOption)
                                @php
                                    $canIssue = $batchOption['signoff_complete']
                                        && $batchOption['remaining_kg'] > 0.0001
                                        && $active !== null
                                        && $active->isOpenForFilling()
                                        && ($activeRemaining === null || $activeRemaining > 0.0001);
                                @endphp
                                <tr class="{{ $batchOption['signoff_complete'] ? '' : 'opacity-50' }}">
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
                                    <td class="py-2 pr-3 text-right">
                                        @unless ($batchOption['status'] === \App\Models\BatchRecord::STATUS_COMPLETED)
                                            <button type="button" wire:click="issueBatchToPallecon({{ $batchOption['id'] }})" wire:loading.attr="disabled" @disabled(! $canIssue)
                                                class="inline-flex items-center px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-xs font-semibold hover:bg-indigo-500 disabled:opacity-40 disabled:cursor-not-allowed">Issue to Pallecon</button>
                                        @endunless
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($active === null)
                    <p class="mt-2 text-xs text-amber-700">No pallecon is open. <a href="{{ route('manufacturing-orders.workspace', ['winmanMo' => $winmanMo]) }}" wire:navigate class="font-semibold underline">Open one on the MO Workspace</a> to issue batches.</p>
                @endif
            @endif
        </div>

        {{-- Pallecon Workspace: the one pallecon this page works on --}}
        <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5">
            <h2 class="text-sm font-semibold text-slate-800 mb-3">Pallecon Workspace</h2>

            @if ($active === null)
                <p class="text-sm text-slate-500">
                    No pallecon selected.
                    <a href="{{ route('manufacturing-orders.workspace', ['winmanMo' => $winmanMo]) }}" wire:navigate class="text-indigo-600 font-semibold">Go to the MO Workspace</a>
                    to create one or pick one from the list.
                </p>
            @else
                @php
                    $filled = $active->filledWeight();
                    $target = $active->target_weight_kg !== null ? (float) $active->target_weight_kg : null;
                    $pct = $target !== null && $target > 0 ? min(100, round($filled / $target * 100)) : null;
                    $isFull = $pct !== null && $pct >= 100;
                @endphp
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <span class="font-semibold text-slate-900">{{ $active->serial_number ?? 'Pallecon #'.$active->id }}</span>
                        <span class="ml-2 text-xs px-2 py-0.5 rounded-full bg-amber-100 text-amber-800">{{ ucfirst($active->status) }}</span>
                        @if ($isFull)
                            <span class="ml-2 text-xs px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 font-semibold">Full &mdash; ready to complete</span>
                        @endif
                        @if ($active->winman_reference)
                            <span class="ml-2 text-xs text-slate-500">Ref: <span class="font-mono text-slate-700">{{ $active->winman_reference }}</span></span>
                        @endif
                    </div>
                    <div class="text-sm text-slate-500">
                        @if ($target !== null)
                            {{ number_format($filled, 1) }} / {{ $fmtKg($target) }} kg ({{ $pct }}%)
                        @else
                            {{ number_format($filled, 1) }} kg (no target set)
                        @endif
                    </div>
                </div>
                <div class="mt-2 h-2 w-full rounded-full bg-slate-100 overflow-hidden">
                    <div class="h-full {{ $isFull ? 'bg-emerald-500' : 'bg-amber-400' }}" style="width: {{ $pct ?? 0 }}%"></div>
                </div>

                <div class="mt-3 text-sm text-slate-600">
                    @forelse ($active->fills->sortBy('sequence') as $fill)
                        <div class="flex justify-between border-b border-slate-100 py-1">
                            <span>#{{ $fill->sequence }} · {{ $fill->batchRecord?->batch_number ?? 'Batch '.$fill->batch_record_id }}</span>
                            <span>{{ $fill->fill_weight !== null ? number_format((float) $fill->fill_weight, 1).' kg' : '—' }}</span>
                        </div>
                    @empty
                        <p class="text-slate-400 italic">No fills yet.</p>
                    @endforelse
                </div>

                {{-- Pallecon number --}}
                <div class="mt-4 border-t border-slate-100 pt-4">
                    <h3 class="text-xs font-semibold uppercase text-slate-400 mb-2">Pallecon number</h3>
                    <div class="max-w-xs">
                        <input type="text" wire:model.live.blur="containerForm.serial_number" class="w-full rounded-lg border-slate-300 text-sm" placeholder="e.g. PAL-00123" />
                    </div>
                </div>

                {{-- Complete the pallecon --}}
                <div class="mt-4 border-t border-slate-100 pt-4">
                    @if ($sealingId === $active->id)
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
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
                            <div>
                                <label class="block text-xs font-medium text-slate-500 mb-1">Liner number (optional)</label>
                                <input type="text" wire:model="sealForm.liner_number" class="w-full rounded-lg border-slate-300 text-sm" />
                            </div>
                            <div class="flex items-end gap-2 md:col-span-4">
                                <button type="button" wire:click="completePallecon" class="inline-flex items-center px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-500">Submit &amp; complete{{ $this->bartenderEnabled ? ' + print label' : '' }}</button>
                                <button type="button" wire:click="cancelSeal" class="inline-flex items-center px-3 py-2 rounded-lg bg-slate-100 text-slate-600 text-sm">Cancel</button>
                            </div>
                        </div>
                    @else
                        <button type="button" wire:click="startSeal({{ $active->id }})" @disabled($active->fills->isEmpty())
                            class="inline-flex items-center px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-500 disabled:opacity-40 disabled:cursor-not-allowed">Complete pallecon</button>
                        @unless ($this->bartenderEnabled)
                            <span class="ml-3 text-xs text-amber-700">Label printing disabled (BARTENDER_ENABLED=false)</span>
                        @endunless
                    @endif
                </div>
            @endif
        </div>

        @endif

        @unless ($isCompletedPalleconView)
        {{-- Completed containers (this MO only) --}}
        <div>
            <h2 class="text-sm font-semibold text-slate-800 mb-3">Completed &mdash; {{ $localOrder?->mo_number ?? $winmanMo }}</h2>
            <div class="space-y-3">
                @forelse ($this->completedContainers as $container)
                    @php $hasUnbooked = ! $container->isWinManBooked(); @endphp
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
                            @if ($this->winmanBookingEnabled && $hasUnbooked)
                                <div class="text-xs text-amber-700 mt-1">Not yet booked to WinMan.</div>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($this->winmanBookingEnabled && $hasUnbooked)
                                <button type="button" wire:click="retryWinManBooking({{ $container->id }})" class="inline-flex items-center px-3 py-2 rounded-lg bg-amber-500 text-white text-sm font-semibold hover:bg-amber-400">Retry WinMan booking</button>
                            @endif
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
        @endunless

    </div>
</div>
