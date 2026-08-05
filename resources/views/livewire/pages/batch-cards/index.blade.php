<?php

use App\Domains\Batch\Jobs\ExtractBatchCardMetadataJob;
use App\Models\BatchCard;
use App\Models\BatchRecord;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Batch Cards')] class extends Component {
    use WithFileUploads;

    public ?int $editingId = null;

    public string $document_code = '';

    public string $title = '';

    public string $product_code = '';

    public string $revision = '';

    public string $issue_date = '';

    public string $effective_from = '';

    public string $effective_to = '';

    public string $status = 'draft';

    public bool $is_current = false;

    public string $notes = '';

    public ?TemporaryUploadedFile $pdfUpload = null;

    public ?string $existingPdfPath = null;

    public ?string $flash = null;

    public ?string $error = null;

    public ?string $extractInfo = null;

    /** @var array<string, mixed> */
    public array $previewExtracted = [];

    /** @var array<int, array<string, mixed>> */
    public array $cards = [];

    public function mount(): void
    {
        $this->loadCards();
    }

    public function loadCards(): void
    {
        $this->cards = BatchCard::query()
            ->orderBy('document_code')
            ->orderByDesc('is_current')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get()
            ->map(function (BatchCard $card): array {
                $extracted = is_array($card->metadata['extracted'] ?? null)
                    ? (array) $card->metadata['extracted']
                    : [];

                $totals = is_array($extracted['totals'] ?? null)
                    ? (array) $extracted['totals']
                    : [];

                $batchQuantity = $totals['quantity'] ?? null;
                $batchUom = trim((string) ($totals['uom'] ?? ''));
                $batchSize = '';

                if ($batchQuantity !== null && $batchQuantity !== '') {
                    $batchQuantityNumber = (float) $batchQuantity;
                    $batchQuantityLabel = rtrim(rtrim(number_format($batchQuantityNumber, 3, '.', ''), '0'), '.');
                    $batchSize = $batchQuantityLabel;
                    if ($batchUom !== '') {
                        $batchSize .= ' ' . strtoupper($batchUom);
                    }
                }

                return [
                    'id' => $card->id,
                    'document_code' => (string) $card->document_code,
                    'title' => (string) $card->title,
                    'product_code' => (string) ($card->product_code ?? ''),
                    'recipe_code' => (string) ($extracted['recipe_code'] ?? ''),
                    'batch_size' => $batchSize,
                    'revision' => (string) $card->revision,
                    'issue_date' => $card->issue_date?->format('Y-m-d'),
                    'effective_from' => $card->effective_from?->format('Y-m-d'),
                    'effective_to' => $card->effective_to?->format('Y-m-d'),
                    'status' => (string) $card->status,
                    'is_current' => (bool) $card->is_current,
                    'pdf_path' => (string) ($card->pdf_path ?? ''),
                    'pdf_url' => $card->pdf_path ? route('batch-cards.pdf', ['batchCard' => $card->id]) : null,
                    'updated_at' => $card->updated_at?->format('Y-m-d H:i'),
                ];
            })
            ->all();
    }

    public function extractMetadataFromFilename(): void
    {
        if (! $this->pdfUpload instanceof TemporaryUploadedFile) {
            return;
        }

        $filename = pathinfo($this->pdfUpload->getClientOriginalName(), PATHINFO_FILENAME);
        $normalized = trim(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', (string) $filename)) ?? '');

        if ($this->title === '') {
            $this->title = Str::title($normalized);
        }

        if ($this->document_code === '' && preg_match('/\b([A-Z]{2,5}\d{2,6})\b/i', $normalized, $matches) === 1) {
            $this->document_code = strtoupper((string) ($matches[1] ?? ''));
        }

        if ($this->revision === '' && preg_match('/\b(?:rev|v)\s*([0-9]+(?:\.[0-9]+)?)\b/i', $normalized, $matches) === 1) {
            $this->revision = (string) ($matches[1] ?? '');
        }
    }

    public function updatedPdfUpload(): void
    {
        $this->extractMetadataFromPdf();
    }

    public function extractMetadataFromPdf(): void
    {
        $this->extractInfo = null;
        $this->error = null;

        if (! $this->pdfUpload instanceof TemporaryUploadedFile) {
            return;
        }

        $this->extractMetadataFromFilename();

        $tempPath = $this->pdfUpload->getRealPath();
        if (! is_string($tempPath) || trim($tempPath) === '') {
            $this->extractInfo = 'PDF selected, but temporary path is unavailable for extraction.';

            return;
        }

        $result = app(ExtractBatchCardMetadataJob::class)($tempPath);
        $metadata = (array) ($result['metadata'] ?? []);
        $this->previewExtracted = $metadata;

        if (($metadata['document_code'] ?? null) && $this->document_code === '') {
            $this->document_code = (string) $metadata['document_code'];
        }

        if (($metadata['revision'] ?? null) && $this->revision === '') {
            $this->revision = (string) $metadata['revision'];
        }

        if (($metadata['product_code'] ?? null) && $this->product_code === '') {
            $this->product_code = (string) $metadata['product_code'];
        }

        if (($metadata['title'] ?? null) && $this->title === '') {
            $this->title = Str::limit((string) $metadata['title'], 255, '');
        }

        if (($metadata['issue_date'] ?? null) && $this->issue_date === '') {
            $this->issue_date = (string) $metadata['issue_date'];
        }

        if (($metadata['effective_from'] ?? null) && $this->effective_from === '') {
            $this->effective_from = (string) $metadata['effective_from'];
        }

        if (($metadata['extract_error'] ?? null) !== null) {
            $this->extractInfo = 'PDF uploaded, but text extraction was partial. You can still save and edit fields manually.';

            return;
        }

        $stepsCount = (int) ($metadata['process_steps_count'] ?? 0);
        $ingredientsCount = (int) ($metadata['ingredients_count'] ?? 0);
        $this->extractInfo = 'PDF extraction completed. Parsed '.$stepsCount.' process steps and '.$ingredientsCount.' ingredient rows. Review the populated fields before saving.';
    }

    public function edit(int $id): void
    {
        $this->error = null;
        $card = BatchCard::query()->findOrFail($id);

        $this->editingId = $card->id;
        $this->document_code = (string) $card->document_code;
        $this->title = (string) $card->title;
        $this->product_code = (string) ($card->product_code ?? '');
        $this->revision = (string) $card->revision;
        $this->issue_date = (string) ($card->issue_date?->format('Y-m-d') ?? '');
        $this->effective_from = (string) ($card->effective_from?->format('Y-m-d') ?? '');
        $this->effective_to = (string) ($card->effective_to?->format('Y-m-d') ?? '');
        $this->status = (string) $card->status;
        $this->is_current = (bool) $card->is_current;
        $this->notes = (string) ($card->notes ?? '');
        $this->existingPdfPath = $card->pdf_path;
        $this->pdfUpload = null;
        $this->previewExtracted = is_array($card->metadata['extracted'] ?? null)
            ? (array) $card->metadata['extracted']
            : [];
    }

    public function cancelEdit(): void
    {
        $this->reset([
            'editingId',
            'document_code',
            'title',
            'product_code',
            'revision',
            'issue_date',
            'effective_from',
            'effective_to',
            'notes',
            'pdfUpload',
            'existingPdfPath',
            'extractInfo',
            'previewExtracted',
        ]);

        $this->status = 'draft';
        $this->is_current = false;
        $this->error = null;
    }

    public function save(): void
    {
        $this->flash = null;
        $this->error = null;

        $validated = $this->validate([
            'document_code' => ['required', 'string', 'max:50'],
            'title' => ['required', 'string', 'max:255'],
            'product_code' => ['nullable', 'string', 'max:80'],
            'revision' => [
                'required',
                'string',
                'max:50',
                Rule::unique('batch_cards', 'revision')
                    ->where(fn ($query) => $query->where('document_code', strtoupper(trim($this->document_code))))
                    ->ignore($this->editingId),
            ],
            'issue_date' => ['nullable', 'date'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'status' => ['required', Rule::in(['draft', 'active', 'superseded', 'archived'])],
            'is_current' => ['boolean'],
            'notes' => ['nullable', 'string'],
            'pdfUpload' => ['nullable', 'file', 'mimes:pdf', 'max:15360'],
        ]);

        $documentCode = strtoupper(trim((string) $validated['document_code']));

        $card = $this->editingId !== null
            ? BatchCard::query()->findOrFail($this->editingId)
            : new BatchCard();

        $card->fill([
            'document_code' => $documentCode,
            'title' => trim((string) $validated['title']),
            'product_code' => trim((string) ($validated['product_code'] ?? '')) ?: null,
            'revision' => trim((string) $validated['revision']),
            'issue_date' => $validated['issue_date'] ?: null,
            'effective_from' => $validated['effective_from'] ?: null,
            'effective_to' => $validated['effective_to'] ?: null,
            'status' => $validated['status'],
            'is_current' => (bool) $validated['is_current'],
            'notes' => trim((string) ($validated['notes'] ?? '')) ?: null,
            'updated_by' => auth()->id(),
        ]);

        if (! $card->exists) {
            $card->created_by = auth()->id();
        }

        if ($this->pdfUpload instanceof TemporaryUploadedFile) {
            if ($card->pdf_path) {
                Storage::disk('public')->delete($card->pdf_path);
            }

            $storedPath = $this->pdfUpload->store('batch-cards', 'public');
            $extractResult = app(ExtractBatchCardMetadataJob::class)(storage_path('app/public/'.$storedPath));

            $card->pdf_path = $storedPath;
            $card->metadata = [
                'original_filename' => $this->pdfUpload->getClientOriginalName(),
                'uploaded_at' => now()->toIso8601String(),
                'extracted' => (array) ($extractResult['metadata'] ?? []),
            ];
            $card->extracted_text = (string) ($extractResult['extracted_text'] ?? '');
        }

        $card->save();

        if ($card->is_current || $card->status === 'active') {
            BatchCard::query()
                ->where('document_code', $card->document_code)
                ->where('id', '!=', $card->id)
                ->update(['is_current' => false]);

            $card->forceFill(['is_current' => true, 'status' => 'active'])->save();
        }

        $this->cancelEdit();
        $this->loadCards();
        $this->flash = 'Batch card saved.';
    }

    public function delete(int $id): void
    {
        $this->flash = null;
        $this->error = null;

        $card = BatchCard::query()->findOrFail($id);

        $inUse = BatchRecord::query()
            ->where('batch_card_id', $card->id)
            ->exists();

        if ($inUse) {
            $this->error = 'Cannot delete this batch card because it is already linked to one or more batch records.';

            return;
        }

        if ($card->pdf_path) {
            Storage::disk('public')->delete($card->pdf_path);
        }

        $card->delete();

        if ($this->editingId === $id) {
            $this->cancelEdit();
        }

        $this->loadCards();
        $this->flash = 'Batch card deleted.';
    }
}; ?>

<div class="py-8">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div>
            <h2 class="text-xl font-semibold text-gray-800">Batch Cards</h2>
            <p class="text-sm text-gray-600">Store and version controlled batch card documents. Each document code can keep multiple revisions and one current active revision.</p>
        </div>

        @if ($flash)
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ $flash }}</div>
        @endif

        @if ($error)
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $error }}</div>
        @endif

        <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 font-medium text-gray-800">
                {{ $editingId ? 'Edit Batch Card Revision' : 'Add Batch Card Revision' }}
            </div>

            <div class="p-5 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Document code</label>
                        <input wire:model.defer="document_code" type="text" class="w-full border-gray-300 rounded-md shadow-sm text-sm" placeholder="WM018" />
                        @error('document_code') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Revision</label>
                        <input wire:model.defer="revision" type="text" class="w-full border-gray-300 rounded-md shadow-sm text-sm" placeholder="1.0" />
                        @error('revision') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Product code</label>
                        <input wire:model.defer="product_code" type="text" class="w-full border-gray-300 rounded-md shadow-sm text-sm" placeholder="10010013" />
                        @error('product_code') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Status</label>
                        <select wire:model.defer="status" class="w-full border-gray-300 rounded-md shadow-sm text-sm">
                            <option value="draft">Draft</option>
                            <option value="active">Active</option>
                            <option value="superseded">Superseded</option>
                            <option value="archived">Archived</option>
                        </select>
                        @error('status') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div>
                    <label class="block text-xs text-gray-600 mb-1">Title</label>
                    <input wire:model.defer="title" type="text" class="w-full border-gray-300 rounded-md shadow-sm text-sm" placeholder="Salt Batch Card" />
                    @error('title') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Issue date</label>
                        <input wire:model.defer="issue_date" type="date" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
                        @error('issue_date') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Effective from</label>
                        <input wire:model.defer="effective_from" type="date" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
                        @error('effective_from') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Effective to</label>
                        <input wire:model.defer="effective_to" type="date" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
                        @error('effective_to') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div>
                    <label class="block text-xs text-gray-600 mb-1">PDF</label>
                    <input wire:model="pdfUpload" type="file" accept="application/pdf" class="w-full text-sm" />
                    @error('pdfUpload') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    <div class="mt-2 flex items-center gap-2">
                        <x-secondary-button type="button" wire:click="extractMetadataFromPdf">Extract PDF metadata</x-secondary-button>
                        @if ($existingPdfPath && $editingId)
                            <a href="{{ route('batch-cards.pdf', ['batchCard' => $editingId]) }}" target="_blank" class="text-sm text-indigo-600 hover:underline">Open current PDF</a>
                        @endif
                    </div>
                    <p class="mt-1 text-xs text-gray-500">PDF text is extracted automatically to assist document code, revision, product code and date fields.</p>
                    @if ($extractInfo)
                        <p class="mt-1 text-xs text-indigo-700">{{ $extractInfo }}</p>
                    @endif
                </div>

                @if (count($previewExtracted) > 0)
                    <div class="rounded-lg border border-indigo-200 bg-indigo-50/40 p-4 space-y-4">
                        <div class="text-sm font-semibold text-indigo-900">Parsed Preview</div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs">
                            <div><span class="text-slate-500">Recipe Code:</span> <span class="font-medium text-slate-800">{{ $previewExtracted['recipe_code'] ?? '—' }}</span></div>
                            <div><span class="text-slate-500">PLC Recipe Number:</span> <span class="font-medium text-slate-800">{{ $previewExtracted['plc_recipe_number'] ?? '—' }}</span></div>
                            <div><span class="text-slate-500">Reason For Issue:</span> <span class="font-medium text-slate-800">{{ $previewExtracted['reason_for_issue'] ?? '—' }}</span></div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs">
                            <div><span class="text-slate-500">Steps Parsed:</span> <span class="font-medium text-slate-800">{{ (int) ($previewExtracted['process_steps_count'] ?? 0) }}</span></div>
                            <div><span class="text-slate-500">Ingredient Rows:</span> <span class="font-medium text-slate-800">{{ (int) ($previewExtracted['ingredients_count'] ?? 0) }}</span></div>
                            <div><span class="text-slate-500">Totals:</span>
                                <span class="font-medium text-slate-800">
                                    {{ (string) ($previewExtracted['totals']['percentage'] ?? '—') }} % /
                                    {{ (string) ($previewExtracted['totals']['quantity'] ?? '—') }}
                                    {{ (string) ($previewExtracted['totals']['uom'] ?? '') }}
                                </span>
                            </div>
                        </div>

                        @php
                            $previewSteps = is_array($previewExtracted['process_steps'] ?? null) ? $previewExtracted['process_steps'] : [];
                            $previewIngredients = is_array($previewExtracted['ingredients'] ?? null) ? $previewExtracted['ingredients'] : [];
                        @endphp

                        @if (count($previewSteps) > 0)
                            <div>
                                <div class="text-xs font-semibold text-slate-700 mb-2">Process Steps</div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                                    @foreach ($previewSteps as $step)
                                        <div class="rounded border border-slate-200 bg-white px-3 py-2 text-xs">
                                            <span class="font-semibold text-slate-800">Step {{ $step['step_no'] ?? '?' }}</span>
                                            <span class="text-slate-600"> - {{ $step['title'] ?? '' }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if (count($previewIngredients) > 0)
                            <div>
                                <div class="text-xs font-semibold text-slate-700 mb-2">Ingredients</div>
                                <div class="overflow-x-auto rounded border border-slate-200 bg-white">
                                    <table class="min-w-full text-xs">
                                        <thead class="bg-slate-50 text-slate-600">
                                            <tr>
                                                <th class="px-2 py-2 text-left">Step</th>
                                                <th class="px-2 py-2 text-left">Allergen</th>
                                                <th class="px-2 py-2 text-left">Material</th>
                                                <th class="px-2 py-2 text-left">Description</th>
                                                <th class="px-2 py-2 text-right">%</th>
                                                <th class="px-2 py-2 text-right">Qty</th>
                                                <th class="px-2 py-2 text-left">UOM</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100">
                                            @foreach ($previewIngredients as $ingredient)
                                                <tr>
                                                    <td class="px-2 py-1.5">{{ $ingredient['step_no'] ?? '—' }}</td>
                                                    <td class="px-2 py-1.5">{{ $ingredient['allergen_material'] ?? '—' }}</td>
                                                    <td class="px-2 py-1.5 font-medium text-slate-800">{{ $ingredient['material_code'] ?? '—' }}</td>
                                                    <td class="px-2 py-1.5">{{ $ingredient['description'] ?? '—' }}</td>
                                                    <td class="px-2 py-1.5 text-right">{{ $ingredient['percentage'] ?? '—' }}</td>
                                                    <td class="px-2 py-1.5 text-right">{{ $ingredient['quantity'] ?? '—' }}</td>
                                                    <td class="px-2 py-1.5">{{ $ingredient['uom'] ?? '—' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif

                <div>
                    <label class="block text-xs text-gray-600 mb-1">Notes</label>
                    <textarea wire:model.defer="notes" rows="3" class="w-full border-gray-300 rounded-md shadow-sm text-sm" placeholder="Document control notes, approval remarks, supersession reason..."></textarea>
                    @error('notes') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>

                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model.defer="is_current" class="rounded border-gray-300 text-indigo-600 shadow-sm" />
                    Mark this revision as current
                </label>
            </div>

            <div class="px-5 py-4 border-t border-gray-100 flex justify-end gap-2">
                @if ($editingId)
                    <x-secondary-button type="button" wire:click="cancelEdit">Cancel edit</x-secondary-button>
                @endif
                <x-primary-button type="button" wire:click="save">Save batch card</x-primary-button>
            </div>
        </div>

        <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 font-medium text-gray-800">Stored Batch Cards</div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Code</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Revision</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Recipe Code</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Title</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Product</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Batch Size</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Status</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Issue Date</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">PDF</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-600">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($cards as $card)
                            <tr>
                                <td class="px-4 py-3 font-semibold text-gray-900">
                                    {{ $card['document_code'] }}
                                    @if ($card['is_current'])
                                        <span class="ml-2 inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">Current</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-700">{{ $card['revision'] }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ $card['recipe_code'] !== '' ? $card['recipe_code'] : '—' }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ $card['title'] }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ $card['product_code'] ?: '—' }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ $card['batch_size'] !== '' ? $card['batch_size'] : '—' }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ ucfirst($card['status']) }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ $card['issue_date'] ?: '—' }}</td>
                                <td class="px-4 py-3 text-gray-700">
                                    @if ($card['pdf_url'])
                                        <a href="{{ $card['pdf_url'] }}" target="_blank" class="text-indigo-600 hover:underline">Open PDF</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="inline-flex items-center gap-2">
                                        <x-secondary-button type="button" wire:click="edit({{ $card['id'] }})">Edit</x-secondary-button>
                                        <button
                                            type="button"
                                            wire:click="delete({{ $card['id'] }})"
                                            wire:confirm="Delete this batch card revision and linked PDF file?"
                                            class="inline-flex items-center px-3 py-2 rounded-md border border-red-300 text-red-700 text-xs font-semibold hover:bg-red-50"
                                        >
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-4 py-6 text-center text-gray-500">No batch cards saved yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
