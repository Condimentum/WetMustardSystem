<?php

use App\Models\Wm003IbcTraceabilityEntry;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('WM003 IBC Traceability')] class extends Component {
    public string $date_used = '';
    public string $supplier_production_date = '';
    public string $best_before_date = '';
    public string $batch_no = '';
    public string $time_on = '';
    public string $operator_name = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->date_used = now()->toDateString();
        $this->time_on = now()->format('H:i');
        $this->operator_name = (string) (auth()->user()?->name ?? '');
    }

    #[Computed]
    public function recent()
    {
        return Wm003IbcTraceabilityEntry::query()
            ->latest('date_used')
            ->latest('id')
            ->limit(20)
            ->get();
    }

    public function save(): void
    {
        $data = $this->validate([
            'date_used' => ['required', 'date'],
            'supplier_production_date' => ['nullable', 'date'],
            'best_before_date' => ['nullable', 'date'],
            'batch_no' => ['nullable', 'string', 'max:120'],
            'time_on' => ['nullable', 'date_format:H:i'],
            'operator_name' => ['required', 'string', 'max:255'],
        ]);

        Wm003IbcTraceabilityEntry::query()->create([
            'date_used' => $data['date_used'],
            'supplier_production_date' => $data['supplier_production_date'] !== '' ? $data['supplier_production_date'] : null,
            'best_before_date' => $data['best_before_date'] !== '' ? $data['best_before_date'] : null,
            'batch_no' => trim((string) ($data['batch_no'] ?? '')) !== '' ? trim((string) $data['batch_no']) : null,
            'time_on' => $data['time_on'] !== '' ? $data['time_on'].':00' : null,
            'operator_name' => trim((string) $data['operator_name']),
            'recorded_by_user_id' => auth()->id(),
        ]);

        $this->batch_no = '';
        $this->flash = 'WM003 row saved.';
        unset($this->recent);
    }
}; ?>

<div class="py-8">
    <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-800">WM003 Vinegar IBC Traceability</h2>
                <p class="text-sm text-gray-500">Details taken from IBC label.</p>
            </div>
            <a href="{{ route('quality.lab-testing') }}" wire:navigate class="text-sm text-indigo-600 hover:underline">Back to Quality &amp; Lab Testing</a>
        </div>

        @if ($flash)
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ $flash }}</div>
        @endif

        <form wire:submit="save" class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="bg-slate-900 px-5 py-3 text-white font-semibold">New traceability entry</div>
            <div class="p-5 grid gap-3 md:grid-cols-3">
                <div><label class="block text-xs text-gray-600 mb-1">Date used</label><input type="date" wire:model.defer="date_used" class="w-full rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Supplier production date</label><input type="date" wire:model.defer="supplier_production_date" class="w-full rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Best before date</label><input type="date" wire:model.defer="best_before_date" class="w-full rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Batch no.</label><input wire:model.defer="batch_no" class="w-full rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Time on</label><input type="time" wire:model.defer="time_on" class="w-full rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Operator name</label><input wire:model.defer="operator_name" class="w-full rounded-md border-gray-300 text-sm" /></div>
            </div>
            <div class="px-5 pb-5">
                <x-primary-button type="submit">Save WM003</x-primary-button>
            </div>
        </form>

        <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-700">Recent WM003 entries</div>
            <table class="min-w-full text-sm">
                <thead class="bg-white text-xs uppercase text-slate-500"><tr><th class="px-3 py-2 text-left">Date Used</th><th class="px-3 py-2 text-left">Supplier Production</th><th class="px-3 py-2 text-left">Best Before</th><th class="px-3 py-2 text-left">Batch</th><th class="px-3 py-2 text-left">Time</th><th class="px-3 py-2 text-left">Operator</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($this->recent as $row)
                    <tr>
                        <td class="px-3 py-2">{{ $row->date_used?->toDateString() }}</td>
                        <td class="px-3 py-2">{{ $row->supplier_production_date?->toDateString() ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $row->best_before_date?->toDateString() ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $row->batch_no ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $row->time_on ? \Illuminate\Support\Carbon::parse($row->time_on)->format('H:i') : '—' }}</td>
                        <td class="px-3 py-2">{{ $row->operator_name }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-3 py-3 text-slate-500">No entries yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
