<?php

use App\Features\Audit\GenerateAuditTrailReportFeature;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Audit Trail')] class extends Component {
    public string $date_from = '';
    public string $date_to = '';
    public string $entity_name = '';
    public string $action = '';

    #[Computed]
    public function entries()
    {
        return app(GenerateAuditTrailReportFeature::class)([
            'date_from' => $this->date_from ?: null,
            'date_to' => $this->date_to ?: null,
            'entity_name' => $this->entity_name ?: null,
            'action' => $this->action ?: null,
        ]);
    }

    public function getExportUrlProperty(): string
    {
        return route('audit.export', array_filter([
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
            'entity_name' => $this->entity_name,
            'action' => $this->action,
        ]));
    }
}; ?>

<div class="py-8">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-800">Audit Trail</h2>
            <a href="{{ $this->exportUrl }}" class="text-sm text-indigo-600 hover:underline">Download CSV</a>
        </div>

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

        <div class="bg-white shadow-sm rounded-lg p-4 flex flex-wrap items-end gap-3">
            <div><label class="block text-xs text-gray-600 mb-1">From</label><input type="date" wire:model.live="date_from" class="border-gray-300 rounded-md shadow-sm text-sm" /></div>
            <div><label class="block text-xs text-gray-600 mb-1">To</label><input type="date" wire:model.live="date_to" class="border-gray-300 rounded-md shadow-sm text-sm" /></div>
            <div><label class="block text-xs text-gray-600 mb-1">Entity</label><input wire:model.live.debounce.400ms="entity_name" placeholder="e.g. batch_records" class="border-gray-300 rounded-md shadow-sm text-sm" /></div>
            <div><label class="block text-xs text-gray-600 mb-1">Action</label><input wire:model.live.debounce.400ms="action" placeholder="e.g. complete" class="border-gray-300 rounded-md shadow-sm text-sm" /></div>
        </div>

        <div class="bg-white shadow-sm rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500 uppercase">
                    <tr><th class="px-4 py-3">Timestamp</th><th class="px-4 py-3">Entity</th><th class="px-4 py-3">Action</th><th class="px-4 py-3">Field</th><th class="px-4 py-3">Old → New</th><th class="px-4 py-3">Reason</th><th class="px-4 py-3">User</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($this->entries as $entry)
                        <tr>
                            <td class="px-4 py-2 text-gray-500 whitespace-nowrap">{{ $entry->created_at?->toDateTimeString() }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $entry->entity_name }}#{{ $entry->entity_id }}</td>
                            <td class="px-4 py-2">{{ $entry->action }}</td>
                            <td class="px-4 py-2">{{ $entry->field_name ?? '—' }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ $entry->old_value ?? '∅' }} → {{ $entry->new_value ?? '∅' }}</td>
                            <td class="px-4 py-2">{{ $entry->reason ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $entry->user?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500">No audit entries match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
