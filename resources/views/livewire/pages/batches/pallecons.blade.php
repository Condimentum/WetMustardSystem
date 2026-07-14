<?php

use App\Features\Pallecon\AddPalleconRecordFeature;
use App\Features\Booking\BookFinishedGoodsFeature;
use App\Features\Pallecon\PrintPalleconLabelFeature;
use App\Models\BatchRecord;
use App\Models\PalleconRecord;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Pallecon Filling')] class extends Component {
    public BatchRecord $batch;

    /** @var array<string, mixed> */
    public array $form = [
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
        $this->batch = $batch->load(['pallecons.checkedBy', 'manufacturingOrder']);
        $this->bartender_enabled = (bool) config('services.bartender.enabled', false);
        $this->label_production_date = now()->toDateString();
    }

    public function addPallecon(): void
    {
        if ((string) ($this->form['fill_weight'] ?? '') === '') {
            $this->form['fill_weight'] = '1';
        }

        $validated = $this->validate([
            'form.ticket_number' => ['required', 'string', 'max:255'],
            'form.serial_number' => ['nullable', 'string', 'max:255'],
            'form.top_seal_number' => ['nullable', 'string', 'max:255'],
            'form.bottom_seal_number' => ['nullable', 'string', 'max:255'],
            'form.liner_number' => ['nullable', 'string', 'max:255'],
            'form.liner_batch_code' => ['nullable', 'string', 'max:255'],
            'form.fill_weight' => ['required', 'numeric', 'min:0'],
            'form.start_time' => ['nullable', 'date'],
            'form.finish_time' => ['nullable', 'date'],
        ])['form'];

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

        $this->reset('form');
        $this->form['fill_weight'] = '1';
        $this->batch = $this->batch->fresh(['pallecons.checkedBy', 'manufacturingOrder']);
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

        $shelfDays = (int) ($this->batch->product?->shelf_life_days ?? 180);

        return $fallbackBase->copy()->addDays($shelfDays)->endOfMonth();
    }

    /**
     * @param  array<string, mixed>  $result
     */
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

    /**
     * @return array<string, mixed>
     */
    public function getLabelPreviewProperty(): array
    {
        $fillWeightInput = (string) ($this->form['fill_weight'] ?? '1');
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
            'winman_lot_number' => trim((string) (($sources['ManufacturingOrder'] ?? 'MO').' '.trim((string) ($this->form['ticket_number'] ?? '')).' '.($lotNumberLabelStyle ?? ''))),
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
}; ?>

<div class="py-8">
    <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Pallecon Filling</h2>
                <p class="text-sm text-gray-500">Batch {{ $batch->batch_number }} · bulk fill traceability (WM004)</p>
            </div>
            <a href="{{ route('batches.show', $batch) }}" wire:navigate class="text-sm text-indigo-600 hover:underline">← Batch record</a>
        </div>

        @if ($print_message)
            <div class="rounded-md p-3 text-sm {{ $print_failed ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-green-50 text-green-700 border border-green-200' }}">
                {{ $print_message }}
            </div>
        @endif

        @if ($winman_booking_preview)
            <div class="border border-emerald-200 rounded-lg p-4 bg-emerald-50">
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

        <form wire:submit="addPallecon" class="bg-white shadow-sm rounded-lg p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Pallecon Number</label>
                    <input type="text" wire:model.live.debounce.300ms="form.ticket_number" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
                    @error('form.ticket_number') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs text-gray-600 mb-1">Fill weight (kg)</label>
                    <input type="number" step="0.001" min="0" wire:model.live.debounce.300ms="form.fill_weight" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
                    @error('form.fill_weight') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
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
                <x-primary-button type="submit" class="w-full justify-center">Complete Batch</x-primary-button>
            </div>
        </form>

        <div class="border border-slate-200 rounded-lg p-4 bg-slate-50">
            <h3 class="text-sm font-semibold text-slate-700 mb-3">Label Preview (Before Print)</h3>

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

        {{-- Recorded pallecons list intentionally hidden for single-label workflow. --}}
    </div>
</div>
