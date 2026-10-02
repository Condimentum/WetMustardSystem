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
    <x-mo-workspace-styles />
    <div class="wm-page max-w-7xl mx-auto space-y-6">

        <section class="wm-card wm-card--gear-tr">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="wm-title">Error Log</h1>
                    <p class="wm-sub" style="margin-top:4px;">Event-viewer style record of problems hit while booking through checks and production: validation failures, SQL/DB errors and other exceptions.</p>
                </div>
                <a href="{{ $this->exportUrl }}" class="wm-link">Download CSV</a>
            </div>

            <x-settings-subnav />
        </section>

        <section class="wm-card wm-card--gear-bl" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px;">
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
        </section>

        <section class="wm-card wm-card--gear-tr">
            <div class="wm-table" style="margin-top:0;">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead>
                    <tr style="background:linear-gradient(180deg,#2b3238,#171c20);color:#fff;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;"><th class="px-4 py-3">Timestamp</th><th class="px-4 py-3">Level</th><th class="px-4 py-3">Context</th><th class="px-4 py-3">Message</th><th class="px-4 py-3">Exception</th><th class="px-4 py-3">User</th></tr>
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
        </section>
    </div>
</div>
