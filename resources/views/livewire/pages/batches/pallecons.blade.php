<?php

use App\Features\Pallecon\AddPalleconRecordFeature;
use App\Features\Pallecon\PrintPalleconLabelFeature;
use App\Models\BatchRecord;
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

    public bool $print_after_save = false;
    public bool $bartender_enabled = false;
    public string $label_production_date = '';
    public ?string $print_message = null;
    public bool $print_failed = false;

    public function mount(BatchRecord $batch): void
    {
        $this->batch = $batch->load(['pallecons.checkedBy', 'manufacturingOrder']);
        $this->bartender_enabled = (bool) config('services.bartender.enabled', false);
        $this->print_after_save = $this->bartender_enabled;
        $this->label_production_date = now()->toDateString();
    }

    public function addPallecon(): void
    {
        if ((string) ($this->form['fill_weight'] ?? '') === '') {
            $this->form['fill_weight'] = '1';
        }

        $validated = $this->validate([
            'form.ticket_number' => ['nullable', 'string', 'max:255'],
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

        $this->print_message = null;
        $this->print_failed = false;

        if ($this->print_after_save && $this->bartender_enabled) {
            try {
                $result = app(PrintPalleconLabelFeature::class)($pallecon, 1, [
                    'production_date' => $this->label_production_date,
                ]);
                $this->print_message = $this->formatPrintMessage($result);
            } catch (\Throwable $e) {
                $this->print_failed = true;
                $this->print_message = 'Pallecon saved, but label print failed: '.$e->getMessage();
            }
        }

        $this->reset('form');
        $this->form['fill_weight'] = '1';
        $this->batch = $this->batch->fresh(['pallecons.checkedBy', 'manufacturingOrder']);
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

        <form wire:submit="addPallecon" class="bg-white shadow-sm rounded-lg p-6 grid grid-cols-1 md:grid-cols-3 gap-4">
            @if ($print_message)
                <div class="md:col-span-3 rounded-md p-3 text-sm {{ $print_failed ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-green-50 text-green-700 border border-green-200' }}">
                    {{ $print_message }}
                </div>
            @endif

            <div>
                <label class="block text-xs text-gray-600 mb-1">Fill weight (kg)</label>
                <input type="number" step="0.001" min="0" wire:model="form.fill_weight" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
                @error('form.fill_weight') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
            </div>
            <div class="md:col-span-2 flex items-end">
                <div class="space-y-2">
                    <label class="block text-xs text-gray-600 mb-1">Label production date (BBE uses +5 months)</label>
                    <input type="date" wire:model="label_production_date" class="border-gray-300 rounded-md shadow-sm text-sm" />
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="print_after_save" class="rounded border-gray-300" {{ $bartender_enabled ? '' : 'disabled' }} />
                        Print Wet Mustard test label after save (WetMustard LabelNew.btw)
                    </label>
                    @if (! $bartender_enabled)
                        <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-1">
                            Printing is disabled. Set <strong>BARTENDER_ENABLED=true</strong> in the environment to enable label printing.
                        </p>
                    @endif
                </div>
            </div>
            <div class="flex items-end">
                <x-primary-button type="submit" class="w-full justify-center">Add pallecon</x-primary-button>
            </div>
        </form>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @forelse ($batch->pallecons as $pallecon)
                <div class="bg-white shadow-sm rounded-lg p-4 space-y-2">
                    <div class="flex items-center justify-between">
                        <div class="font-semibold text-gray-800">#{{ $pallecon->serial_number }}</div>
                        <div class="text-xs text-gray-400">{{ $pallecon->ticket_number }}</div>
                    </div>
                    <dl class="text-sm text-gray-600 space-y-1">
                        <div class="flex justify-between"><dt class="text-gray-500">Fill weight</dt><dd>{{ $pallecon->fill_weight ? rtrim(rtrim((string) $pallecon->fill_weight, '0'), '.').' kg' : '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Top seal</dt><dd>{{ $pallecon->top_seal_number ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Bottom seal</dt><dd>{{ $pallecon->bottom_seal_number ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Liner</dt><dd>{{ $pallecon->liner_number ?? '—' }} / {{ $pallecon->liner_batch_code ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Checked by</dt><dd>{{ $pallecon->checkedBy?->name ?? '—' }}</dd></div>
                    </dl>
                </div>
            @empty
                <div class="col-span-full text-center text-sm text-gray-500 bg-white rounded-lg p-8">No pallecons recorded yet.</div>
            @endforelse
        </div>
    </div>
</div>
