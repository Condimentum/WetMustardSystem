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

        // A completed batch (ingredients signed off) is exactly what gets filled into
        // pallecons, so only sign-off and remaining kg gate issuing - not batch status.
        $batchMeta = collect($this->moBatches)->firstWhere('id', $batchId);

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
    <x-mo-workspace-styles />
    <x-pallecon-workspace-styles />
    <div class="wm-page max-w-7xl mx-auto space-y-6">

        {{-- Header --}}
        <section class="wm-card wm-card--gear-tr">
            <div class="pw-hero">
                <span class="pw-hero-icon"><img src="{{ asset('pallecon-row-icon.png') }}" alt="" /></span>
                <div class="pw-hero-text">
                    <div class="wml-title">PALLECON WORKSPACE</div>
                    <div class="wml-sub">{{ $localOrder?->mo_number ?? $winmanMo }}</div>
                </div>
            </div>
            <div class="pw-stats">
                <div class="pw-stat"><div class="pw-stat-label">MO</div><div class="pw-stat-value" style="color:#1f5130;">{{ $localOrder?->mo_number ?? $winmanMo }}</div></div>
                <div class="pw-stat"><div class="pw-stat-label">Product</div><div class="pw-stat-value">{{ $localOrder?->winman_product_id ?? '—' }}</div></div>
                <div class="pw-stat"><div class="pw-stat-label">Batches</div><div class="pw-stat-value" style="color:#5b3b8f;">{{ count($this->moBatches) }}</div></div>
                <div class="pw-stat"><div class="pw-stat-label">Physical capacity</div><div class="pw-stat-value">{{ number_format($this->capacityKg, 0) }} kg</div></div>
            </div>
        </section>

        @if ($flash)
            <div class="pw-flash {{ $flashError ? 'pw-flash--err' : 'pw-flash--ok' }}">
                {{ $flash }}
            </div>
        @endif

        @if ($winman_booking_results !== [])
            <div class="space-y-3">
                @foreach ($winman_booking_results as $result)
                    @php $preview = $result['preview']; @endphp
                    <div class="pw-booking {{ $result['error'] ? 'pw-booking--err' : '' }}">
                        <h3 class="pw-booking-title">
                            WinMan Booking &mdash; pallecon {{ $result['serial_number'] }} ({{ number_format($result['fill_weight'], 1) }} kg)
                        </h3>
                        <div class="pw-kv">
                            <div><span>Status:</span> <strong>{{ $preview['booking_status'] ?? '—' }}</strong></div>
                            <div><span>Inventory ID:</span> <strong>{{ $preview['winman_inventory_id'] ?? '—' }}</strong></div>
                            <div><span>Booked At:</span> <strong>{{ $preview['booked_at'] ?? '—' }}</strong></div>
                            <div><span>MO ID:</span> <strong>{{ $preview['manufacturing_order_id'] ?? '—' }}</strong></div>
                            <div><span>Quantity (kg):</span> <strong>{{ $preview['quantity_kg'] ?? '—' }}</strong></div>
                            <div><span>Pallecon Number:</span> <strong>{{ $preview['pallecon_number'] ?? '—' }}</strong></div>
                            <div class="pw-kv-wide"><span>Lot Number Sent:</span> <strong>{{ $preview['lot_number'] ?? '—' }}</strong></div>
                            <div><span>Finished Date:</span> <strong>{{ $preview['finished_date'] ?? '—' }}</strong></div>
                            <div><span>Expiry Date:</span> <strong>{{ $preview['expiry_date'] ?? '—' }}</strong></div>
                            <div><span>Logged Qty (kg):</span> <strong>{{ $preview['booked_quantity_kg_logged'] ?? '—' }}</strong></div>
                            <div><span>Logged Qty (TU):</span> <strong>{{ $preview['booked_quantity_traded_units'] ?? '—' }}</strong></div>
                            @if (! empty($preview['error_message']))
                                <div class="pw-kv-wide" style="color:#b42318;">
                                    <span>Error:</span> {{ $preview['error_message'] }}
                                </div>
                            @endif
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

            $palleconTone = static fn (Pallecon $p): array => match (true) {
                $p->isOnHold() => ['bg' => '#9b3b3b', 'dot' => '#f5c2c2', 'label' => 'On hold'],
                $p->status === Pallecon::STATUS_SEALED => ['bg' => '#1f5c61', 'dot' => '#7fd1bf', 'label' => ucfirst($p->status)],
                $p->status === Pallecon::STATUS_CONSUMED => ['bg' => '#6b7280', 'dot' => '#d1d5db', 'label' => ucfirst($p->status)],
                $p->status === Pallecon::STATUS_FILLING => ['bg' => 'linear-gradient(180deg,#d4ad55,#b8913a)', 'dot' => '#fdf3d0', 'label' => ucfirst($p->status)],
                default => ['bg' => '#3d6a8a', 'dot' => '#c7e0f2', 'label' => ucfirst($p->status)],
            };
        @endphp

        @if ($isCompletedPalleconView)
            {{-- Completed pallecon: read-only summary --}}
            @php $tone = $palleconTone($active); @endphp
            <section class="wm-card wm-card--gear-tr">
                <div class="pw-pallecon">
                    <img src="{{ asset('pallecon-row-icon.png') }}" alt="" />
                    <div>
                        <span class="pw-serial">{{ $active->serial_number ?? 'Pallecon #'.$active->id }}</span>
                        <span class="wm-pill" style="margin-left:8px;background:{{ $tone['bg'] }};"><span class="wm-pill-dot" style="background:{{ $tone['dot'] }};"></span>{{ ucfirst($active->status) }}</span>
                        @if ($active->isOnHold())
                            <span class="wm-pill" style="margin-left:6px;background:#9b3b3b;"><span class="wm-pill-dot" style="background:#f5c2c2;"></span>On hold</span>
                        @endif
                    </div>
                    <div class="pw-weight">
                        <strong>{{ $active->final_weight !== null ? number_format((float) $active->final_weight, 1).' kg' : '—' }}</strong>
                        @if ($active->sealed_at)
                            sealed {{ $active->sealed_at->format('d/m/Y H:i') }}
                        @endif
                        @if ($active->sealedBy)
                            by {{ $active->sealedBy->name }}
                        @endif
                    </div>
                </div>

                @if ($active->isOnHold())
                    <p class="pw-error" style="margin-top:10px;">{{ $active->hold_reason }}</p>
                @endif

                <div class="pw-tiles">
                    <div class="pw-stat"><div class="pw-stat-label">MO</div><div class="pw-stat-value">{{ $localOrder?->mo_number ?? $winmanMo }}</div></div>
                    <div class="pw-stat"><div class="pw-stat-label">WinMan reference</div><div class="pw-stat-value" style="font-family:ui-monospace,monospace;font-size:.9rem;">{{ $active->winman_reference ?: '—' }}</div></div>
                    <div class="pw-stat"><div class="pw-stat-label">Top / bottom seal</div><div class="pw-stat-value">{{ $active->top_seal_number ?: '—' }} / {{ $active->bottom_seal_number ?: '—' }}</div></div>
                    <div class="pw-stat"><div class="pw-stat-label">Liner number</div><div class="pw-stat-value">{{ $active->liner_number ?: '—' }}</div></div>
                </div>

                <h3 class="pw-subhead">Batches used</h3>
                <div class="pw-mini">
                    <div class="pw-mini-row pw-mini-head"><div>Batch</div><div class="pw-num">Weight</div></div>
                    @forelse ($active->fills->sortBy('sequence') as $fill)
                        <div class="pw-mini-row">
                            <div style="font-weight:600;">{{ $fill->batchRecord?->batch_number ?? 'Batch '.$fill->batch_record_id }}</div>
                            <div class="pw-num">{{ $fill->fill_weight !== null ? number_format((float) $fill->fill_weight, 1).' kg' : '—' }}</div>
                        </div>
                    @empty
                        <div class="pw-mini-row"><div class="pw-empty">No fills recorded.</div></div>
                    @endforelse
                </div>

                @php $lastBooking = $active->winmanBookingLogs->sortByDesc('id')->first(); @endphp
                <h3 class="pw-subhead">WinMan booking</h3>
                <div>
                    @if ($lastBooking === null)
                        <span class="pw-tag pw-tag--muted">Not booked</span>
                    @else
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($lastBooking->booking_status === 'success')
                                <span class="pw-tag pw-tag--ok">Booked &middot; Inventory {{ $lastBooking->winman_inventory_id ?? '—' }}</span>
                            @else
                                <span class="pw-tag pw-tag--err">{{ \Illuminate\Support\Str::headline($lastBooking->booking_status) }}</span>
                            @endif
                            <span class="pw-ref">lot {{ $lastBooking->lot_number }} &middot; {{ number_format((float) $lastBooking->quantity_booked_kg, 1) }} kg &middot; {{ $lastBooking->booking_date?->format('d/m/Y H:i') }}</span>
                        </div>
                        @if ($lastBooking->error_message)
                            <p class="pw-error">{{ $lastBooking->error_message }}</p>
                        @endif
                    @endif
                </div>

                <h3 class="pw-subhead">Label prints</h3>
                <div class="pw-mini" style="--pw-cols: minmax(0, 1.2fr) minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1.2fr);">
                    <div class="pw-mini-row pw-mini-head"><div>Printed</div><div>Type</div><div>By</div><div>Status</div></div>
                    @forelse ($active->labelPrintLogs->sortByDesc('printed_at') as $log)
                        <div class="pw-mini-row">
                            <div>{{ $log->printed_at?->format('d/m/Y H:i') ?? '—' }}</div>
                            <div>{{ \Illuminate\Support\Str::headline($log->label_type ?? '—') }}</div>
                            <div>{{ $log->printedBy?->name ?? '—' }}</div>
                            <div>
                                @if ($log->status === 'success')
                                    <span class="pw-tag pw-tag--ok">Printed</span>
                                @else
                                    <span class="pw-tag pw-tag--err">Failed</span>
                                    @if ($log->error_message)
                                        <span class="pw-error">{{ $log->error_message }}</span>
                                    @endif
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="pw-mini-row"><div class="pw-empty">No label prints recorded.</div></div>
                    @endforelse
                </div>
            </section>
        @else

        {{-- Batches available from this MO --}}
        <section class="wm-card wm-card--gear-tr">
            <h2 class="wm-title">Batches &mdash; {{ $localOrder?->mo_number ?? $winmanMo }}</h2>

            @php
                // Fully issued batches have nothing left to put into a pallecon.
                $batchesToFill = collect($this->moBatches)->filter(fn (array $b): bool => $b['remaining_kg'] > 0.0001);
            @endphp
            @if (count($this->moBatches) === 0)
                <p class="pw-empty" style="margin-top:12px;">No batches created for this manufacturing order yet.</p>
            @elseif ($batchesToFill->isEmpty())
                <p class="pw-empty" style="margin-top:12px;">All batches for this manufacturing order have been fully issued to pallecons.</p>
            @else
                <div class="wml-table">
                    <div class="wml-bar wm-grid wm-grid--pwbatch">
                        <div>Batch</div>
                        <div class="wml-num">Batch Qty</div>
                        <div class="wml-num">Qty Filled</div>
                        <div class="wml-num">Qty Remaining</div>
                        <div></div>
                    </div>
                    @foreach ($batchesToFill as $batchOption)
                        @php
                            $canIssue = $batchOption['signoff_complete']
                                && $batchOption['remaining_kg'] > 0.0001
                                && $active !== null
                                && $active->isOpenForFilling()
                                && ($activeRemaining === null || $activeRemaining > 0.0001);
                        @endphp
                        <div @class(['wm-prow wm-grid wm-grid--pwbatch', 'wm-prow--muted' => ! $batchOption['signoff_complete']])>
                            <div class="wm-ref">
                                <div class="wml-ref">{{ $batchOption['batch_number'] }}</div>
                                @unless ($batchOption['signoff_complete'])
                                    <span class="pw-pending">sign-off pending</span>
                                @endunless
                            </div>
                            <div class="wml-num" data-label="Batch Qty" style="color:#1f2a33;font-weight:600;">{{ $fmtKg($batchOption['planned_kg']) }}</div>
                            <div class="wml-num" data-label="Qty Filled" style="color:#1f2a33;font-weight:600;">{{ $fmtKg($batchOption['filled_kg']) }}</div>
                            <div class="wml-num" data-label="Qty Remaining" style="color:#1f2a33;">
                                {{ $fmtKg($batchOption['remaining_kg']) }}
                            </div>
                            <div class="wm-action">
                                <button type="button" wire:click="issueBatchToPallecon({{ $batchOption['id'] }})" wire:loading.attr="disabled" @disabled(! $canIssue)
                                    class="wm-btn-continue">Issue to Pallecon</button>
                            </div>
                        </div>
                    @endforeach
                </div>
                @if ($active === null)
                    <p class="pw-note">No pallecon is open. <a href="{{ route('manufacturing-orders.workspace', ['winmanMo' => $winmanMo]) }}" wire:navigate>Open one on the MO Workspace</a> to issue batches.</p>
                @endif
            @endif
        </section>

        {{-- Pallecon Workspace: the one pallecon this page works on --}}
        <section class="wm-card wm-card--gear-bl">
            <h2 class="wm-title">Pallecon Workspace</h2>

            @if ($active === null)
                <p class="pw-empty" style="margin-top:12px;font-style:normal;">
                    No pallecon selected.
                    <a href="{{ route('manufacturing-orders.workspace', ['winmanMo' => $winmanMo]) }}" wire:navigate class="wm-link">Go to the MO Workspace</a>
                    to create one or pick one from the list.
                </p>
            @else
                @php
                    $filled = $active->filledWeight();
                    $target = $active->target_weight_kg !== null ? (float) $active->target_weight_kg : null;
                    $pct = $target !== null && $target > 0 ? min(100, round($filled / $target * 100)) : null;
                    $isFull = $pct !== null && $pct >= 100;
                    $tone = $palleconTone($active);
                @endphp
                <div class="wml-table" style="padding:16px 18px;">
                    <div class="pw-pallecon">
                        <img src="{{ asset('pallecon-row-icon.png') }}" alt="" />
                        <div>
                            <span class="pw-serial">{{ $active->serial_number ?? 'Pallecon #'.$active->id }}</span>
                            <span class="wm-pill" style="margin-left:8px;background:{{ $tone['bg'] }};"><span class="wm-pill-dot" style="background:{{ $tone['dot'] }};"></span>{{ ucfirst($active->status) }}</span>
                            @if ($isFull)
                                <span class="wm-pill" style="margin-left:6px;background:#1f5c61;"><span class="wm-pill-dot" style="background:#7fd1bf;"></span>Full &mdash; ready to complete</span>
                            @endif
                            @if ($active->winman_reference)
                                <div class="pw-ref" style="margin-top:4px;">Ref: <code>{{ $active->winman_reference }}</code></div>
                            @endif
                        </div>
                        <div class="pw-weight">
                            @if ($target !== null)
                                <strong>{{ number_format($filled, 1) }} / {{ $fmtKg($target) }} kg</strong>
                                {{ $pct }}% filled
                            @else
                                <strong>{{ number_format($filled, 1) }} kg</strong>
                                no target set
                            @endif
                        </div>
                    </div>
                    <div @class(['pw-bar', 'pw-bar--full' => $isFull])><span style="width: {{ $pct ?? 0 }}%"></span></div>

                    <div class="pw-fills">
                        @forelse ($active->fills->sortBy('sequence') as $fill)
                            <div class="pw-fill">
                                <span>#{{ $fill->sequence }} · {{ $fill->batchRecord?->batch_number ?? 'Batch '.$fill->batch_record_id }}</span>
                                <strong>{{ $fill->fill_weight !== null ? number_format((float) $fill->fill_weight, 1).' kg' : '—' }}</strong>
                            </div>
                        @empty
                            <p class="pw-empty">No fills yet.</p>
                        @endforelse
                    </div>

                    {{-- Pallecon number --}}
                    <div class="pw-section">
                        <label class="pw-label">Pallecon number</label>
                        <div style="max-width:20rem;">
                            <input type="text" wire:model.live.blur="containerForm.serial_number" class="pw-input" placeholder="e.g. PAL-00123" />
                        </div>
                    </div>

                    {{-- Complete the pallecon --}}
                    <div class="pw-section">
                        @if ($sealingId === $active->id)
                            <div class="pw-seal">
                                <div>
                                    <label class="pw-label">Final weight (kg) *</label>
                                    <input type="number" step="0.001" min="0.001" wire:model="sealForm.final_weight" class="pw-input" />
                                    @error('sealForm.final_weight') <span class="pw-error">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <label class="pw-label">Top seal (optional)</label>
                                    <input type="text" wire:model="sealForm.top_seal_number" class="pw-input" />
                                </div>
                                <div>
                                    <label class="pw-label">Bottom seal (optional)</label>
                                    <input type="text" wire:model="sealForm.bottom_seal_number" class="pw-input" />
                                </div>
                                <div>
                                    <label class="pw-label">Liner number (optional)</label>
                                    <input type="text" wire:model="sealForm.liner_number" class="pw-input" />
                                </div>
                            </div>
                            <div class="pw-actions" style="margin-top:14px;">
                                <button type="button" wire:click="completePallecon" class="pw-btn">Submit &amp; complete{{ $this->bartenderEnabled ? ' + print label' : '' }}</button>
                                <button type="button" wire:click="cancelSeal" class="pw-btn-ghost">Cancel</button>
                            </div>
                        @else
                            <div class="pw-actions">
                                <button type="button" wire:click="startSeal({{ $active->id }})" @disabled($active->fills->isEmpty()) class="pw-btn">Complete pallecon</button>
                                @unless ($this->bartenderEnabled)
                                    <span class="pw-hint">Label printing disabled (BARTENDER_ENABLED=false)</span>
                                @endunless
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </section>

        @endif

        @unless ($isCompletedPalleconView)
        {{-- Completed containers (this MO only) --}}
        <section class="wm-card">
            <h2 class="wm-title">Completed &mdash; {{ $localOrder?->mo_number ?? $winmanMo }}</h2>
            <div class="wml-table">
                @forelse ($this->completedContainers as $container)
                    @php
                        $hasFailedBooking = $container->hasFailedWinManBooking();
                        $tone = $palleconTone($container);
                    @endphp
                    <div class="wm-prow pw-completed">
                        <div class="pw-completed-main">
                            <img src="{{ asset('pallecon-row-icon.png') }}" alt="" class="wm-picon" />
                            <span class="wml-ref">{{ $container->serial_number ?? 'Pallecon #'.$container->id }}</span>
                            <span class="wm-pill" style="background:{{ $tone['bg'] }};"><span class="wm-pill-dot" style="background:{{ $tone['dot'] }};"></span>{{ $tone['label'] }}</span>
                            <span class="pw-ref">{{ number_format((float) $container->final_weight, 1) }} kg</span>
                            @if ($container->isOnHold())
                                <div class="pw-error" style="flex-basis:100%;">{{ $container->hold_reason }}</div>
                            @endif
                            @if ($this->winmanBookingEnabled && $hasFailedBooking)
                                <div class="pw-hint" style="flex-basis:100%;font-weight:700;">WinMan booking failed.</div>
                            @endif
                        </div>
                        <div class="pw-completed-actions">
                            @if ($this->winmanBookingEnabled && $hasFailedBooking)
                                <button type="button" wire:click="retryWinManBooking({{ $container->id }})" class="pw-btn-warn">Retry WinMan booking</button>
                            @endif
                            @if ($container->isOnHold())
                                <span class="pw-error">Quarantined — labelling blocked</span>
                            @elseif ($this->bartenderEnabled)
                                <button type="button" wire:click="printLabel({{ $container->id }})" class="wm-btn-continue">Print label</button>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="wml-empty">No completed pallecons yet.</div>
                @endforelse
            </div>
        </section>
        @endunless

    </div>
</div>
