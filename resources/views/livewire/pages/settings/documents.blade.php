<?php

use App\Models\DocumentReference;
use App\Models\DocumentReferenceChange;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Settings - Documents')] class extends Component {
    public bool $documentModalOpen = false;

    public ?int $editingDocumentId = null;

    public string $code = '';
    public string $title = '';
    public string $version = '';
    public string $issue_date = '';
    public string $module = '';
    public string $status = '';

    public ?int $selectedDocumentId = null;

    public ?string $flash = null;
    public string $flashLevel = 'success';

    public function mount(): void
    {
        $firstId = DocumentReference::query()->orderBy('code')->value('id');
        $this->selectedDocumentId = $firstId ? (int) $firstId : null;
    }

    #[Computed]
    public function documents()
    {
        if (! Schema::hasTable('document_reference_changes')) {
            return DocumentReference::query()
                ->orderBy('code')
                ->get()
                ->each(fn (DocumentReference $document) => $document->setAttribute('changes_count', 0));
        }

        return DocumentReference::query()
            ->withCount('changes')
            ->orderBy('code')
            ->get();
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
        $document = DocumentReference::query()->findOrFail($id);

        $this->editingDocumentId = $document->id;
        $this->code = (string) $document->code;
        $this->title = (string) $document->title;
        $this->version = (string) ($document->version ?? '');
        $this->issue_date = $document->issue_date?->toDateString() ?? '';
        $this->module = (string) ($document->module ?? '');
        $this->status = (string) ($document->status ?? '');
        $this->selectedDocumentId = $document->id;
        $this->documentModalOpen = true;
        unset($this->selectedDocumentChanges);
    }

    public function createDocument(): void
    {
        $this->resetDocumentForm();
        $this->selectedDocumentId = null;
        $this->documentModalOpen = true;
    }

    public function closeDocumentModal(): void
    {
        $this->documentModalOpen = false;
    }

    public function resetDocumentForm(): void
    {
        $this->editingDocumentId = null;
        $this->code = '';
        $this->title = '';
        $this->version = '';
        $this->issue_date = '';
        $this->module = '';
        $this->status = '';
    }

    public function saveDocument(): void
    {
        $validated = $this->validate([
            'code' => ['required', 'string', 'max:50'],
            'title' => ['required', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:50'],
            'issue_date' => ['nullable', 'date'],
            'module' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:100'],
        ]);

        $normalizedCode = strtoupper(trim((string) $validated['code']));
        $isUpdate = $this->editingDocumentId !== null;
        $existing = $isUpdate ? DocumentReference::query()->find($this->editingDocumentId) : null;

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
                'title' => trim((string) $validated['title']),
                'version' => trim((string) ($validated['version'] ?? '')) ?: null,
                'issue_date' => trim((string) ($validated['issue_date'] ?? '')) ?: null,
                'module' => trim((string) ($validated['module'] ?? '')) ?: null,
                'status' => trim((string) ($validated['status'] ?? '')) ?: null,
            ],
        );

        if (Schema::hasTable('document_reference_changes')) {
            $newVersion = trim((string) ($document->version ?? ''));
            $oldVersion = trim((string) ($existing?->version ?? ''));
            $didVersionChange = $oldVersion !== $newVersion;

            $changeReason = match (true) {
                ! $isUpdate => 'Initial issue.',
                $didVersionChange && $oldVersion !== '' && $newVersion !== '' => 'Superseded version '.$oldVersion.' with version '.$newVersion.'.',
                $didVersionChange && $newVersion !== '' => 'Issued version '.$newVersion.'.',
                default => 'Metadata updated.',
            };

            DocumentReferenceChange::query()->create([
                'document_reference_id' => (int) $document->id,
                'issue_version' => $newVersion !== '' ? $newVersion : null,
                'date_issued' => $document->issue_date?->toDateString() ?? now()->toDateString(),
                'issued_by' => auth()->user()?->name,
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
        $this->status = (string) ($document->status ?? '');
        $this->flashLevel = 'success';
        $this->flash = $isUpdate ? 'Document updated. History entry recorded.' : 'Document created. History entry recorded.';
        unset($this->documents, $this->selectedDocumentChanges);
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
                <x-primary-button type="button" wire:click="createDocument">New document</x-primary-button>
            </div>
            <div class="max-h-[560px] overflow-y-auto divide-y divide-gray-100">
                @forelse ($this->documents as $document)
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
            <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/40" wire:click="closeDocumentModal"></div>
                <div class="relative w-full max-w-5xl max-h-[90vh] overflow-y-auto rounded-xl bg-white border border-gray-200 shadow-2xl">
                    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">{{ $editingDocumentId ? 'Edit document' : 'New document' }}</h3>
                            <p class="text-xs text-gray-500">Manage metadata and document issue history in one place.</p>
                        </div>
                        <button type="button" wire:click="closeDocumentModal" class="text-sm text-gray-500 hover:text-gray-700">Close</button>
                    </div>

                    <div class="p-6 space-y-6">
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
                                    <label class="block text-xs text-gray-600 mb-1">Status</label>
                                    <input wire:model.defer="status" class="w-full border-gray-300 rounded-md shadow-sm text-sm" placeholder="Active" />
                                    @error('status') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-xs text-gray-600 mb-1">Module</label>
                                    <input wire:model.defer="module" class="w-full border-gray-300 rounded-md shadow-sm text-sm" placeholder="Metal Detector" />
                                    @error('module') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                </div>
                            </div>

                            <div class="flex items-center justify-end gap-2">
                                <x-secondary-button type="button" wire:click="resetDocumentForm">Clear</x-secondary-button>
                                <x-primary-button type="button" wire:click="saveDocument">{{ $editingDocumentId ? 'Update document' : 'Create document' }}</x-primary-button>
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
