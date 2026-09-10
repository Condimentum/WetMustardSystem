<?php

use App\Domains\Pallecon\Support\PalleconCapacity;
use App\Domains\Pallecon\Support\PalleconFilling;
use App\Features\Pallecon\AttachBatchFillFeature;
use App\Features\Pallecon\PrintPalleconLabelFeature;
use App\Features\Pallecon\SealPalleconFeature;
use App\Models\BatchRecord;
use App\Models\ManufacturingOrder;
use App\Models\Pallecon;
use App\Models\PalleconRecord;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Pallecon Workspace')] class extends Component {
    public int $winmanMo;

    public ?ManufacturingOrder $localOrder = null;

    /** When set (?pallecon=), the page is scoped to this one pallecon. */
    public ?int $palleconId = null;

    /** Pallecon number / seal / liner details, edited inside the open pallecon. */
    public array $containerForm = [
        'serial_number' => '',
        'top_seal_number' => '',
        'bottom_seal_number' => '',
        'liner_number' => '',
    ];

    public string $fillBatchId = '';
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
        $this->palleconId = request()->integer('pallecon') ?: null;
        $this->production_date = now()->toDateString();
        $this->syncContainerForm();
    }

    /** Mirror the pallecon's number/seal/liner values into the editable form. */
    private function syncContainerForm(): void
    {
        $container = $this->activeContainer;

        $this->containerForm = [
            'serial_number' => (string) ($container?->serial_number ?? ''),
            'top_seal_number' => (string) ($container?->top_seal_number ?? ''),
            'bottom_seal_number' => (string) ($container?->bottom_seal_number ?? ''),
            'liner_number' => (string) ($container?->liner_number ?? ''),
        ];
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
                ->with(['fills.batchRecord:id,batch_number,manufacturing_order_id'])
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
     * Resolves and guards the selected source batch for a fill of $weight kg.
     *
     * @return BatchRecord|null  null on failure (flash set)
     */
    private function resolveFillBatch(float $weight): ?BatchRecord
    {
        $batchMeta = collect($this->moBatches)->firstWhere('id', (int) $this->fillBatchId);

        $error = app(PalleconFilling::class)->guardFill($batchMeta, $weight);
        if ($error !== null) {
            $this->flash = $error;
            $this->flashError = true;

            return null;
        }

        return BatchRecord::with('manufacturingOrder', 'product')->findOrFail((int) $this->fillBatchId);
    }

    public function addFill(): void
    {
        $this->validate([
            'fillBatchId' => ['required', 'integer'],
            'fillWeight' => ['required', 'numeric', 'min:0.001'],
        ]);

        $this->flash = null;
        $this->winman_booking_preview = null;

        $container = $this->activeContainer;
        if ($container === null) {
            $this->flash = 'No pallecon selected. Create one on the MO Workspace first.';
            $this->flashError = true;

            return;
        }

        $batch = $this->resolveFillBatch((float) $this->fillWeight);
        if ($batch === null) {
            return;
        }

        try {
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
        $messages = $this->bookFillToWinMan($batch, $container, $fill, $messages);

        $this->flash = implode(' ', $messages);
        $this->fillWeight = '';
        unset($this->activeContainer, $this->moBatches);
    }

    public function saveContainerDetails(): void
    {
        $container = $this->activeContainer;
        if ($container === null) {
            return;
        }

        $data = $this->validate([
            'containerForm.serial_number' => ['nullable', 'string', 'max:255'],
            'containerForm.top_seal_number' => ['nullable', 'string', 'max:255'],
            'containerForm.bottom_seal_number' => ['nullable', 'string', 'max:255'],
            'containerForm.liner_number' => ['nullable', 'string', 'max:255'],
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
            'top_seal_number' => ($data['top_seal_number'] ?? '') ?: null,
            'bottom_seal_number' => ($data['bottom_seal_number'] ?? '') ?: null,
            'liner_number' => ($data['liner_number'] ?? '') ?: null,
        ]);

        $this->flash = 'Pallecon '.($container->serial_number ?? '#'.$container->id).' details saved.';
        $this->flashError = false;
        unset($this->activeContainer);
        $this->syncContainerForm();
    }

    /**
     * @param  array<int, string>  $messages
     * @return array<int, string>
     */
    private function bookFillToWinMan(BatchRecord $batch, Pallecon $container, \App\Models\PalleconFill $fill, array $messages): array
    {
        $result = app(PalleconFilling::class)->bookFill(
            $batch,
            $container,
            $fill,
            $this->production_date !== '' ? $this->production_date : now()->toDateString(),
            auth()->user(),
        );

        if ($result['preview'] !== null) {
            $this->winman_booking_preview = $result['preview'];
        }

        if ($result['error']) {
            $this->flashError = true;
        }

        return array_merge($messages, $result['messages']);
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

        if ($this->bartenderEnabled) {
            $printResult = $this->printLabelFor($sealed);
            $messages[] = $printResult;
        }

        $this->flash = implode(' ', $messages);
        unset($this->activeContainer, $this->completedContainers);
        $this->syncContainerForm();
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

        {{-- Pallecon Workspace: the one pallecon this page works on --}}
        @php $active = $this->activeContainer; @endphp
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
                    $pct = $this->limitKg > 0 ? min(100, round($filled / $this->limitKg * 100)) : 0;
                @endphp
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <span class="font-semibold text-slate-900">{{ $active->serial_number ?? 'Pallecon #'.$active->id }}</span>
                        <span class="ml-2 text-xs px-2 py-0.5 rounded-full bg-amber-100 text-amber-800">{{ ucfirst($active->status) }}</span>
                        @if ($active->winman_reference)
                            <span class="ml-2 text-xs text-slate-500">Ref: <span class="font-mono text-slate-700">{{ $active->winman_reference }}</span></span>
                        @endif
                    </div>
                    <div class="text-sm text-slate-500">{{ number_format($filled, 1) }} / {{ number_format($this->limitKg, 0) }} kg
                        @if ($active->target_weight_kg)· target {{ $fmtKg($active->target_weight_kg) }} kg @endif</div>
                </div>
                <div class="mt-2 h-2 w-full rounded-full bg-slate-100 overflow-hidden">
                    <div class="h-full bg-amber-400" style="width: {{ $pct }}%"></div>
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

                {{-- Add another fill from the selected batch --}}
                <div class="mt-4 border-t border-slate-100 pt-4">
                    <h3 class="text-xs font-semibold uppercase text-slate-400 mb-2">Add fill</h3>
                    @if ($selectedBatch)
                        <p class="text-sm text-slate-600 mb-2">From batch <strong>{{ $selectedBatch['batch_number'] }}</strong> &mdash;
                            <span class="font-semibold {{ $selectedBatch['remaining_kg'] <= 0.0001 ? 'text-emerald-600' : 'text-slate-900' }}">{{ $fmtKg($selectedBatch['remaining_kg']) }} kg</span> remaining</p>
                    @else
                        <p class="text-sm text-amber-700 mb-2">Select a batch above to add another fill.</p>
                    @endif
                    <div class="flex flex-wrap items-end gap-3">
                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1">Fill weight (kg) *</label>
                            <input type="number" step="0.001" min="0.001"
                                @if ($selectedBatch) max="{{ $fmtKg($selectedBatch['remaining_kg']) }}" @endif
                                wire:model="fillWeight" class="w-40 rounded-lg border-slate-300 text-sm" placeholder="e.g. 200" />
                            @error('fillWeight') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                        </div>
                        <button type="button" wire:click="addFill" wire:loading.attr="disabled" @disabled(! $selectedBatch)
                            class="inline-flex items-center px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-500 disabled:opacity-40 disabled:cursor-not-allowed">Add to Pallecon</button>
                        @error('fillBatchId') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                </div>

                {{-- Pallecon number, seals & liner --}}
                <div class="mt-4 border-t border-slate-100 pt-4">
                    <h3 class="text-xs font-semibold uppercase text-slate-400 mb-2">Pallecon number, seals &amp; liner</h3>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1">Pallecon number</label>
                            <input type="text" wire:model="containerForm.serial_number" class="w-full rounded-lg border-slate-300 text-sm" placeholder="e.g. PAL-00123" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1">Top seal</label>
                            <input type="text" wire:model="containerForm.top_seal_number" class="w-full rounded-lg border-slate-300 text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1">Bottom seal</label>
                            <input type="text" wire:model="containerForm.bottom_seal_number" class="w-full rounded-lg border-slate-300 text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1">Liner number</label>
                            <input type="text" wire:model="containerForm.liner_number" class="w-full rounded-lg border-slate-300 text-sm" />
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="button" wire:click="saveContainerDetails" class="inline-flex items-center px-3 py-2 rounded-lg bg-slate-100 text-slate-700 text-sm font-semibold hover:bg-slate-200">Save details</button>
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
                            <div class="flex items-end gap-2">
                                <button type="button" wire:click="completePallecon" class="inline-flex items-center px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-500">Complete{{ $this->bartenderEnabled ? ' & print label' : '' }}</button>
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
