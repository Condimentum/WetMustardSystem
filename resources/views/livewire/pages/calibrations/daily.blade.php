<?php

use App\Models\LabScaleCalibration;
use App\Models\ProductionScaleCalibration;
use App\Models\SaltMeterCalibration;
use App\Models\ViscosityMeterAutozeroCheck;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Daily Calibrations')] class extends Component {
    public string $wm001_date = '';
    public string $wm001_reading = '';
    public string $wm001_operator_name = '';
    public string $wm001_deviation_reason = '';

    public string $wm002_date = '';
    public string $wm002_reading = '';
    public string $wm002_operator_name = '';
    public string $wm002_deviation_reason = '';

    public string $wm006_date = '';
    public bool $wm006_complete = true;
    public string $wm006_operator_name = '';
    public string $wm006_deviation_reason = '';

    public string $wm013_date = '';
    public string $wm013_powder_3kg = '';
    public string $wm013_powder_30kg = '';
    public string $wm013_pallecon = '';
    public string $wm013_bucket_filler = '';
    public string $wm013_operator_name = '';
    public string $wm013_deviation_reason = '';

    public ?string $flash = null;
    public string $flashLevel = 'success';

    public function mount(): void
    {
        $today = now()->toDateString();
        $name = (string) (auth()->user()?->name ?? '');

        $this->wm001_date = $today;
        $this->wm001_operator_name = $name;

        $this->wm002_date = $today;
        $this->wm002_operator_name = $name;

        $this->wm006_date = $today;
        $this->wm006_operator_name = $name;

        $this->wm013_date = $today;
        $this->wm013_operator_name = $name;
    }

    #[Computed]
    public function todayStatus(): array
    {
        $today = now()->toDateString();

        return [
            'wm001' => LabScaleCalibration::query()->whereDate('checked_date', $today)->exists(),
            'wm002' => SaltMeterCalibration::query()->whereDate('checked_date', $today)->exists(),
            'wm006' => ViscosityMeterAutozeroCheck::query()->whereDate('checked_date', $today)->exists(),
            'wm013' => ProductionScaleCalibration::query()->whereDate('checked_date', $today)->exists(),
        ];
    }

    public function saveWm001(): void
    {
        $data = $this->validate([
            'wm001_date' => ['required', 'date'],
            'wm001_reading' => ['required', 'numeric'],
            'wm001_operator_name' => ['required', 'string', 'max:255'],
            'wm001_deviation_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $reading = (float) $data['wm001_reading'];
        $passed = $reading >= 99.98 && $reading <= 100.02;

        if (! $passed && trim((string) $data['wm001_deviation_reason']) === '') {
            $this->addError('wm001_deviation_reason', 'Provide reason/action when reading is outside 100 +/- 0.02.');

            return;
        }

        LabScaleCalibration::query()->create([
            'checked_date' => $data['wm001_date'],
            'reading' => $reading,
            'passed' => $passed,
            'operator_name' => trim((string) $data['wm001_operator_name']),
            'deviation_reason' => trim((string) $data['wm001_deviation_reason']) !== '' ? trim((string) $data['wm001_deviation_reason']) : null,
            'checked_by_user_id' => auth()->id(),
        ]);

        $this->wm001_reading = '';
        $this->wm001_deviation_reason = '';
        $this->flashLevel = 'success';
        $this->flash = 'WM001 entry saved.';
        unset($this->todayStatus);
    }

    public function saveWm002(): void
    {
        $data = $this->validate([
            'wm002_date' => ['required', 'date'],
            'wm002_reading' => ['required', 'numeric'],
            'wm002_operator_name' => ['required', 'string', 'max:255'],
            'wm002_deviation_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $reading = (float) $data['wm002_reading'];
        $passed = $reading >= 98 && $reading <= 102;

        if (! $passed && trim((string) $data['wm002_deviation_reason']) === '') {
            $this->addError('wm002_deviation_reason', 'Provide reason/action when reading is outside 100 +/- 2 mg/l.');

            return;
        }

        SaltMeterCalibration::query()->create([
            'checked_date' => $data['wm002_date'],
            'reading' => $reading,
            'passed' => $passed,
            'operator_name' => trim((string) $data['wm002_operator_name']),
            'deviation_reason' => trim((string) $data['wm002_deviation_reason']) !== '' ? trim((string) $data['wm002_deviation_reason']) : null,
            'checked_by_user_id' => auth()->id(),
        ]);

        $this->wm002_reading = '';
        $this->wm002_deviation_reason = '';
        $this->flashLevel = 'success';
        $this->flash = 'WM002 entry saved.';
        unset($this->todayStatus);
    }

    public function saveWm006(): void
    {
        $data = $this->validate([
            'wm006_date' => ['required', 'date'],
            'wm006_complete' => ['boolean'],
            'wm006_operator_name' => ['required', 'string', 'max:255'],
            'wm006_deviation_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $complete = (bool) $data['wm006_complete'];

        if (! $complete && trim((string) $data['wm006_deviation_reason']) === '') {
            $this->addError('wm006_deviation_reason', 'Provide reason/action when check is not complete.');

            return;
        }

        ViscosityMeterAutozeroCheck::query()->create([
            'checked_date' => $data['wm006_date'],
            'complete' => $complete,
            'operator_name' => trim((string) $data['wm006_operator_name']),
            'deviation_reason' => trim((string) $data['wm006_deviation_reason']) !== '' ? trim((string) $data['wm006_deviation_reason']) : null,
            'checked_by_user_id' => auth()->id(),
        ]);

        $this->wm006_complete = true;
        $this->wm006_deviation_reason = '';
        $this->flashLevel = 'success';
        $this->flash = 'WM006 entry saved.';
        unset($this->todayStatus);
    }

    public function saveWm013(): void
    {
        $data = $this->validate([
            'wm013_date' => ['required', 'date'],
            'wm013_powder_3kg' => ['required', 'numeric'],
            'wm013_powder_30kg' => ['required', 'numeric'],
            'wm013_pallecon' => ['required', 'numeric'],
            'wm013_bucket_filler' => ['required', 'numeric'],
            'wm013_operator_name' => ['required', 'string', 'max:255'],
            'wm013_deviation_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $powder3kg = (float) $data['wm013_powder_3kg'];
        $powder30kg = (float) $data['wm013_powder_30kg'];
        $pallecon = (float) $data['wm013_pallecon'];
        $bucketFiller = (float) $data['wm013_bucket_filler'];

        $passed = $powder3kg >= 99.98 && $powder3kg <= 100.02
            && $powder30kg >= 9.9 && $powder30kg <= 10.1
            && $pallecon >= 9.0 && $pallecon <= 11.0
            && $bucketFiller >= 9.9 && $bucketFiller <= 10.1;

        if (! $passed && trim((string) $data['wm013_deviation_reason']) === '') {
            $this->addError('wm013_deviation_reason', 'Provide reason/action when any reading is outside tolerance.');

            return;
        }

        ProductionScaleCalibration::query()->create([
            'checked_date' => $data['wm013_date'],
            'powder_3kg_reading' => $powder3kg,
            'powder_30kg_reading' => $powder30kg,
            'pallecon_scale_reading' => $pallecon,
            'bucket_filler_scale_reading' => $bucketFiller,
            'passed' => $passed,
            'operator_name' => trim((string) $data['wm013_operator_name']),
            'deviation_reason' => trim((string) $data['wm013_deviation_reason']) !== '' ? trim((string) $data['wm013_deviation_reason']) : null,
            'checked_by_user_id' => auth()->id(),
        ]);

        $this->wm013_powder_3kg = '';
        $this->wm013_powder_30kg = '';
        $this->wm013_pallecon = '';
        $this->wm013_bucket_filler = '';
        $this->wm013_deviation_reason = '';
        $this->flashLevel = 'success';
        $this->flash = 'WM013 entry saved.';
        unset($this->todayStatus);
    }
}; ?>

<div class="py-8">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @php
            $checkCount = collect($this->todayStatus)->count();
            $completedTodayCount = collect($this->todayStatus)->filter()->count();
            $statusBadge = fn (string $label, bool $done) => '<span style="display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;border:1px solid '.($done ? '#86efac' : '#cbd5e1').';background:'.($done ? '#ecfdf5' : '#f1f5f9').';color:'.($done ? '#15803d' : '#475569').';font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;">'.$label.': '.($done ? 'Done' : 'Pending').'</span>';
        @endphp

        <div style="background:#fff;border:1px solid #dbe1ea;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,0.08);">
            <div style="padding:24px 26px;background:linear-gradient(135deg,#f8fafc 0%,#e0ecff 100%);border-bottom:1px solid #dbe1ea;">
                <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
                    <div style="width:56px;height:56px;background:#ecfdf5;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid #86efac;overflow:hidden;flex-shrink:0;">
                        <img src="{{ asset('calibration-icon.png') }}" alt="Daily Calibrations" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" />
                    </div>

                    <div>
                        <div style="font-size:1.3rem;font-weight:900;color:#1a1a2e;letter-spacing:-0.02em;line-height:1;">DAILY CALIBRATIONS</div>
                        <div style="font-size:0.75rem;font-weight:700;color:#64748b;letter-spacing:.14em;margin-top:4px;">WM001 &middot; WM002 &middot; WM006 &middot; WM013 TOLERANCE CHECKS</div>
                    </div>

                    <span style="margin-left:auto;display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;font-size:12px;font-weight:800;">
                        {{ $completedTodayCount }} of {{ $checkCount }} completed today
                    </span>
                </div>

                <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
                    {!! $statusBadge('WM001', $this->todayStatus['wm001']) !!}
                    {!! $statusBadge('WM002', $this->todayStatus['wm002']) !!}
                    {!! $statusBadge('WM006', $this->todayStatus['wm006']) !!}
                    {!! $statusBadge('WM013', $this->todayStatus['wm013']) !!}
                </div>
            </div>

            <div style="padding:24px 26px;">
                @if ($flash)
                    <div @class([
                        'rounded-lg border px-4 py-3 text-sm mb-6',
                        'border-green-200 bg-green-50 text-green-800' => $flashLevel === 'success',
                        'border-red-200 bg-red-50 text-red-800' => $flashLevel === 'error',
                    ])>{{ $flash }}</div>
                @endif

        <div class="grid gap-6 lg:grid-cols-2">
            <form wire:submit="saveWm001" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div style="background:#2d3f8f;" class="px-5 py-3 text-white font-semibold">WM001 Lab Scales Daily Calibration</div>
                <div class="p-5 space-y-3">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div><label class="block text-xs text-gray-600 mb-1">Date</label><input type="date" wire:model.defer="wm001_date" class="w-full rounded-md border-gray-300 text-sm" /></div>
                        <div><label class="block text-xs text-gray-600 mb-1">Operator name</label><input wire:model.defer="wm001_operator_name" class="w-full rounded-md border-gray-300 text-sm" /></div>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Reading (target 100 +/- 0.02)</label>
                        <input type="number" step="0.001" wire:model.defer="wm001_reading" class="w-full rounded-md border-gray-300 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Deviation reason / action (required when out of tolerance)</label>
                        <input wire:model.defer="wm001_deviation_reason" class="w-full rounded-md border-gray-300 text-sm" />
                        @error('wm001_deviation_reason')<div class="text-xs text-red-600 mt-1">{{ $message }}</div>@enderror
                    </div>
                    <x-primary-button type="submit">Save WM001</x-primary-button>
                </div>
            </form>

            <form wire:submit="saveWm002" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div style="background:#2d3f8f;" class="px-5 py-3 text-white font-semibold">WM002 Daily Salt Meter Calibration</div>
                <div class="p-5 space-y-3">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div><label class="block text-xs text-gray-600 mb-1">Date</label><input type="date" wire:model.defer="wm002_date" class="w-full rounded-md border-gray-300 text-sm" /></div>
                        <div><label class="block text-xs text-gray-600 mb-1">Operator name</label><input wire:model.defer="wm002_operator_name" class="w-full rounded-md border-gray-300 text-sm" /></div>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Reading mg/l (target 100 +/- 2)</label>
                        <input type="number" step="0.001" wire:model.defer="wm002_reading" class="w-full rounded-md border-gray-300 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Deviation reason / action (required when out of tolerance)</label>
                        <input wire:model.defer="wm002_deviation_reason" class="w-full rounded-md border-gray-300 text-sm" />
                        @error('wm002_deviation_reason')<div class="text-xs text-red-600 mt-1">{{ $message }}</div>@enderror
                    </div>
                    <x-primary-button type="submit">Save WM002</x-primary-button>
                </div>
            </form>

            <form wire:submit="saveWm006" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div style="background:#2d3f8f;" class="px-5 py-3 text-white font-semibold">WM006 Viscosity Meter Autozero Check Complete</div>
                <div class="p-5 space-y-3">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div><label class="block text-xs text-gray-600 mb-1">Date</label><input type="date" wire:model.defer="wm006_date" class="w-full rounded-md border-gray-300 text-sm" /></div>
                        <div><label class="block text-xs text-gray-600 mb-1">Operator name</label><input wire:model.defer="wm006_operator_name" class="w-full rounded-md border-gray-300 text-sm" /></div>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Complete</label>
                        <select wire:model.defer="wm006_complete" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="1">Yes</option>
                            <option value="0">No</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Deviation reason / action (required when not complete)</label>
                        <input wire:model.defer="wm006_deviation_reason" class="w-full rounded-md border-gray-300 text-sm" />
                        @error('wm006_deviation_reason')<div class="text-xs text-red-600 mt-1">{{ $message }}</div>@enderror
                    </div>
                    <x-primary-button type="submit">Save WM006</x-primary-button>
                </div>
            </form>

            <form wire:submit="saveWm013" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div style="background:#2d3f8f;" class="px-5 py-3 text-white font-semibold">WM013 Production Scales Daily Calibration</div>
                <div class="p-5 space-y-3">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div><label class="block text-xs text-gray-600 mb-1">Date</label><input type="date" wire:model.defer="wm013_date" class="w-full rounded-md border-gray-300 text-sm" /></div>
                        <div><label class="block text-xs text-gray-600 mb-1">Operator name</label><input wire:model.defer="wm013_operator_name" class="w-full rounded-md border-gray-300 text-sm" /></div>
                    </div>
                    <div class="grid gap-3 md:grid-cols-2">
                        <div><label class="block text-xs text-gray-600 mb-1">Powder 3kg scale (target 100 +/- 0.02)</label><input type="number" step="0.001" wire:model.defer="wm013_powder_3kg" class="w-full rounded-md border-gray-300 text-sm" /></div>
                        <div><label class="block text-xs text-gray-600 mb-1">Powder 30kg scale (target 10 +/- 0.1)</label><input type="number" step="0.001" wire:model.defer="wm013_powder_30kg" class="w-full rounded-md border-gray-300 text-sm" /></div>
                        <div><label class="block text-xs text-gray-600 mb-1">Pallecon scale (target 10 +/- 1)</label><input type="number" step="0.001" wire:model.defer="wm013_pallecon" class="w-full rounded-md border-gray-300 text-sm" /></div>
                        <div><label class="block text-xs text-gray-600 mb-1">Bucket filler scale (target 10 +/- 0.1)</label><input type="number" step="0.001" wire:model.defer="wm013_bucket_filler" class="w-full rounded-md border-gray-300 text-sm" /></div>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Deviation reason / action (required when any reading is out of tolerance)</label>
                        <input wire:model.defer="wm013_deviation_reason" class="w-full rounded-md border-gray-300 text-sm" />
                        @error('wm013_deviation_reason')<div class="text-xs text-red-600 mt-1">{{ $message }}</div>@enderror
                    </div>
                    <x-primary-button type="submit">Save WM013</x-primary-button>
                </div>
            </form>
        </div>
            </div>
        </div>
    </div>
</div>
