<?php

use App\Domains\Batch\Jobs\ExtractBatchCardMetadataJob;
use App\Domains\Reporting\Support\DocumentSetup;
use App\Domains\Reporting\Support\DocumentSources;
use App\Models\DocumentLayoutSetting;
use App\Models\DocumentReference;
use App\Models\DocumentReferenceChange;
use App\Models\ReportConfig;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Settings - Documents')] class extends Component {
    use WithFileUploads;
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
    public string $trigger_material_codes = '';

    /** "program", "material_trigger" or "none" - see DocumentSources. */
    public string $source_type = DocumentSources::TYPE_NONE;
    public string $program_key = '';

    public string $moduleFilter = '';

    public ?int $selectedDocumentId = null;

    public ?TemporaryUploadedFile $pdfUpload = null;

    public ?string $extractInfo = null;

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
        $this->loadDocumentLink(null);
        $this->loadDocumentSetup(null);
    }

    public function updatedCode($value): void
    {
        if ($this->editingDocumentId !== null) {
            return;
        }

        $code = strtoupper(trim((string) $value));
        $programKey = app(DocumentSources::class)->programKeyForCode($code);
        if ($programKey !== null && $this->source_type !== DocumentSources::TYPE_MATERIAL_TRIGGER) {
            $this->source_type = DocumentSources::TYPE_PROGRAM;
            $this->program_key = $programKey;
        }

        $this->loadDocumentSetup(null, $code);
    }

    public function updatedSourceType(): void
    {
        if ($this->source_type === DocumentSources::TYPE_PROGRAM && $this->program_key === '') {
            $this->program_key = (string) (app(DocumentSources::class)->programKeyForCode($this->code) ?? '');
        }

        $this->fillColumnsFromSourceWhenEmpty();
    }

    public function updatedProgramKey(): void
    {
        $this->fillColumnsFromSourceWhenEmpty();
    }

    #[Computed]
    public function programOptions(): array
    {
        return app(DocumentSources::class)->programs();
    }

    private function currentSourceKey(): ?string
    {
        return app(DocumentSources::class)->sourceKey($this->source_type, $this->program_key);
    }

    private function fillColumnsFromSourceWhenEmpty(): void
    {
        $hasColumns = collect($this->setup_columns)->contains(fn ($column): bool => is_array($column) && trim((string) ($column['key'] ?? '')) !== '');
        if (! $hasColumns) {
            $this->setup_columns = app(DocumentSources::class)->defaultColumns($this->currentSourceKey());
        }
    }

    private function loadDocumentLink(?DocumentReference $document, ?string $fallbackCode = null): void
    {
        $link = app(DocumentSources::class)->linkFor($document, $fallbackCode);
        $this->source_type = $link['type'] ?? DocumentSources::TYPE_NONE;
        $this->program_key = (string) ($link['program_key'] ?? '');
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
        $this->trigger_material_codes = implode(', ', (array) ($document->trigger_material_codes ?? []));
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
        $this->loadDocumentLink($document);
        $this->loadDocumentSetup($document);
        $this->documentModalOpen = true;
        unset($this->selectedDocumentChanges);
    }

    public function createDocument(): void
    {
        $this->resetDocumentForm();
        $this->module = self::DEFAULT_MODULES[0];
        $this->loadDocumentLink(null);
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
        ReportConfig::query()->where('report_key', 'doc_'.$document->code)->delete();

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
        $this->trigger_material_codes = '';
        $this->issued_by = (string) (auth()->user()?->name ?? '');
        $this->reason_for_change = '';
        $this->pdfUpload = null;
        $this->extractInfo = null;
        $this->loadDocumentLink(null);
        $this->loadDocumentSetup(null);
    }

    public function updatedPdfUpload(): void
    {
        $this->extractInfo = null;

        if (! $this->pdfUpload instanceof TemporaryUploadedFile) {
            return;
        }

        try {
            $this->validate(['pdfUpload' => ['file', 'mimes:pdf', 'max:15360']]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->pdfUpload = null;

            throw $e;
        }

        $tempPath = $this->pdfUpload->getRealPath();
        if (! is_string($tempPath) || trim($tempPath) === '') {
            $this->extractInfo = 'PDF selected, but could not be read for extraction - enter the details manually.';

            return;
        }

        $result = app(ExtractBatchCardMetadataJob::class)($tempPath);
        $metadata = (array) ($result['metadata'] ?? []);

        if (($metadata['document_code'] ?? null) && $this->code === '') {
            $this->code = (string) $metadata['document_code'];
        }

        if (($metadata['revision'] ?? null) && $this->version === '') {
            $this->version = (string) $metadata['revision'];
        }

        if (($metadata['title'] ?? null) && $this->title === '') {
            $this->title = Str::limit((string) $metadata['title'], 255, '');
        }

        if (($metadata['issue_date'] ?? null) && $this->issue_date === '') {
            $this->issue_date = (string) $metadata['issue_date'];
        }

        if (($metadata['reason_for_issue'] ?? null) && $this->reason_for_change === '') {
            $this->reason_for_change = (string) $metadata['reason_for_issue'];
        }

        if (($metadata['extract_error'] ?? null) !== null) {
            $this->extractInfo = 'PDF uploaded, but text extraction failed - enter the details manually.';

            return;
        }

        $this->extractInfo = 'PDF scanned - review the pre-filled fields below before saving.';
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

    public function addAllSetupColumns(): void
    {
        $existingKeys = collect($this->setup_columns)
            ->map(fn ($column): string => is_array($column) ? trim((string) ($column['key'] ?? '')) : '')
            ->all();

        foreach ($this->availableSetupColumns() as $available) {
            $existingIndex = array_search($available['key'], $existingKeys, true);

            if ($existingIndex !== false) {
                $this->setup_columns[$existingIndex]['visible'] = true;

                continue;
            }

            $this->setup_columns[] = [
                'key' => $available['key'],
                'label' => $available['label'] !== '' ? $available['label'] : strtoupper(str_replace('_', ' ', $available['key'])),
                'width' => $available['width'],
                'visible' => true,
            ];
            $existingKeys[] = $available['key'];
        }
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
        $defaults = app(DocumentSetup::class)->defaultsForCode($documentCode, $this->currentSourceKey());
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
            'source_type' => ['required', 'in:'.DocumentSources::TYPE_PROGRAM.','.DocumentSources::TYPE_MATERIAL_TRIGGER.','.DocumentSources::TYPE_NONE],
            'program_key' => ['nullable', 'required_if:source_type,'.DocumentSources::TYPE_PROGRAM, 'in:'.implode(',', array_keys($this->programOptions))],
            'trigger_material_codes' => ['nullable', 'required_if:source_type,'.DocumentSources::TYPE_MATERIAL_TRIGGER, 'string', 'max:1000'],
        ], [
            'program_key.required_if' => 'Choose the program this document is filled from.',
            'trigger_material_codes.required_if' => 'Enter at least one trigger material code.',
        ]);

        $isMaterialTriggered = $validated['source_type'] === DocumentSources::TYPE_MATERIAL_TRIGGER;
        $normalizedCode = strtoupper(trim((string) $validated['code']));
        $isUpdate = $this->editingDocumentId !== null;
        $existing = $isUpdate ? DocumentReference::query()->find($this->editingDocumentId) : null;

        $normalizedTitle = trim((string) $validated['title']);
        $normalizedVersion = trim((string) ($validated['version'] ?? '')) ?: null;
        $normalizedIssueDate = trim((string) ($validated['issue_date'] ?? '')) ?: null;
        $normalizedModule = trim((string) ($validated['module'] ?? '')) ?: null;
        $enteredReason = trim((string) ($validated['reason_for_change'] ?? ''));
        $triggerCodes = ! $isMaterialTriggered ? [] : collect(explode(',', (string) ($validated['trigger_material_codes'] ?? '')))
            ->map(fn ($code) => trim((string) $code))
            ->filter()
            ->unique()
            ->values()
            ->all();

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

        $attributes = [
            'code' => $normalizedCode,
            'title' => $normalizedTitle,
            'version' => $normalizedVersion,
            'issue_date' => $normalizedIssueDate,
            'module' => $normalizedModule,
            'trigger_material_codes' => $triggerCodes !== [] ? $triggerCodes : null,
            'status' => 'Active',
        ];

        if (app(DocumentSources::class)->hasSourceColumns()) {
            $attributes['source_type'] = $validated['source_type'];
            $attributes['program_key'] = $validated['source_type'] === DocumentSources::TYPE_PROGRAM ? $validated['program_key'] : null;
        }

        $document = DocumentReference::query()->updateOrCreate(['id' => $this->editingDocumentId], $attributes);

        if ($isUpdate && $existing !== null && $existing->code !== $normalizedCode) {
            ReportConfig::query()->where('report_key', 'doc_'.$existing->code)->delete();
        }

        if ($triggerCodes !== []) {
            ReportConfig::query()->updateOrCreate(
                ['report_key' => 'doc_'.$normalizedCode],
                [
                    'report_name' => $normalizedCode.' - '.$normalizedTitle,
                    'report_type' => 'scheduled',
                    'schedule_time' => '06:00',
                    'date_offset_from_days' => -1,
                    'date_offset_to_days' => -1,
                    'enabled' => true,
                    'updated_by' => auth()->id(),
                ],
            );
        } elseif ($validated['source_type'] === DocumentSources::TYPE_PROGRAM && app(DocumentSources::class)->isTableBacked($validated['program_key'])) {
            // Created disabled (and never re-enabled here) so it can't duplicate an existing emailed report;
            // switch it on and pick recipients in Reporting Admin.
            $scheduledReport = ReportConfig::query()->firstOrCreate(
                ['report_key' => 'doc_'.$normalizedCode],
                [
                    'report_name' => $normalizedCode.' - '.$normalizedTitle,
                    'report_type' => 'scheduled',
                    'schedule_time' => '06:00',
                    'date_offset_from_days' => -1,
                    'date_offset_to_days' => -1,
                    'enabled' => false,
                ],
            );
            $scheduledReport->update(['report_name' => $normalizedCode.' - '.$normalizedTitle, 'updated_by' => auth()->id()]);
            $scheduledReportCreated = $scheduledReport->wasRecentlyCreated;
        } else {
            ReportConfig::query()->where('report_key', 'doc_'.$normalizedCode)->delete();
        }

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
        $this->trigger_material_codes = implode(', ', (array) ($document->trigger_material_codes ?? []));
        $this->loadDocumentLink($document);
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

        if ($scheduledReportCreated ?? false) {
            $this->flashLevel = 'success';
            $this->flash = trim(($this->flash ?? 'Document saved.').' A scheduled report "doc_'.$normalizedCode.'" was added in Reporting Admin - it is off until you enable it.');
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
        return collect(app(DocumentSources::class)->defaultColumns($this->currentSourceKey()))
            ->map(fn (array $column): array => [
                'key' => $column['key'],
                'label' => $column['label'],
                'width' => $column['width'],
            ])
            ->all();
    }

    #[Computed]
    public function linkedProgramHasConfigurablePdf(): bool
    {
        if ($this->source_type === DocumentSources::TYPE_MATERIAL_TRIGGER) {
            return true;
        }

        return (bool) ($this->programOptions[$this->program_key]['configurable_pdf'] ?? false);
    }

    private function loadDocumentSetup(?DocumentReference $document, ?string $overrideCode = null): void
    {
        $resolved = app(DocumentSetup::class)->resolveForDocument($document, $overrideCode, $this->currentSourceKey());
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

        return app(DocumentSetup::class)->normalize($documentCode ?? strtoupper(trim($this->code)), $raw, $this->currentSourceKey());
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
    <x-mo-workspace-styles />
    <div class="wm-page max-w-7xl mx-auto space-y-6">
        <section class="wm-card wm-card--gear-tr">
            <h1 class="wm-title">Documents</h1>
            <p class="wm-sub" style="margin-top:4px;">Manage controlled document metadata and issue/change history used in generated paperwork.</p>

            <x-settings-subnav />
        </section>

        @if ($flash)
            <div @class([
                'text-sm rounded-lg px-4 py-3 border',
                'bg-green-50 border-green-200 text-green-800' => $flashLevel === 'success',
                'bg-red-50 border-red-200 text-red-800' => $flashLevel === 'error',
            ])>{{ $flash }}</div>
        @endif

        <section class="wm-card wm-card--gear-bl">
            <div class="flex items-center justify-between gap-2">
                <h2 class="wm-title" style="font-size:1.05rem;">Document List</h2>
                <div class="flex items-center gap-2">
                    <select wire:model.live="moduleFilter" class="rounded-md border-gray-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500">
                        <option value="">All categories</option>
                        @foreach ($this->moduleOptions as $moduleOption)
                            <option value="{{ $moduleOption }}">{{ $moduleOption }}</option>
                        @endforeach
                    </select>
                    <button type="button" wire:click="createDocument" class="wm-btn-dark">New document</button>
                    <button type="button" wire:click="createDocument" class="wm-btn-dark" style="background:linear-gradient(180deg,#2b6f86,#1d4f61);">Upload from PDF</button>
                </div>
            </div>
            <div class="wm-table" style="margin-top:14px;">
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
        </section>

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

                            @unless ($editingDocumentId)
                                <div
                                    x-data="{ dragging: false }"
                                    x-on:dragover.prevent="dragging = true"
                                    x-on:dragleave.prevent="dragging = false"
                                    x-on:drop.prevent="
                                        dragging = false;
                                        const file = $event.dataTransfer.files[0];
                                        if (file) {
                                            const dt = new DataTransfer();
                                            dt.items.add(file);
                                            $refs.pdfInput.files = dt.files;
                                            $refs.pdfInput.dispatchEvent(new Event('change'));
                                        }
                                    "
                                    :class="dragging ? 'border-sky-500 bg-sky-100' : 'border-sky-300 bg-sky-50/50'"
                                    class="rounded-lg border-2 border-dashed p-4 text-center transition"
                                >
                                    <label class="cursor-pointer block">
                                        <input type="file" x-ref="pdfInput" wire:model="pdfUpload" accept="application/pdf" class="hidden" />
                                        <div class="text-sm font-medium text-sky-800">Drag &amp; drop a PDF here, or click to choose a file</div>
                                        <div class="text-xs text-sky-600 mt-1">We'll scan it to pre-fill the code, title, version, issue date and reason for change below - review before saving.</div>
                                    </label>
                                    <div wire:loading wire:target="pdfUpload" class="mt-2 text-xs text-sky-700">Scanning PDF...</div>
                                    @if ($pdfUpload)
                                        <div class="mt-2 text-xs text-gray-600">Selected: {{ $pdfUpload->getClientOriginalName() }}</div>
                                    @endif
                                    @error('pdfUpload') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
                                </div>
                                @if ($extractInfo)
                                    <div class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-800">{{ $extractInfo }}</div>
                                @endif
                            @endunless

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
                                <div class="md:col-span-2 space-y-3 rounded-md border border-gray-200 bg-gray-50 p-3">
                                    <div>
                                        <div class="block text-xs text-gray-600 mb-1">Data source</div>
                                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                            @foreach ([
                                                \App\Domains\Reporting\Support\DocumentSources::TYPE_PROGRAM => ['Program driven', 'Filled from a program in the app'],
                                                \App\Domains\Reporting\Support\DocumentSources::TYPE_MATERIAL_TRIGGER => ['Material triggered', 'Generated when a material is issued to a batch'],
                                                \App\Domains\Reporting\Support\DocumentSources::TYPE_NONE => ['Not linked', 'Reference document only'],
                                            ] as $sourceValue => [$sourceLabel, $sourceHint])
                                                <label class="flex cursor-pointer items-start gap-2 rounded-md border bg-white px-3 py-2 text-sm {{ $source_type === $sourceValue ? 'border-sky-500 ring-1 ring-sky-500' : 'border-gray-300' }}">
                                                    <input type="radio" wire:model.live="source_type" value="{{ $sourceValue }}" class="mt-0.5 border-gray-300 text-sky-600" />
                                                    <span>
                                                        <span class="block font-medium text-gray-800">{{ $sourceLabel }}</span>
                                                        <span class="block text-xs text-gray-500">{{ $sourceHint }}</span>
                                                    </span>
                                                </label>
                                            @endforeach
                                        </div>
                                        @error('source_type') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </div>

                                    @if ($source_type === \App\Domains\Reporting\Support\DocumentSources::TYPE_PROGRAM)
                                        <div>
                                            <label class="block text-xs text-gray-600 mb-1">Linked program</label>
                                            <select wire:model.live="program_key" class="w-full border-gray-300 rounded-md shadow-sm text-sm">
                                                <option value="">Select a program...</option>
                                                @foreach ($this->programOptions as $programKey => $program)
                                                    <option value="{{ $programKey }}">{{ $program['code'] !== '' ? $program['code'].' - ' : '' }}{{ $program['label'] }}</option>
                                                @endforeach
                                            </select>
                                            @error('program_key') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                            @if ($program_key !== '' && ! $this->linkedProgramHasConfigurablePdf)
                                                <p class="mt-1 text-xs text-amber-700">This program's generated PDF doesn't read the column settings below yet - they're saved, but only the editor and preview use them for now.</p>
                                            @endif
                                        </div>
                                    @elseif ($source_type === \App\Domains\Reporting\Support\DocumentSources::TYPE_MATERIAL_TRIGGER)
                                        <div>
                                            <label class="block text-xs text-gray-600 mb-1">Trigger material codes (WinMan Product IDs)</label>
                                            <input wire:model="trigger_material_codes" class="w-full border-gray-300 rounded-md shadow-sm text-sm" placeholder="e.g. 90010012, 90010013" />
                                            <p class="mt-1 text-xs text-gray-500">Comma-separated. When any of these materials is issued to a batch, this document auto-generates and emails for that day.</p>
                                            @error('trigger_material_codes') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                        </div>
                                    @endif
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

                            <div class="space-y-3" x-data="documentColumnEditor">
                                <div class="font-medium text-sm text-gray-800">Columns</div>

                                <div class="grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_auto_auto] gap-2">
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
                                    <div class="self-end">
                                        <button type="button" wire:click="addAllSetupColumns" @disabled($this->availableSetupColumns === []) class="inline-flex items-center rounded-md border border-sky-300 bg-sky-50 px-3 py-2 text-sm text-sky-800 hover:bg-sky-100 disabled:opacity-50 disabled:cursor-not-allowed">Add all columns</button>
                                    </div>
                                </div>

                                @if ($this->availableSetupColumns === [])
                                    <div class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2">
                                        This document isn't linked to a data source yet. Choose a program or material trigger under Data source above to get its columns, or add columns manually.
                                    </div>
                                @endif

                                @error('setup_columns')
                                    <div class="text-xs text-red-700">{{ $message }}</div>
                                @enderror

                                <div wire:ignore class="space-y-3">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div
                                            class="text-xs rounded-md border px-3 py-1.5"
                                            :class="overLimit ? 'border-red-200 bg-red-50 text-red-700' : 'border-slate-200 bg-slate-50 text-slate-700'"
                                        >
                                            Visible total: <span class="font-semibold" x-text="fmt(visibleTotal) + '%'"></span>
                                            |
                                            Remaining: <span x-text="fmt(100 - visibleTotal) + '%'"></span>
                                        </div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <button type="button" x-on:click="fitToPage()" :disabled="visibleCols.length === 0" class="inline-flex items-center rounded-md border border-slate-300 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50 disabled:opacity-50" title="Scale visible widths so they total exactly 100%, keeping their proportions">Fit to 100%</button>
                                            <button type="button" x-on:click="autoWidths()" :disabled="visibleCols.length === 0" class="inline-flex items-center rounded-md border border-sky-300 bg-sky-50 px-3 py-1.5 text-xs text-sky-800 hover:bg-sky-100 disabled:opacity-50" title="Size each column to its header and sample content, totalling 100%">Auto-adjust widths</button>
                                            <button type="button" x-on:click="addCustomColumn()" class="inline-flex items-center rounded-md border border-slate-300 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50">+ Custom column</button>
                                        </div>
                                    </div>

                                    <div x-show="overLimit" x-cloak class="text-xs text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2">
                                        Visible column widths are above 100%. Reduce widths (or use Fit to 100%) before saving.
                                    </div>

                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200 text-xs">
                                            <thead class="bg-gray-50 text-gray-500 uppercase">
                                                <tr>
                                                    <th class="px-1 py-2"></th>
                                                    <th class="px-2 py-2 text-left">Show</th>
                                                    <th class="px-2 py-2 text-left">Key</th>
                                                    <th class="px-2 py-2 text-left">Label</th>
                                                    <th class="px-2 py-2 text-left">Width %</th>
                                                    <th class="px-2 py-2 text-left">Running %</th>
                                                    <th class="px-2 py-2 text-left"></th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100">
                                                <template x-for="(col, index) in cols" :key="index">
                                                    <tr
                                                        :draggable="rowArmed === index"
                                                        x-on:dragstart="rowDragStart($event, index)"
                                                        x-on:dragover.prevent="rowOver = index"
                                                        x-on:drop.prevent="rowDrop(index)"
                                                        x-on:dragend="rowDragEnd()"
                                                        :class="{
                                                            'opacity-40': rowFrom === index,
                                                            'bg-sky-50': rowFrom !== null && rowOver === index && rowFrom !== index,
                                                            'bg-gray-50 text-gray-400': ! col.visible,
                                                        }"
                                                    >
                                                        <td class="px-1 py-2">
                                                            <span
                                                                class="cursor-grab select-none px-1 text-gray-400 hover:text-gray-700"
                                                                title="Drag to reorder"
                                                                x-on:pointerdown="rowArmed = index"
                                                                x-on:pointerup="rowArmed = null"
                                                            >&#x2807;</span>
                                                        </td>
                                                        <td class="px-2 py-2"><input type="checkbox" x-model="col.visible" class="rounded border-gray-300 text-sky-600"></td>
                                                        <td class="px-2 py-2"><input x-model="col.key" class="w-full border-gray-300 rounded-md text-xs" placeholder="field_key" /></td>
                                                        <td class="px-2 py-2"><input x-model="col.label" class="w-full border-gray-300 rounded-md text-xs" placeholder="Column label" /></td>
                                                        <td class="px-2 py-2"><input type="number" min="1" max="100" step="0.5" x-model.number="col.width" class="w-24 border-gray-300 rounded-md text-xs" /></td>
                                                        <td class="px-2 py-2" :class="runningTotal(index) > 100 ? 'text-red-700 font-semibold' : 'text-gray-600'" x-text="fmt(runningTotal(index)) + '%'"></td>
                                                        <td class="px-2 py-2">
                                                            <button type="button" x-on:click="removeColumn(index)" class="text-red-600 hover:text-red-700">Remove</button>
                                                        </td>
                                                    </tr>
                                                </template>
                                                <tr x-show="cols.length === 0">
                                                    <td colspan="7" class="px-2 py-3 text-gray-500">No columns configured yet for this document.</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="space-y-2">
                                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                                            <div class="font-medium text-sm text-gray-800">Layout preview</div>
                                            <div class="text-xs text-gray-500">Drag a column header to move it. Drag the edge between two headers to resize them.</div>
                                        </div>

                                        <div x-show="hiddenCols.length > 0" class="flex flex-wrap items-center gap-1 text-xs">
                                            <span class="text-gray-500">Hidden:</span>
                                            <template x-for="hidden in hiddenCols" :key="hidden.index">
                                                <button type="button" x-on:click="cols[hidden.index].visible = true" class="rounded-full border border-gray-300 px-2 py-0.5 text-gray-600 hover:bg-gray-50" title="Show this column" x-text="'+ ' + labelFor(hidden.col)"></button>
                                            </template>
                                        </div>

                                        <div x-ref="previewBox" class="relative rounded-md border border-gray-200 bg-slate-100" style="overflow: hidden; padding: 12px;">
                                            <div :style="`width: ${page.w * scale}px; height: ${page.h * scale}px; margin: 0 auto; overflow: hidden;`">
                                                <div
                                                    class="bg-white shadow relative"
                                                    :style="`width: ${page.w}px; height: ${page.h}px; transform: scale(${scale}); transform-origin: top left; box-sizing: border-box; padding: ${num('setup_margin_top', 20)}px ${num('setup_margin_right', 20)}px ${num('setup_margin_bottom', 20)}px ${num('setup_margin_left', 20)}px; font-family: 'DejaVu Sans', Arial, sans-serif; color: #111827; font-size: ${num('setup_base_font_size', 11)}px; line-height: ${num('setup_line_height', 1.25)};`"
                                                >
                                                    <div class="flex items-center" style="padding: 4px 3px; margin-bottom: 12px;">
                                                        <div style="width: 180px; flex-shrink: 0;">
                                                            <img x-show="$wire.setup_show_logo" src="{{ asset('assets/condimentum-logo.png') }}" alt="Logo" style="width: 170px; height: auto;" />
                                                        </div>
                                                        <div style="padding-left: 16px; font-size: 30px; font-weight: 700; line-height: 1.25;" x-text="documentTitle"></div>
                                                    </div>

                                                    <div :style="`border: 2px solid #111827; padding: ${num('setup_content_padding', 10)}px;`">
                                                        <div x-show="$wire.setup_show_ccp_block && ccpLines.length > 0" style="color: #dc2626; font-weight: 700; margin: 10px 0 8px; line-height: 1.35;">
                                                            <template x-for="(line, li) in ccpLines" :key="li">
                                                                <div x-text="line"></div>
                                                            </template>
                                                        </div>

                                                        <div x-show="visibleCols.length === 0" class="text-center text-gray-400" style="padding: 24px;">No visible columns</div>

                                                        <table x-ref="previewTable" x-show="visibleCols.length > 0" style="width: 100%; border-collapse: collapse; table-layout: fixed;">
                                                            <colgroup>
                                                                <template x-for="item in visibleCols" :key="item.index">
                                                                    <col :style="`width: ${Number(item.col.width) || 0}%;`" />
                                                                </template>
                                                            </colgroup>
                                                            <thead>
                                                                <tr>
                                                                    <template x-for="(item, vi) in visibleCols" :key="item.index">
                                                                        <th
                                                                            class="relative select-none"
                                                                            :class="{
                                                                                'cursor-grab': ! resizing,
                                                                                'opacity-40': headFrom === vi,
                                                                                'outline outline-2 outline-sky-500': headFrom !== null && headOver === vi && headFrom !== vi,
                                                                            }"
                                                                            :draggable="! resizing"
                                                                            x-on:dragstart="headDragStart($event, vi)"
                                                                            x-on:dragover.prevent="headOver = vi"
                                                                            x-on:drop.prevent="headDrop(vi)"
                                                                            x-on:dragend="headFrom = null; headOver = null"
                                                                            :title="`${labelFor(item.col)} - ${fmt(Number(item.col.width) || 0)}%`"
                                                                            :style="`border: 1px solid #111827; padding: 4px; vertical-align: top; background: ${headOver === vi && headFrom !== null ? '#e0f2fe' : '#f3f4f6'}; font-size: ${num('setup_table_header_font_size', 10)}px; text-transform: uppercase; letter-spacing: 0.02em; text-align: center; overflow-wrap: anywhere;`"
                                                                        >
                                                                            <span x-text="labelFor(item.col)"></span>
                                                                            <span
                                                                                x-show="vi < visibleCols.length - 1"
                                                                                class="absolute top-0 bottom-0 z-10 cursor-col-resize hover:bg-sky-400/60"
                                                                                :class="resizing && resizing.vi === vi ? 'bg-sky-500/70' : ''"
                                                                                style="right: -5px; width: 10px;"
                                                                                draggable="false"
                                                                                x-on:pointerdown.stop.prevent="startResize($event, vi)"
                                                                            ></span>
                                                                        </th>
                                                                    </template>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <template x-for="row in [0, 1, 2]" :key="row">
                                                                    <tr>
                                                                        <template x-for="item in visibleCols" :key="item.index">
                                                                            <td style="border: 1px solid #111827; padding: 4px; vertical-align: top; overflow-wrap: anywhere;" x-text="sampleFor(item.col)"></td>
                                                                        </template>
                                                                    </tr>
                                                                </template>
                                                            </tbody>
                                                        </table>
                                                    </div>

                                                    <div x-show="$wire.setup_show_qa_signoff">
                                                        <div style="margin-top: 14px; font-size: 12px;">
                                                            QA Approval: Name: <span style="display: inline-block; border-bottom: 1px solid #111827; min-width: 180px; height: 16px; vertical-align: middle;"></span>
                                                            Signed: <span style="display: inline-block; border-bottom: 1px solid #111827; min-width: 240px; height: 16px; vertical-align: middle;"></span>
                                                        </div>
                                                        <div style="margin-top: 14px; font-size: 12px;">
                                                            Date: <span style="display: inline-block; border-bottom: 1px solid #111827; min-width: 180px; height: 16px; vertical-align: middle;"></span>
                                                        </div>
                                                    </div>

                                                    <div x-show="$wire.setup_show_issue_history" class="absolute" style="left: 20px; right: 20px; bottom: 20px;">
                                                        <table style="width: 100%; border-collapse: collapse; table-layout: fixed;">
                                                            <thead>
                                                                <tr>
                                                                    <template x-for="heading in ['Issue Version', 'Date Issued', 'Issued By', 'Reason for Change']" :key="heading">
                                                                        <th :style="`border: 1px solid #111827; padding: 4px; vertical-align: top; background: #f3f4f6; font-size: ${num('setup_table_header_font_size', 10)}px; text-transform: uppercase; letter-spacing: 0.02em;`" x-text="heading"></th>
                                                                    </template>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <tr>
                                                                    <td style="border: 1px solid #111827; padding: 4px; vertical-align: top;" x-text="String($wire.version || '').trim() || '—'"></td>
                                                                    <td style="border: 1px solid #111827; padding: 4px; vertical-align: top;" x-text="issueDateDisplay"></td>
                                                                    <td style="border: 1px solid #111827; padding: 4px; vertical-align: top;" x-text="String($wire.issued_by || '').trim() || '—'"></td>
                                                                    <td style="border: 1px solid #111827; padding: 4px; vertical-align: top; overflow-wrap: anywhere;" x-text="String($wire.reason_for_change || '').trim() || 'No document issue history configured in Settings.'"></td>
                                                                </tr>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                                            <template x-for="item in visibleCols" :key="item.index">
                                                <span><span class="text-gray-700" x-text="labelFor(item.col)"></span> <span x-text="fmt(Number(item.col.width) || 0) + '%'"></span></span>
                                            </template>
                                        </div>
                                    </div>
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

    @script
    <script>
        Alpine.data('documentColumnEditor', () => ({
            cols: $wire.entangle('setup_columns'),
            samples: @js(app(\App\Domains\Reporting\Support\DocumentSources::class)->sampleValues()),
            boxWidth: 0,
            rowArmed: null,
            rowFrom: null,
            rowOver: null,
            headFrom: null,
            headOver: null,
            resizing: null,

            init() {
                const observer = new ResizeObserver(() => {
                    this.boxWidth = this.$refs.previewBox.clientWidth;
                });
                observer.observe(this.$refs.previewBox);
                this.boxWidth = this.$refs.previewBox.clientWidth;
            },

            get visibleCols() {
                return (this.cols || [])
                    .map((col, index) => ({ col, index }))
                    .filter((item) => item.col && item.col.visible);
            },

            get hiddenCols() {
                return (this.cols || [])
                    .map((col, index) => ({ col, index }))
                    .filter((item) => item.col && ! item.col.visible);
            },

            get visibleTotal() {
                return Math.round(this.visibleCols.reduce((sum, item) => sum + (Number(item.col.width) || 0), 0) * 100) / 100;
            },

            get overLimit() {
                return this.visibleTotal > 100;
            },

            get page() {
                const sizes = { A4: [794, 1123], LETTER: [816, 1056] };
                const [w, h] = sizes[this.$wire.setup_paper_size] || sizes.A4;

                return this.$wire.setup_orientation === 'landscape' ? { w: h, h: w } : { w, h };
            },

            get scale() {
                if (! this.boxWidth) {
                    return 0.5;
                }

                return Math.min(1, (this.boxWidth - 24) / this.page.w);
            },

            get documentTitle() {
                return [this.$wire.code, this.$wire.title]
                    .map((part) => String(part || '').trim())
                    .filter(Boolean)
                    .join(' - ') || 'Document title';
            },

            get issueDateDisplay() {
                const value = String(this.$wire.issue_date || '').trim();
                const match = value.match(/^(\d{4})-(\d{2})-(\d{2})/);

                return match ? `${match[3]}-${match[2]}-${match[1]}` : (value || '—');
            },

            get ccpLines() {
                return String(this.$wire.setup_ccp_message || '')
                    .split(/\r\n|\r|\n/)
                    .map((line) => line.trim())
                    .filter(Boolean);
            },

            num(property, fallback) {
                const value = parseFloat(this.$wire[property]);

                return Number.isFinite(value) ? value : fallback;
            },

            fmt(value) {
                return (Math.round(value * 100) / 100).toFixed(2);
            },

            labelFor(col) {
                const label = String(col.label || '').trim();

                return label !== '' ? label : String(col.key || '').replace(/_/g, ' ').toUpperCase();
            },

            sampleFor(col) {
                const key = String(col.key || '').trim();
                if (this.samples[key] !== undefined) {
                    return this.samples[key];
                }
                if (/date/.test(key)) {
                    return '2026-08-12';
                }
                if (/time/.test(key)) {
                    return '06:30';
                }
                if (/(_by|name)$/.test(key)) {
                    return 'Adrian Lacki';
                }

                return 'Sample';
            },

            runningTotal(index) {
                return (this.cols || [])
                    .slice(0, index + 1)
                    .reduce((sum, col) => sum + (col && col.visible ? Number(col.width) || 0 : 0), 0);
            },

            addCustomColumn() {
                this.cols = [...this.cols, { key: 'new_column', label: 'New Column', width: 8, visible: true }];
            },

            removeColumn(index) {
                this.cols = this.cols.filter((_, i) => i !== index);
            },

            moveColumn(from, to) {
                if (from === to || from === null || to === null) {
                    return;
                }

                const next = [...this.cols];
                const [moved] = next.splice(from, 1);
                next.splice(to, 0, moved);
                this.cols = next;
            },

            // Turns weights into 0.5-step percentages that sum to exactly 100.
            applyWeights(weights) {
                const total = weights.reduce((sum, weight) => sum + weight, 0);
                if (total <= 0) {
                    return;
                }

                const widths = weights.map((weight) => Math.max(1, Math.round((weight / total) * 200) / 2));
                const drift = Math.round((100 - widths.reduce((sum, width) => sum + width, 0)) * 2) / 2;
                const widest = widths.indexOf(Math.max(...widths));
                widths[widest] = Math.max(1, widths[widest] + drift);

                const next = this.cols.map((col) => ({ ...col }));
                this.visibleCols.forEach((item, vi) => {
                    next[item.index].width = widths[vi];
                });
                this.cols = next;
            },

            fitToPage() {
                this.applyWeights(this.visibleCols.map((item) => Math.max(0.5, Number(item.col.width) || 0)));
            },

            autoWidths() {
                const context = document.createElement('canvas').getContext('2d');
                const fontFamily = "'DejaVu Sans', Arial, sans-serif";
                const headerSize = this.num('setup_table_header_font_size', 10);
                const bodySize = this.num('setup_base_font_size', 11);
                const cellPadding = 10;

                const measure = (text, font) => {
                    context.font = font;

                    return context.measureText(text).width;
                };

                this.applyWeights(this.visibleCols.map((item) => {
                    const header = this.labelFor(item.col).toUpperCase();
                    const headerFont = `bold ${headerSize}px ${fontFamily}`;
                    const longestWord = Math.max(...header.split(/\s+/).map((word) => measure(word, headerFont)));
                    // Headers may wrap onto two lines; sample values should stay on one.
                    const headerNeed = Math.max(longestWord, measure(header, headerFont) / 2) * 1.04;
                    const sampleNeed = measure(this.sampleFor(item.col), `${bodySize}px ${fontFamily}`);

                    return Math.max(headerNeed, sampleNeed) + cellPadding;
                }));
            },

            rowDragStart(event, index) {
                if (this.rowArmed !== index) {
                    event.preventDefault();

                    return;
                }

                this.rowFrom = index;
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', String(index));
            },

            rowDrop(index) {
                this.moveColumn(this.rowFrom, index);
                this.rowDragEnd();
            },

            rowDragEnd() {
                this.rowArmed = null;
                this.rowFrom = null;
                this.rowOver = null;
            },

            headDragStart(event, vi) {
                if (this.resizing) {
                    event.preventDefault();

                    return;
                }

                this.headFrom = vi;
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', String(vi));
            },

            headDrop(vi) {
                const visible = this.visibleCols;
                if (this.headFrom !== null && visible[this.headFrom] && visible[vi]) {
                    this.moveColumn(visible[this.headFrom].index, visible[vi].index);
                }
                this.headFrom = null;
                this.headOver = null;
            },

            // Trades width between a column and its right-hand neighbour so the total stays fixed.
            startResize(event, vi) {
                const visible = this.visibleCols;
                const left = visible[vi];
                const right = visible[vi + 1];
                if (! left || ! right) {
                    return;
                }

                const tableWidth = this.$refs.previewTable.getBoundingClientRect().width;
                const unitsPerPixel = (this.visibleTotal || 100) / tableWidth;
                const startX = event.clientX;
                const startLeft = Number(left.col.width) || 1;
                const startRight = Number(right.col.width) || 1;
                const pair = startLeft + startRight;

                this.resizing = { vi };

                const onMove = (moveEvent) => {
                    const delta = (moveEvent.clientX - startX) * unitsPerPixel;
                    let nextLeft = Math.round((startLeft + delta) * 2) / 2;
                    nextLeft = Math.min(Math.max(nextLeft, 1), pair - 1);

                    this.cols[left.index].width = nextLeft;
                    this.cols[right.index].width = Math.round((pair - nextLeft) * 100) / 100;
                };

                const onUp = () => {
                    window.removeEventListener('pointermove', onMove);
                    window.removeEventListener('pointerup', onUp);
                    this.resizing = null;
                };

                window.addEventListener('pointermove', onMove);
                window.addEventListener('pointerup', onUp);
            },
        }));
    </script>
    @endscript
</div>
