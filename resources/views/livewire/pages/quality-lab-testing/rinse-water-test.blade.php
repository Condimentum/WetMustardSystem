<?php

use App\Models\Wm010RinseWaterTestEntry;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('WM010 Rinse Water Test')] class extends Component {
    public string $tested_date = '';
    public string $section = Wm010RinseWaterTestEntry::SECTION_CLEANING_CHEMICALS;
    public string $equipment = '';
    public string $reading = '';
    public string $reading_unit = '';
    public string $pass_or_fail = 'Pass';
    public string $action_taken_if_failed = '';
    public string $operator_name = '';
    public string $chemical_used = '';
    public string $target = '';
    public string $result = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->tested_date = now()->toDateString();
        $this->operator_name = (string) (auth()->user()?->name ?? '');
    }

    #[Computed]
    public function recent()
    {
        return Wm010RinseWaterTestEntry::query()->latest('tested_date')->latest('id')->limit(25)->get();
    }

    public function save(): void
    {
        $data = $this->validate([
            'tested_date' => ['required', 'date'],
            'section' => ['required', 'in:cleaning_chemicals,sulphites,chemical_titration'],
            'equipment' => ['nullable', 'string', 'max:255'],
            'reading' => ['nullable', 'numeric'],
            'reading_unit' => ['nullable', 'string', 'max:30'],
            'pass_or_fail' => ['nullable', 'in:Pass,Fail'],
            'action_taken_if_failed' => ['nullable', 'string', 'max:500'],
            'operator_name' => ['nullable', 'string', 'max:255'],
            'chemical_used' => ['nullable', 'string', 'max:255'],
            'target' => ['nullable', 'string', 'max:255'],
            'result' => ['nullable', 'string', 'max:255'],
        ]);

        Wm010RinseWaterTestEntry::query()->create([
            'tested_date' => $data['tested_date'],
            'section' => $data['section'],
            'equipment' => trim((string) $data['equipment']) !== '' ? trim((string) $data['equipment']) : null,
            'reading' => $data['reading'] !== '' ? (float) $data['reading'] : null,
            'reading_unit' => trim((string) $data['reading_unit']) !== '' ? trim((string) $data['reading_unit']) : null,
            'pass_or_fail' => $data['pass_or_fail'] !== '' ? $data['pass_or_fail'] : null,
            'action_taken_if_failed' => trim((string) $data['action_taken_if_failed']) !== '' ? trim((string) $data['action_taken_if_failed']) : null,
            'operator_name' => trim((string) $data['operator_name']) !== '' ? trim((string) $data['operator_name']) : null,
            'chemical_used' => trim((string) $data['chemical_used']) !== '' ? trim((string) $data['chemical_used']) : null,
            'target' => trim((string) $data['target']) !== '' ? trim((string) $data['target']) : null,
            'result' => trim((string) $data['result']) !== '' ? trim((string) $data['result']) : null,
            'recorded_by_user_id' => auth()->id(),
        ]);

        $this->flash = 'WM010 row saved.';
        unset($this->recent);
    }
}; ?>

<div class="py-8">
    <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div style="background:#fff;border:1px solid #dbe1ea;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,0.08);">
            <div style="padding:24px 26px;background:linear-gradient(135deg,#f8fafc 0%,#e0ecff 100%);border-bottom:1px solid #dbe1ea;">
                <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
                    <div style="width:56px;height:56px;background:#ecfdf5;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid #86efac;overflow:hidden;flex-shrink:0;">
                        <img src="{{ asset('lab-testing-icon.png') }}" alt="Rinse Water Test" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" />
                    </div>

                    <div>
                        <div style="font-size:1.3rem;font-weight:900;color:#1a1a2e;letter-spacing:-0.02em;line-height:1;">WM010 RINSE WATER TEST SHEET</div>
                        <div style="font-size:0.75rem;font-weight:700;color:#64748b;letter-spacing:.14em;margin-top:4px;">CLEANING CHEMICALS &middot; SULPHITES &middot; TITRATION</div>
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
            <div style="background:#2d3f8f;" class="px-5 py-3 text-white font-semibold">New rinse-water row</div>
            <div class="p-5 grid gap-3 md:grid-cols-3">
                <div><label class="block text-xs text-gray-600 mb-1">Date</label><input type="date" wire:model.defer="tested_date" class="w-full rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Section</label><select wire:model.defer="section" class="w-full rounded-md border-gray-300 text-sm"><option value="cleaning_chemicals">Cleaning chemicals</option><option value="sulphites">Sulphites</option><option value="chemical_titration">Chemical titration check</option></select></div>
                <div><label class="block text-xs text-gray-600 mb-1">Operator name</label><input wire:model.defer="operator_name" class="w-full rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Equipment</label><input wire:model.defer="equipment" class="w-full rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Reading</label><input type="number" step="0.001" wire:model.defer="reading" class="w-full rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Reading unit</label><input wire:model.defer="reading_unit" placeholder="pH or mg/Ltr" class="w-full rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Pass / Fail</label><select wire:model.defer="pass_or_fail" class="w-full rounded-md border-gray-300 text-sm"><option value="Pass">Pass</option><option value="Fail">Fail</option></select></div>
                <div class="md:col-span-2"><label class="block text-xs text-gray-600 mb-1">Action taken if failed</label><input wire:model.defer="action_taken_if_failed" class="w-full rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Chemical used</label><input wire:model.defer="chemical_used" class="w-full rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Target</label><input wire:model.defer="target" class="w-full rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-600 mb-1">Result</label><input wire:model.defer="result" class="w-full rounded-md border-gray-300 text-sm" /></div>
            </div>
            <div class="px-5 pb-5"><x-primary-button type="submit">Save WM010</x-primary-button></div>
        </form>

        <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden mt-6">
            <div style="background:#2d3f8f;" class="px-4 py-2 text-sm font-semibold text-white">Recent WM010 entries</div>
            <table class="min-w-full text-sm">
                <thead><tr style="background:#2d3f8f;color:#fff;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;"><th class="px-3 py-2 text-left">Date</th><th class="px-3 py-2 text-left">Section</th><th class="px-3 py-2 text-left">Equipment</th><th class="px-3 py-2 text-left">Reading</th><th class="px-3 py-2 text-left">Pass/Fail</th><th class="px-3 py-2 text-left">Operator</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse ($this->recent as $row)
                    <tr>
                        <td class="px-3 py-2">{{ $row->tested_date?->toDateString() }}</td>
                        <td class="px-3 py-2">{{ \Illuminate\Support\Str::headline((string) $row->section) }}</td>
                        <td class="px-3 py-2">{{ $row->equipment ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $row->reading !== null ? $row->reading.' '.($row->reading_unit ?? '') : '—' }}</td>
                        <td class="px-3 py-2">{{ $row->pass_or_fail ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $row->operator_name ?? '—' }}</td>
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
