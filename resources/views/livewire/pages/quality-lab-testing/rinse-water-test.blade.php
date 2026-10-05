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
    <x-mo-workspace-styles />
    <div class="wm-page max-w-6xl mx-auto space-y-6">

        <section class="wm-card wm-card--gear-tr">
            <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
                <span class="wml-medal">
                    <img src="{{ asset('images/dashboard/microscope.png') }}" alt="Rinse Water Test" />
                </span>

                <div>
                    <h1 class="wm-title">WM010 RINSE WATER TEST SHEET</h1>
                    <div class="wm-sub" style="text-transform:uppercase;letter-spacing:.1em;">Cleaning Chemicals &middot; Sulphites &middot; Titration</div>
                </div>

            </div>
        </section>

        @if ($flash)
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ $flash }}</div>
        @endif

        <form wire:submit="save" class="wm-table">
            <div class="flex items-center justify-between px-6 py-4" style="background:linear-gradient(180deg,#2b3238,#171c20);border-radius:16px 16px 0 0;">
                <h2 class="text-lg font-semibold text-white">New rinse-water row</h2>
            </div>

            <div class="p-6 grid gap-4 md:grid-cols-3">
                <div><label class="mb-1 block text-sm text-slate-600">Date</label><input type="date" wire:model.defer="tested_date" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" /></div>
                <div><label class="mb-1 block text-sm text-slate-600">Section</label><select wire:model.defer="section" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"><option value="cleaning_chemicals">Cleaning chemicals</option><option value="sulphites">Sulphites</option><option value="chemical_titration">Chemical titration check</option></select></div>
                <div><label class="mb-1 block text-sm text-slate-600">Operator name</label><input wire:model.defer="operator_name" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" /></div>
                <div><label class="mb-1 block text-sm text-slate-600">Equipment</label><input wire:model.defer="equipment" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" /></div>
                <div><label class="mb-1 block text-sm text-slate-600">Reading</label><input type="number" step="0.001" wire:model.defer="reading" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" /></div>
                <div><label class="mb-1 block text-sm text-slate-600">Reading unit</label><input wire:model.defer="reading_unit" placeholder="pH or mg/Ltr" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" /></div>
                <div><label class="mb-1 block text-sm text-slate-600">Pass / Fail</label><select wire:model.defer="pass_or_fail" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"><option value="Pass">Pass</option><option value="Fail">Fail</option></select></div>
                <div class="md:col-span-2"><label class="mb-1 block text-sm text-slate-600">Action taken if failed</label><input wire:model.defer="action_taken_if_failed" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" /></div>
                <div><label class="mb-1 block text-sm text-slate-600">Chemical used</label><input wire:model.defer="chemical_used" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" /></div>
                <div><label class="mb-1 block text-sm text-slate-600">Target</label><input wire:model.defer="target" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" /></div>
                <div><label class="mb-1 block text-sm text-slate-600">Result</label><input wire:model.defer="result" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" /></div>
            </div>

            <div class="px-6 pb-6"><button type="submit" class="wm-btn-dark">Save WM010</button></div>
        </form>

        <div class="wm-table">
            <div class="flex items-center justify-between px-6 py-4" style="background:linear-gradient(180deg,#2b3238,#171c20);border-radius:16px 16px 0 0;">
                <h3 class="text-lg font-semibold text-white">Recent WM010 entries</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead>
                        <tr style="background:linear-gradient(180deg,#2b3238,#171c20);color:#fff;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;">
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Section</th>
                            <th class="px-4 py-3">Equipment</th>
                            <th class="px-4 py-3">Reading</th>
                            <th class="px-4 py-3">Pass/Fail</th>
                            <th class="px-4 py-3">Operator</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($this->recent as $row)
                            <tr>
                                <td class="px-4 py-2">{{ $row->tested_date?->toDateString() }}</td>
                                <td class="px-4 py-2">{{ \Illuminate\Support\Str::headline((string) $row->section) }}</td>
                                <td class="px-4 py-2">{{ $row->equipment ?? '—' }}</td>
                                <td class="px-4 py-2">{{ $row->reading !== null ? $row->reading.' '.($row->reading_unit ?? '') : '—' }}</td>
                                <td class="px-4 py-2">{{ $row->pass_or_fail ?? '—' }}</td>
                                <td class="px-4 py-2">{{ $row->operator_name ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No entries yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
