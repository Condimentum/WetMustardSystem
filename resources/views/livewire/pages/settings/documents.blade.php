<?php

use App\Domains\Reporting\Support\DocumentSetup;
use App\Models\DocumentLayoutSetting;
use App\Models\DocumentReference;
use App\Models\DocumentReferenceChange;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Settings - Documents')] class extends Component {
    private const DEFAULT_MODULES = [
        'Daily Calibrations',
        'Traceability',
        'Pallecons Processing',
        'Lab Testing',
        'Bucketing',
    ];

    public bool $documentModalOpen = false;

    public ?int $editingDocumentId = null;

    public string $code = '';
    public string $title = '';
    public string $version = '';
    public string $issue_date = '';
    public string $module = '';
    public string $issued_by = '';
    public string $reason_for_change = '';

    public string $moduleFilter = '';

    public ?int $selectedDocumentId = null;

    public ?string $flash = null;
    public string $flashLevel = 'success';

    public string $setup_orientation = 'portrait';
    public string $setup_paper_size = 'A4';
    public string $setup_margin_top = '20';
    public string $setup_margin_right = '20';
    public string $setup_margin_bottom = '20';
    public string $setup_margin_left = '20';
    public string $setup_content_padding = '10';
    public string $setup_base_font_size = '11';
    public string $setup_table_header_font_size = '10';
    public string $setup_line_height = '1.25';
    public bool $setup_show_logo = true;
    public bool $setup_show_ccp_block = true;
    public string $setup_ccp_message = '';
    public bool $setup_show_qa_signoff = true;
    public bool $setup_show_issue_history = true;

    /** @var array<int, array<string, mixed>> */
    public array $setup_columns = [];

    public string $setup_available_column_key = '';

    public function mount(): void
    {
        $firstId = DocumentReference::query()->orderBy('code')->value('id');
        $this->selectedDocumentId = $firstId ? (int) $firstId : null;
        $this->loadDocumentSetup(null);
    }

    public function updatedCode($value): void
    {
        if ($this->editingDocumentId !== null) {
            return;
        }

        $this->loadDocumentSetup(null, strtoupper(trim((string) $value)));
    }

    #[Computed]
    public function documents()
    {
        $query = DocumentReference::query()
            ->when($this->moduleFilter !== '', fn ($query) => $query->where('module', $this->moduleFilter));

        if (! Schema::hasTable('document_reference_changes')) {
            return $query
                ->orderBy('code')
                ->get()
                ->each(fn (DocumentReference $document) => $document->setAttribute('changes_count', 0));
        }

        return $query
            ->withCount('changes')
            ->orderByRaw("CASE WHEN module IS NULL OR module = '' THEN 1 ELSE 0 END")
            ->orderBy('module')
            ->orderBy('code')
            ->get();
    }

    #[Computed]
    public function moduleOptions(): array
    {
        $existingModules = DocumentReference::query()
            ->whereNotNull('module')
            ->where('module', '!=', '')
            ->orderBy('module')
            ->pluck('module')
            ->all();

        return collect(array_merge(self::DEFAULT_MODULES, $existingModules))
            ->map(fn ($module) => trim((string) $module))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    #[Computed]
    public function selectedDocumentChanges()
    {
        if ($this->selectedDocumentId === null) {
            return collect();
        }

        if (! Schema::hasTable('document_reference_changes')) {
            return collect();
        }

        return DocumentReferenceChange::query()
            ->where('document_reference_id', $this->selectedDocumentId)
            ->with('changedBy')
            ->orderByDesc('date_issued')
            ->orderByDesc('id')
            ->get();
    }

    public function editDocument(int $id): void
    {
        $documentQuery = DocumentReference::query();
        if ($this->hasDocumentLayoutSettingsTable()) {
            $documentQuery->with('layoutSetting');
        }

        $document = $documentQuery->findOrFail($id);

        $this->editingDocumentId = $document->id;
        $this->code = (string) $document->code;
        $this->title = (string) $document->title;
        $this->version = (string) ($document->version ?? '');
        $this->issue_date = $document->issue_date?->toDateString() ?? '';
        $this->module = (string) ($document->module ?? '');
        $latestChange = Schema::hasTable('document_reference_changes')
            ? DocumentReferenceChange::query()
                ->where('document_reference_id', $document->id)
                ->orderByDesc('date_issued')
                ->orderByDesc('id')
                ->first()
            : null;

        $this->issued_by = Schema::hasTable('document_reference_changes')
            ? (string) ($latestChange?->issued_by ?? '')
            : '';
        $this->reason_for_change = Schema::hasTable('document_reference_changes')
            ? (string) ($latestChange?->reason_for_change ?? '')
            : '';
        $this->selectedDocumentId = $document->id;
        $this->loadDocumentSetup($document);
        $this->documentModalOpen = true;
        unset($this->selectedDocumentChanges);
    }

    public function createDocument(): void
    {
        $this->resetDocumentForm();
        $this->module = self::DEFAULT_MODULES[0];
        $this->loadDocumentSetup(null);
        $this->selectedDocumentId = null;
        $this->documentModalOpen = true;
    }

    public function closeDocumentModal(): void
    {
        $this->documentModalOpen = false;
    }

    public function deleteDocument(): void
    {
        if ($this->editingDocumentId === null) {
            return;
        }

        $document = DocumentReference::query()->find($this->editingDocumentId);
        if (! $document) {
            $this->flashLevel = 'error';
            $this->flash = 'Document no longer exists.';
            $this->documentModalOpen = false;
            $this->resetDocumentForm();
            unset($this->documents, $this->selectedDocumentChanges);

            return;
        }

        $document->delete();

        $this->selectedDocumentId = DocumentReference::query()->orderBy('code')->value('id');
        $this->documentModalOpen = false;
        $this->resetDocumentForm();
        $this->flashLevel = 'success';
        $this->flash = 'Document deleted.';
        unset($this->documents, $this->selectedDocumentChanges);
    }

    public function resetDocumentForm(): void
    {
        $this->editingDocumentId = null;
        $this->code = '';
        $this->title = '';
        $this->version = '';
        $this->issue_date = '';
        $this->module = '';
        $this->issued_by = (string) (auth()->user()?->name ?? '');
        $this->reason_for_change = '';
        $this->loadDocumentSetup(null);
    }

    public function addSetupColumn(): void
    {
        $this->setup_columns[] = [
            'key' => 'new_column',
            'label' => 'New Column',
            'width' => 8,
            'visible' => true,
        ];
    }

    public function clearDocumentSetupDraft(): void
    {
        $document = $this->editingDocumentId !== null
            ? DocumentReference::query()->find($this->editingDocumentId)
            : null;

        $this->loadDocumentSetup($document, strtoupper(trim($this->code)));
    }

    public function addSetupColumnFromAvailable(?string $key = null): void
    {
        $resolvedKey = trim((string) ($key ?? $this->setup_available_column_key));
        if ($resolvedKey === '') {
            return;
        }

        $available = collect($this->availableSetupColumns())->firstWhere('key', $resolvedKey);
        if (! is_array($available)) {
            return;
        }

        $existingIndex = collect($this->setup_columns)->search(
            fn ($column): bool => is_array($column) && trim((string) ($column['key'] ?? '')) === $resolvedKey
        );

        if ($existingIndex !== false) {
            $this->setup_columns[$existingIndex]['visible'] = true;

            return;
        }

        $this->setup_columns[] = [
            'key' => (string) ($available['key'] ?? $resolvedKey),
            'label' => (string) ($available['label'] ?? strtoupper(str_replace('_', ' ', $resolvedKey))),
            'width' => is_numeric($available['width'] ?? null) ? (float) $available['width'] : 8,
            'visible' => true,
        ];
    }

    public function useSuggestedColumns(): void
    {
        $suggested = $this->availableSetupColumns();
        if ($suggested === []) {
            return;
        }

        $this->setup_columns = collect($suggested)
            ->map(fn (array $column): array => [
                'key' => (string) ($column['key'] ?? ''),
                'label' => (string) ($column['label'] ?? ''),
                'width' => is_numeric($column['width'] ?? null) ? (float) $column['width'] : 8,
                'visible' => (bool) ($column['visible'] ?? true),
            ])
            ->values()
            ->all();
    }

    public function removeSetupColumn(int $index): void
    {
        if (! array_key_exists($index, $this->setup_columns)) {
            return;
        }

        unset($this->setup_columns[$index]);
        $this->setup_columns = array_values($this->setup_columns);
    }

    public function resetDocumentSetup(): void
    {
        $document = $this->editingDocumentId !== null
            ? DocumentReference::query()->find($this->editingDocumentId)
            : null;

        $documentCode = $document?->code ?? strtoupper(trim($this->code));
        $defaults = app(DocumentSetup::class)->defaultsForCode($documentCode);
        $this->applySetup($defaults);

        if ($document !== null) {
            if ($this->hasDocumentLayoutSettingsTable()) {
                DocumentLayoutSetting::query()->updateOrCreate(
                    ['document_reference_id' => $document->id],
                    ['settings' => $defaults],
                );
            }

            $this->flashLevel = 'success';
            $this->flash = 'Document setup reset and saved.';
        }
    }

    public function saveDocumentMetadata(): void
    {
        $validated = $this->validate([
            'code' => ['required', 'string', 'max:50'],
            'title' => ['required', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:50'],
            'issue_date' => ['nullable', 'date'],
            'module' => ['nullable', 'string', 'max:255'],
            'issued_by' => ['nullable', 'string', 'max:255'],
            'reason_for_change' => ['nullable', 'string', 'max:1000'],
        ]);

        $normalizedCode = strtoupper(trim((string) $validated['code']));
        $isUpdate = $this->editingDocumentId !== null;
        $existing = $isUpdate ? DocumentReference::query()->find($this->editingDocumentId) : null;

        $normalizedTitle = trim((string) $validated['title']);
        $normalizedVersion = trim((string) ($validated['version'] ?? '')) ?: null;
        $normalizedIssueDate = trim((string) ($validated['issue_date'] ?? '')) ?: null;
        $normalizedModule = trim((string) ($validated['module'] ?? '')) ?: null;
        $enteredReason = trim((string) ($validated['reason_for_change'] ?? ''));

        $latestReason = '';
        if ($isUpdate && $existing !== null && Schema::hasTable('document_reference_changes')) {
            $latestReason = trim((string) (DocumentReferenceChange::query()
                ->where('document_reference_id', $existing->id)
                ->orderByDesc('date_issued')
                ->orderByDesc('id')
                ->value('reason_for_change') ?? ''));
        }

        $reasonChanged = $enteredReason !== '' && $enteredReason !== $latestReason;

        $metadataChanged = ! $isUpdate || $existing === null
            || (string) $existing->code !== $normalizedCode
            || (string) $existing->title !== $normalizedTitle
            || (string) ($existing->version ?? '') !== (string) ($normalizedVersion ?? '')
            || ($existing->issue_date?->toDateString() ?? null) !== $normalizedIssueDate
            || (string) ($existing->module ?? '') !== (string) ($normalizedModule ?? '')
            || $reasonChanged;

        $duplicateExists = DocumentReference::query()
            ->where('code', $normalizedCode)
            ->when($this->editingDocumentId, fn ($query) => $query->where('id', '!=', $this->editingDocumentId))
            ->exists();

        if ($duplicateExists) {
            $this->addError('code', 'Document code already exists.');

            return;
        }

        $document = DocumentReference::query()->updateOrCreate(
            ['id' => $this->editingDocumentId],
            [
                'code' => $normalizedCode,
                'title' => $normalizedTitle,
                'version' => $normalizedVersion,
                'issue_date' => $normalizedIssueDate,
                'module' => $normalizedModule,
                'status' => 'Active',
            ],
        );

        if ($this->hasDocumentLayoutSettingsTable()) {
            DocumentLayoutSetting::query()->updateOrCreate(
                ['document_reference_id' => (int) $document->id],
                ['settings' => $this->buildSetupPayload($document->code)],
            );
        }

        if ($metadataChanged && Schema::hasTable('document_reference_changes')) {
            $newVersion = trim((string) ($document->version ?? ''));
            $oldVersion = trim((string) ($existing?->version ?? ''));
            $didVersionChange = $oldVersion !== $newVersion;

            $changeReason = match (true) {
                ! $isUpdate => 'Initial issue.',
                $didVersionChange && $oldVersion !== '' && $newVersion !== '' => 'Superseded version '.$oldVersion.' with version '.$newVersion.'.',
                $didVersionChange && $newVersion !== '' => 'Issued version '.$newVersion.'.',
                default => 'Metadata updated.',
            };

            if ($enteredReason !== '') {
                $changeReason = $enteredReason;
            }

            DocumentReferenceChange::query()->create([
                'document_reference_id' => (int) $document->id,
                'issue_version' => $newVersion !== '' ? $newVersion : null,
                'date_issued' => $document->issue_date?->toDateString() ?? now()->toDateString(),
                'issued_by' => trim((string) ($validated['issued_by'] ?? '')) ?: auth()->user()?->name,
                'reason_for_change' => $changeReason,
                'changed_by_user_id' => auth()->id(),
            ]);
        }

        $this->editingDocumentId = (int) $document->id;
        $this->selectedDocumentId = (int) $document->id;
        $this->code = (string) $document->code;
        $this->title = (string) $document->title;
        $this->version = (string) ($document->version ?? '');
        $this->issue_date = $document->issue_date?->toDateString() ?? '';
        $this->module = (string) ($document->module ?? '');
        $this->loadDocumentSetup($document);

        if (! $isUpdate) {
            $this->flashLevel = 'success';
            $this->flash = 'Document created. History entry recorded.';
        } elseif ($metadataChanged) {
            $this->flashLevel = 'success';
            $this->flash = 'Document metadata updated. History entry recorded.';
        } else {
            $this->flash = null;
        }

        $this->documentModalOpen = false;
        unset($this->documents, $this->selectedDocumentChanges);
    }

    public function saveDocumentSetup(): void
    {
        $this->resetErrorBag('setup_columns');

        $visibleWidthTotal = $this->setupVisibleWidthTotal();
        if ($visibleWidthTotal > 100) {
            $this->addError(
                'setup_columns',
                'Visible column widths total '.number_format($visibleWidthTotal, 2).'% and cannot exceed 100%.'
            );

            return;
        }

        $this->saveDocumentMetadata();
    }

    #[Computed]
    public function setupPreviewColumns(): array
    {
        return collect($this->setup_columns)
            ->filter(fn ($column): bool => is_array($column) && (bool) ($column['visible'] ?? false))
            ->map(function ($column): array {
                $label = trim((string) ($column['label'] ?? ''));
                $key = trim((string) ($column['key'] ?? ''));

                return [
                    'key' => $key,
                    'label' => $label !== '' ? $label : strtoupper(str_replace('_', ' ', $key)),
                    'width' => is_numeric($column['width'] ?? null) ? (float) $column['width'] : 8,
                ];
            })
            ->values()
            ->all();
    }

    #[Computed]
    public function setupVisibleWidthTotal(): float
    {
        return round((float) collect($this->setup_columns)
            ->filter(fn ($column): bool => is_array($column) && (bool) ($column['visible'] ?? false))
            ->sum(function ($column): float {
                $width = $column['width'] ?? 0;

                return is_numeric($width) ? (float) $width : 0;
            }), 2);
    }

    #[Computed]
    public function availableSetupColumns(): array
    {
        $documentCode = strtoupper(trim($this->code));
        $setupService = app(DocumentSetup::class);
        $defaults = $setupService->defaultsForCode($documentCode);

        if (! is_array($defaults['columns'] ?? null) || $defaults['columns'] === []) {
            $context = strtolower(trim($this->title.' '.$this->module));

            if (str_contains($context, 'pallecon')) {
                $defaults = $setupService->defaultsForCode('WM004');
            } elseif (str_contains($context, 'traceability')) {
                $defaults = $setupService->defaultsForCode('WM003');
            } elseif (str_contains($context, 'lab')) {
                $defaults = $setupService->defaultsForCode('WM005');
            } elseif (str_contains($context, 'rinse')) {
                $defaults = $setupService->defaultsForCode('WM010');
            }
        }

        return collect(is_array($defaults['columns'] ?? null) ? $defaults['columns'] : [])
            ->map(function ($column): array {
                return [
                    'key' => trim((string) ($column['key'] ?? '')),
                    'label' => trim((string) ($column['label'] ?? '')),
                    'width' => is_numeric($column['width'] ?? null) ? (float) $column['width'] : 8,
                ];
            })
            ->filter(fn (array $column): bool => $column['key'] !== '')
            ->values()
            ->all();
    }

    public function sampleColumnValue(string $columnKey): string
    {
        return match ($columnKey) {
            'tested_date', 'date_used', 'supplier_production_date', 'best_before_date' => '2026-08-12',
            'tested_time', 'time_on' => '06:30',
            'mo_number' => '1224',
            'batch_number', 'batch_no' => 'hqwidj',
            'ph' => '23.000',
            'acidity_acetic' => '32.000',
            'acidity_citric' => '21.000',
            'salt' => '23.000',
            'viscosity_brookfield' => '41.000',
            'viscosity_bostwick' => '12.000',
            'aw' => '12.000',
            'solids' => '123.000',
            'appearance' => 'Pass',
            'tested_by', 'operator_name' => 'Adrian Lacki',
            'ticket_number' => 'TK-10092',
            'serial_number' => 'S0400332',
            'top_seal_number' => 'TS8821',
            'bottom_seal_number' => 'BS9912',
            'liner_number' => 'LN-22',
            'liner_batch_code' => 'LBC-722A',
            'fill_weight' => '1000.000',
            'start_time' => '07:00',
            'finish_time' => '07:55',
            'checked_by' => 'Operator QA',
            'checked_at' => '2026-08-12 08:00',
            default => 'Sample',
        };
    }

    private function loadDocumentSetup(?DocumentReference $document, ?string $overrideCode = null): void
    {
        $resolved = app(DocumentSetup::class)->resolveForDocument($document, $overrideCode);
        $this->applySetup($resolved);
    }

    /** @param array<string, mixed> $setup */
    private function applySetup(array $setup): void
    {
        $this->setup_orientation = (string) ($setup['orientation'] ?? 'portrait');
        $this->setup_paper_size = (string) ($setup['paper_size'] ?? 'A4');
        $this->setup_margin_top = (string) ($setup['margin_top'] ?? 20);
        $this->setup_margin_right = (string) ($setup['margin_right'] ?? 20);
        $this->setup_margin_bottom = (string) ($setup['margin_bottom'] ?? 20);
        $this->setup_margin_left = (string) ($setup['margin_left'] ?? 20);
        $this->setup_content_padding = (string) ($setup['content_padding'] ?? 10);
        $this->setup_base_font_size = (string) ($setup['base_font_size'] ?? 11);
        $this->setup_table_header_font_size = (string) ($setup['table_header_font_size'] ?? 10);
        $this->setup_line_height = (string) ($setup['line_height'] ?? 1.25);
        $this->setup_show_logo = (bool) ($setup['show_logo'] ?? true);
        $this->setup_show_ccp_block = (bool) ($setup['show_ccp_block'] ?? true);
        $this->setup_ccp_message = (string) ($setup['ccp_message'] ?? '');
        $this->setup_show_qa_signoff = (bool) ($setup['show_qa_signoff'] ?? true);
        $this->setup_show_issue_history = (bool) ($setup['show_issue_history'] ?? true);
        $this->setup_columns = collect(is_array($setup['columns'] ?? null) ? $setup['columns'] : [])
            ->map(function ($column): array {
                return [
                    'key' => trim((string) ($column['key'] ?? '')),
                    'label' => trim((string) ($column['label'] ?? '')),
                    'width' => is_numeric($column['width'] ?? null) ? (float) $column['width'] : 8,
                    'visible' => (bool) ($column['visible'] ?? false),
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function buildSetupPayload(?string $documentCode = null): array
    {
        $raw = [
            'orientation' => $this->setup_orientation,
            'paper_size' => $this->setup_paper_size,
            'margin_top' => $this->setup_margin_top,
            'margin_right' => $this->setup_margin_right,
            'margin_bottom' => $this->setup_margin_bottom,
            'margin_left' => $this->setup_margin_left,
            'content_padding' => $this->setup_content_padding,
            'base_font_size' => $this->setup_base_font_size,
            'table_header_font_size' => $this->setup_table_header_font_size,
            'line_height' => $this->setup_line_height,
            'show_logo' => $this->setup_show_logo,
            'show_ccp_block' => $this->setup_show_ccp_block,
            'ccp_message' => $this->setup_ccp_message,
            'show_qa_signoff' => $this->setup_show_qa_signoff,
            'show_issue_history' => $this->setup_show_issue_history,
            'columns' => $this->setup_columns,
        ];

        return app(DocumentSetup::class)->normalize($documentCode ?? strtoupper(trim($this->code)), $raw);
    }

    private function hasDocumentLayoutSettingsTable(): bool
    {
        try {
            return Schema::hasTable('document_layout_settings');
        } catch (\Throwable) {
            return false;
        }
    }
}; ?>

<div class="py-8">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <h2 class="text-xl font-semibold text-gray-800">Documents</h2>
        <p class="text-sm text-gray-600">Manage controlled document metadata and issue/change history used in generated paperwork.</p>

        <div class="rounded-lg border border-slate-200 bg-white p-2 shadow-sm">
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('settings.admin') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.admin') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">General</a>
                <a href="{{ route('settings.recipes') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.recipes') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Recipes</a>
                <a href="{{ route('settings.product-mapping') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.product-mapping') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Product Mapping</a>
                <a href="{{ route('settings.operator-sync') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.operator-sync') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Operator Sync</a>
                <a href="{{ route('settings.documents') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.documents') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Documents</a>
                <a href="{{ route('reporting.admin') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('reporting.*') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Reporting</a>
                <a href="{{ route('notifications.admin') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('notifications.*') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Notifications</a>
                <a href="{{ route('audit.index') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('audit.*') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Audit</a>
            </div>
        </div>

        @if ($flash)
            <div @class([
                'text-sm rounded-lg px-4 py-3 border',
                'bg-green-50 border-green-200 text-green-800' => $flashLevel === 'success',
                'bg-red-50 border-red-200 text-red-800' => $flashLevel === 'error',
            ])>{{ $flash }}</div>
        @endif

        <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-2">
                <div class="font-medium text-gray-800">Document List</div>
                <div class="flex items-center gap-2">
                    <select wire:model.live="moduleFilter" class="rounded-md border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500">
                        <option value="">All categories</option>
                        @foreach ($this->moduleOptions as $moduleOption)
                            <option value="{{ $moduleOption }}">{{ $moduleOption }}</option>
                        @endforeach
                    </select>
                    <x-primary-button type="button" wire:click="createDocument">New document</x-primary-button>
                </div>
            </div>
            <div class="max-h-[560px] overflow-y-auto divide-y divide-gray-100">
                @php
                    $currentModuleHeader = null;
                @endphp
                @forelse ($this->documents as $document)
                    @php
                        $moduleHeader = trim((string) ($document->module ?? '')) !== ''
                            ? (string) $document->module
                            : 'Uncategorised';
                    @endphp

                    @if ($moduleHeader !== $currentModuleHeader)
                        <div class="sticky top-0 z-10 bg-slate-50/95 backdrop-blur px-5 py-2 text-xs font-semibold uppercase tracking-wide text-slate-600 border-y border-gray-100">
                            {{ $moduleHeader }}
                        </div>
                        @php
                            $currentModuleHeader = $moduleHeader;
                        @endphp
                    @endif

                    <button type="button" wire:click="editDocument({{ $document->id }})" class="w-full text-left px-5 py-3 hover:bg-gray-50">
                        <div class="flex items-center justify-between gap-2">
                            <div>
                                <div class="font-medium text-gray-800">{{ $document->code }} - {{ $document->title }}</div>
                                <div class="text-xs text-gray-500">{{ $document->module ?: 'No module' }} | Version {{ $document->version ?: 'N/A' }} | {{ $document->status ?: 'Unknown' }}</div>
                            </div>
                            <span class="text-xs text-gray-500">{{ $document->changes_count }} change{{ $document->changes_count === 1 ? '' : 's' }}</span>
                        </div>
                    </button>
                @empty
                    <div class="px-5 py-6 text-sm text-gray-500">No documents created yet.</div>
                @endforelse
            </div>
        </div>

        @if ($documentModalOpen)
            <div class="fixed inset-0 z-50 overflow-y-auto p-3 sm:p-6">
                <div class="absolute inset-0 bg-black/40" wire:click="closeDocumentModal"></div>
                <div class="relative mx-auto my-2 sm:my-4 w-full max-w-5xl max-h-[calc(100vh-1.5rem)] sm:max-h-[calc(100vh-3rem)] rounded-xl bg-white border border-gray-200 shadow-2xl flex flex-col overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between shrink-0">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">{{ $editingDocumentId ? 'Edit document' : 'New document' }}</h3>
                            <p class="text-xs text-gray-500">Manage metadata and document issue history in one place.</p>
                        </div>
                        <button type="button" wire:click="closeDocumentModal" class="text-sm text-gray-500 hover:text-gray-700">Close</button>
                    </div>

                    <div class="p-6 space-y-6 overflow-y-auto min-h-0">
                        <div class="bg-white rounded-lg border border-gray-200 p-5 space-y-4">
                            <div class="font-medium text-gray-800">Document Metadata</div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs text-gray-600 mb-1">Document code</label>
                                    <input wire:model.defer="code" class="w-full border-gray-300 rounded-md shadow-sm text-sm" placeholder="MINT005" />
                                    @error('code') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-600 mb-1">Version</label>
                                    <input wire:model.defer="version" class="w-full border-gray-300 rounded-md shadow-sm text-sm" placeholder="5" />
                                    @error('version') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-xs text-gray-600 mb-1">Title</label>
                                    <input wire:model.defer="title" class="w-full border-gray-300 rounded-md shadow-sm text-sm" placeholder="Metal detector verification sheet" />
                                    @error('title') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-600 mb-1">Issue date</label>
                                    <input type="date" wire:model.defer="issue_date" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
                                    @error('issue_date') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-600 mb-1">Issued by</label>
                                    <input wire:model.defer="issued_by" class="w-full border-gray-300 rounded-md shadow-sm text-sm" placeholder="T Boyce" />
                                    @error('issued_by') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-xs text-gray-600 mb-1">Reason for change</label>
                                    <textarea wire:model.defer="reason_for_change" rows="3" class="w-full border-gray-300 rounded-md shadow-sm text-sm" placeholder="Describe what changed in this revision..."></textarea>
                                    @error('reason_for_change') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-xs text-gray-600 mb-1">Category / module</label>
                                    <select wire:model.defer="module" class="w-full border-gray-300 rounded-md shadow-sm text-sm focus:border-sky-500 focus:ring-sky-500">
                                        <option value="">- uncategorised -</option>
                                        @foreach ($this->moduleOptions as $moduleOption)
                                            <option value="{{ $moduleOption }}">{{ $moduleOption }}</option>
                                        @endforeach
                                    </select>
                                    @error('module') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                </div>
                            </div>

                            <div class="flex items-center justify-end gap-2">
                                @if ($editingDocumentId)
                                    <button
                                        type="button"
                                        wire:click="deleteDocument"
                                        wire:confirm="Delete this document and its history entries? This cannot be undone."
                                        class="inline-flex items-center rounded-md border border-red-300 px-3 py-2 text-sm font-medium text-red-700 transition hover:bg-red-50"
                                    >
                                        Delete document
                                    </button>
                                @endif
                                <x-secondary-button type="button" wire:click="resetDocumentForm">Clear</x-secondary-button>
                                <x-primary-button type="button" wire:click="saveDocumentMetadata">{{ $editingDocumentId ? 'Update document' : 'Create document' }}</x-primary-button>
                            </div>
                        </div>

                        <div class="bg-white rounded-lg border border-gray-200 p-5 space-y-4">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <div class="font-medium text-gray-800">Document Setup</div>
                                    <p class="text-xs text-gray-500">Per-document PDF settings used by preview and generated paperwork.</p>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                                <div>
                                    <label class="block text-xs text-gray-600 mb-1">Orientation</label>
                                    <select wire:model.defer="setup_orientation" class="w-full border-gray-300 rounded-md shadow-sm text-sm">
                                        <option value="portrait">Portrait</option>
                                        <option value="landscape">Landscape</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-600 mb-1">Paper size</label>
                                    <select wire:model.defer="setup_paper_size" class="w-full border-gray-300 rounded-md shadow-sm text-sm">
                                        <option value="A4">A4</option>
                                        <option value="LETTER">Letter</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-600 mb-1">Base font size</label>
                                    <input type="number" min="7" max="20" step="0.5" wire:model.defer="setup_base_font_size" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-600 mb-1">Header font size</label>
                                    <input type="number" min="7" max="20" step="0.5" wire:model.defer="setup_table_header_font_size" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
                                <div>
                                    <label class="block text-xs text-gray-600 mb-1">Margin top</label>
                                    <input type="number" min="0" max="100" step="1" wire:model.defer="setup_margin_top" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-600 mb-1">Margin right</label>
                                    <input type="number" min="0" max="100" step="1" wire:model.defer="setup_margin_right" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-600 mb-1">Margin bottom</label>
                                    <input type="number" min="0" max="150" step="1" wire:model.defer="setup_margin_bottom" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-600 mb-1">Margin left</label>
                                    <input type="number" min="0" max="100" step="1" wire:model.defer="setup_margin_left" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-600 mb-1">Line height</label>
                                    <input type="number" min="1" max="2" step="0.05" wire:model.defer="setup_line_height" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model.defer="setup_show_logo" class="rounded border-gray-300 text-sky-600"> Show logo</label>
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model.defer="setup_show_ccp_block" class="rounded border-gray-300 text-sky-600"> Show CCP block</label>
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model.defer="setup_show_qa_signoff" class="rounded border-gray-300 text-sky-600"> Show QA signoff</label>
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model.defer="setup_show_issue_history" class="rounded border-gray-300 text-sky-600"> Show document metadata</label>
                            </div>

                            <div>
                                <label class="block text-xs text-gray-600 mb-1">CCP / instruction message (one line per row)</label>
                                <textarea wire:model.defer="setup_ccp_message" rows="5" class="w-full border-gray-300 rounded-md shadow-sm text-sm" placeholder="Enter instruction lines shown in the CCP block..."></textarea>
                            </div>

                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <div class="font-medium text-sm text-gray-800">Columns</div>
                                    <div class="text-xs text-gray-600">Visible total: {{ number_format($this->setupVisibleWidthTotal, 2) }}%</div>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_auto] gap-2">
                                    <div>
                                        <label class="block text-xs text-gray-600 mb-1">Add field from corresponding form</label>
                                        <select wire:model="setup_available_column_key" class="w-full border-gray-300 rounded-md shadow-sm text-sm">
                                            <option value="">Select a field...</option>
                                            @foreach ($this->availableSetupColumns as $availableColumn)
                                                <option value="{{ $availableColumn['key'] }}">{{ $availableColumn['label'] }} ({{ $availableColumn['key'] }})</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="self-end">
                                        <button type="button" wire:click="addSetupColumnFromAvailable" class="inline-flex items-center rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">Add selected field</button>
                                    </div>
                                </div>

                                @if ($this->availableSetupColumns === [])
                                    <div class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2">
                                        No linked form field map exists yet for this document code. You can still add columns manually, or I can wire this code to a specific form.
                                    </div>
                                @endif

                                @if ($this->setupVisibleWidthTotal > 100)
                                    <div class="text-xs text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2">
                                        Visible column widths are above 100%. Reduce widths before saving.
                                    </div>
                                @endif
                                @error('setup_columns')
                                    <div class="text-xs text-red-700">{{ $message }}</div>
                                @enderror

                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200 text-xs">
                                        <thead class="bg-gray-50 text-gray-500 uppercase">
                                            <tr>
                                                <th class="px-2 py-2 text-left">Show</th>
                                                <th class="px-2 py-2 text-left">Key</th>
                                                <th class="px-2 py-2 text-left">Label</th>
                                                <th class="px-2 py-2 text-left">Width %</th>
                                                <th class="px-2 py-2 text-left">Running %</th>
                                                <th class="px-2 py-2 text-left"></th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            @php
                                                $runningVisibleWidth = 0;
                                            @endphp
                                            @forelse ($setup_columns as $index => $column)
                                                @php
                                                    $currentWidth = is_numeric($column['width'] ?? null) ? (float) $column['width'] : 0;
                                                    $isVisible = (bool) ($column['visible'] ?? false);
                                                    $runningVisibleWidth += $isVisible ? $currentWidth : 0;
                                                @endphp
                                                <tr>
                                                    <td class="px-2 py-2"><input type="checkbox" wire:model.defer="setup_columns.{{ $index }}.visible" class="rounded border-gray-300 text-sky-600"></td>
                                                    <td class="px-2 py-2"><input wire:model.defer="setup_columns.{{ $index }}.key" class="w-full border-gray-300 rounded-md text-xs" placeholder="field_key" /></td>
                                                    <td class="px-2 py-2"><input wire:model.defer="setup_columns.{{ $index }}.label" class="w-full border-gray-300 rounded-md text-xs" placeholder="Column label" /></td>
                                                    <td class="px-2 py-2"><input type="number" min="1" max="100" step="0.5" wire:model.defer="setup_columns.{{ $index }}.width" class="w-24 border-gray-300 rounded-md text-xs" /></td>
                                                    <td class="px-2 py-2 {{ $runningVisibleWidth > 100 ? 'text-red-700 font-semibold' : 'text-gray-600' }}">{{ number_format($runningVisibleWidth, 2) }}%</td>
                                                    <td class="px-2 py-2">
                                                        <button type="button" wire:click="removeSetupColumn({{ $index }})" class="text-red-600 hover:text-red-700">Remove</button>
                                                    </td>
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="6" class="px-2 py-3 text-gray-500">No columns configured yet for this document.</td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>

                                <div class="text-xs rounded-md border px-3 py-2 {{ $this->setupVisibleWidthTotal > 100 ? 'border-red-200 bg-red-50 text-red-700' : 'border-slate-200 bg-slate-50 text-slate-700' }}">
                                    Visible total: {{ number_format($this->setupVisibleWidthTotal, 2) }}%
                                    |
                                    Remaining: {{ number_format(100 - $this->setupVisibleWidthTotal, 2) }}%
                                </div>
                            </div>

                            <div class="flex items-center justify-end gap-2">
                                <x-secondary-button type="button" wire:click="clearDocumentSetupDraft">Clear</x-secondary-button>
                                <x-primary-button type="button" wire:click="saveDocumentSetup">Save changes</x-primary-button>
                            </div>
                        </div>

                        <div class="bg-white rounded-lg border border-gray-200 p-5 space-y-4">
                            <div class="font-medium text-gray-800">Document Change History</div>

                            @if (! $selectedDocumentId)
                                <div class="text-sm text-gray-500">Save the document metadata first. History entries are recorded automatically on each save.</div>
                            @else
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                                        <thead class="bg-gray-50 text-left text-xs text-gray-500 uppercase">
                                            <tr>
                                                <th class="px-3 py-2">Issue Version</th>
                                                <th class="px-3 py-2">Date Issued</th>
                                                <th class="px-3 py-2">Issued By</th>
                                                <th class="px-3 py-2">Reason for Change</th>
                                                <th class="px-3 py-2">Recorded By</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            @forelse ($this->selectedDocumentChanges as $change)
                                                <tr>
                                                    <td class="px-3 py-2">{{ $change->issue_version ?: '—' }}</td>
                                                    <td class="px-3 py-2">{{ $change->date_issued?->format('Y-m-d') ?: '—' }}</td>
                                                    <td class="px-3 py-2">{{ $change->issued_by ?: '—' }}</td>
                                                    <td class="px-3 py-2">{{ $change->reason_for_change ?: '—' }}</td>
                                                    <td class="px-3 py-2">{{ $change->changedBy?->name ?: '—' }}</td>
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="5" class="px-3 py-5 text-center text-gray-500">No change history recorded for this document yet.</td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
