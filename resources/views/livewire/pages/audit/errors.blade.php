<?php

use App\Features\Audit\GenerateErrorLogReportFeature;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Error Log')] class extends Component {
    public string $date_from = '';
    public string $date_to = '';
    public string $level = '';
    public string $context = '';

    #[Computed]
    public function entries()
    {
        return app(GenerateErrorLogReportFeature::class)([
            'date_from' => $this->date_from ?: null,
            'date_to' => $this->date_to ?: null,
            'level' => $this->level ?: null,
            'context' => $this->context ?: null,
        ]);
    }

    public function getExportUrlProperty(): string
    {
        return route('audit.errors.export', array_filter([
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
            'level' => $this->level,
            'context' => $this->context,
        ]));
    }
}; ?>

<div class="py-8">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Error Log</h2>
                <p class="text-sm text-gray-500">Event-viewer style record of problems hit while booking through checks and production: validation failures, SQL/DB errors and other exceptions.</p>
            </div>
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
                <a href="{{ route('notifications.setup') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('notifications.setup') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Notifications Setup</a>
                <a href="{{ route('audit.index') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('audit.index') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Audit</a>
                <a href="{{ route('audit.errors') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('audit.errors') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Error Log</a>
            </div>
        </div>

        <div class="bg-white shadow-sm rounded-lg p-4 flex flex-wrap items-end gap-3">
            <div><label class="block text-xs text-gray-600 mb-1">From</label><input type="date" wire:model.live="date_from" class="border-gray-300 rounded-md shadow-sm text-sm" /></div>
            <div><label class="block text-xs text-gray-600 mb-1">To</label><input type="date" wire:model.live="date_to" class="border-gray-300 rounded-md shadow-sm text-sm" /></div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Level</label>
                <select wire:model.live="level" class="border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">All</option>
                    <option value="validation">Validation</option>
                    <option value="error">Error</option>
                    <option value="critical">Critical</option>
                </select>
            </div>
            <div><label class="block text-xs text-gray-600 mb-1">Context</label><input wire:model.live.debounce.400ms="context" placeholder="e.g. batches.show.complete" class="border-gray-300 rounded-md shadow-sm text-sm" /></div>
        </div>

        <div class="bg-white shadow-sm rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500 uppercase">
                    <tr><th class="px-4 py-3">Timestamp</th><th class="px-4 py-3">Level</th><th class="px-4 py-3">Context</th><th class="px-4 py-3">Message</th><th class="px-4 py-3">Exception</th><th class="px-4 py-3">User</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($this->entries as $entry)
                        @php
                            $levelStyles = match ($entry->level) {
                                'critical' => ['bg' => '#fef2f2', 'border' => '#fca5a5', 'color' => '#b91c1c'],
                                'validation' => ['bg' => '#fffbeb', 'border' => '#fde68a', 'color' => '#92400e'],
                                default => ['bg' => '#fef2f2', 'border' => '#fecaca', 'color' => '#dc2626'],
                            };
                        @endphp
                        <tr>
                            <td class="px-4 py-2 text-gray-500 whitespace-nowrap">{{ $entry->created_at?->toDateTimeString() }}</td>
                            <td class="px-4 py-2">
                                <span style="display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;border:1px solid {{ $levelStyles['border'] }};background:{{ $levelStyles['bg'] }};color:{{ $levelStyles['color'] }};font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;">{{ $entry->level }}</span>
                            </td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $entry->context ?? '—' }}</td>
                            <td class="px-4 py-2 max-w-md">{{ $entry->message }}</td>
                            <td class="px-4 py-2 text-gray-500 font-mono text-xs">{{ class_basename($entry->exception_class) }}</td>
                            <td class="px-4 py-2">{{ $entry->user?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">No errors logged for these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>
