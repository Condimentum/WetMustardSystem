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
        <div style="background:#fff;border:1px solid #dbe1ea;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,0.08);">
            <div style="padding:24px 26px;background:linear-gradient(135deg,#f8fafc 0%,#e0ecff 100%);border-bottom:1px solid #dbe1ea;">
                <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
                    <div style="width:56px;height:56px;background:#ecfdf5;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid #86efac;overflow:hidden;flex-shrink:0;">
                        <img src="{{ asset('lab-testing-icon.png') }}" alt="IBC Traceability" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" />
                    </div>

                    <div>
                        <div style="font-size:1.3rem;font-weight:900;color:#1a1a2e;letter-spacing:-0.02em;line-height:1;">WM003 VINEGAR IBC TRACEABILITY</div>
                        <div style="font-size:0.75rem;font-weight:700;color:#64748b;letter-spacing:.14em;margin-top:4px;">DETAILS TAKEN FROM IBC LABEL</div>
                    </div>

                    <a href="{{ route('quality.lab-testing') }}" wire:navigate style="margin-left:auto;display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;font-size:12px;font-weight:800;text-decoration:none;">
                        Back to Quality &amp; Lab Testing
                    </a>
                </div>
            </div>

            <div style="padding:24px 26px;">

        @if ($flash)
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 mb-6">{{ $flash }}</div>
        @endif

        <form wire:submit="save" class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div style="background:#2d3f8f;" class="px-5 py-3 text-white font-semibold">New traceability entry</div>
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

        <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden mt-6">
            <div style="background:#2d3f8f;" class="px-4 py-2 text-sm font-semibold text-white">Recent WM003 entries</div>
            <table class="min-w-full text-sm">
                <thead><tr style="background:#2d3f8f;color:#fff;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;"><th class="px-3 py-2 text-left">Date Used</th><th class="px-3 py-2 text-left">Supplier Production</th><th class="px-3 py-2 text-left">Best Before</th><th class="px-3 py-2 text-left">Batch</th><th class="px-3 py-2 text-left">Time</th><th class="px-3 py-2 text-left">Operator</th></tr></thead>
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
    </div>
</div>
