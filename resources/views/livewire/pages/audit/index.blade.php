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
    <x-mo-workspace-styles />
    <div class="wm-page max-w-7xl mx-auto space-y-6">

        <section class="wm-card wm-card--gear-tr">
            <div class="flex items-center justify-between">
                <h1 class="wm-title">Audit Trail</h1>
                <a href="{{ $this->exportUrl }}" class="wm-link">Download CSV</a>
            </div>

            <x-settings-subnav />
        </section>

        <section class="wm-card wm-card--gear-bl" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px;">
            <div><label class="block text-xs text-gray-600 mb-1">From</label><input type="date" wire:model.live="date_from" class="border-gray-300 rounded-md shadow-sm text-sm" /></div>
            <div><label class="block text-xs text-gray-600 mb-1">To</label><input type="date" wire:model.live="date_to" class="border-gray-300 rounded-md shadow-sm text-sm" /></div>
            <div><label class="block text-xs text-gray-600 mb-1">Entity</label><input wire:model.live.debounce.400ms="entity_name" placeholder="e.g. batch_records" class="border-gray-300 rounded-md shadow-sm text-sm" /></div>
            <div><label class="block text-xs text-gray-600 mb-1">Action</label><input wire:model.live.debounce.400ms="action" placeholder="e.g. complete" class="border-gray-300 rounded-md shadow-sm text-sm" /></div>
        </section>

        <section class="wm-card wm-card--gear-tr">
            <div class="wm-table" style="margin-top:0;">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead>
                    <tr style="background:linear-gradient(180deg,#2b3238,#171c20);color:#fff;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;"><th class="px-4 py-3">Timestamp</th><th class="px-4 py-3">Entity</th><th class="px-4 py-3">Action</th><th class="px-4 py-3">Field</th><th class="px-4 py-3">Old → New</th><th class="px-4 py-3">Reason</th><th class="px-4 py-3">User</th></tr>
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
        </section>
    </div>
</div>
