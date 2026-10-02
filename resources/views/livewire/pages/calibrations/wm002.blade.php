<?php

use App\Models\SaltMeterCalibration;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('WM002 Daily Salt Meter Calibration')] class extends Component {
    public string $wm002_date = '';
    public string $wm002_reading = '';
    public string $wm002_operator_name = '';
    public string $wm002_deviation_reason = '';

    public ?string $flash = null;
    public string $flashLevel = 'success';

    public function mount(): void
    {
        $this->wm002_date = now()->toDateString();
        $this->wm002_operator_name = (string) (auth()->user()?->name ?? '');
    }

    #[Computed]
    public function doneToday(): bool
    {
        return SaltMeterCalibration::query()->whereDate('checked_date', now()->toDateString())->exists();
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
        unset($this->doneToday);
    }
}; ?>

<div class="py-8">
    <x-mo-workspace-styles />
    <div class="wm-page max-w-3xl mx-auto space-y-6">

        <section class="wm-card wm-card--gear-tr">
            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                <span style="width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid #c9a24a;overflow:hidden;flex-shrink:0;background:#fffdf7;">
                    <img src="{{ asset('calibration-icon.png') }}" alt="WM002" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" />
                </span>
                <div>
                    <h1 class="wm-title">WM002 Daily Salt Meter Calibration</h1>
                    <div class="wm-sub" style="text-transform:uppercase;letter-spacing:.1em;">Target 100 &plusmn; 2 mg/l</div>
                </div>
                <a href="{{ route('calibrations.daily') }}" wire:navigate class="wm-link" style="margin-left:auto;">Back to Daily Calibrations</a>
            </div>
        </section>

        @if ($flash)
            <div @class([
                'rounded-lg border px-4 py-3 text-sm',
                'border-green-200 bg-green-50 text-green-800' => $flashLevel === 'success',
                'border-red-200 bg-red-50 text-red-800' => $flashLevel === 'error',
            ])>{{ $flash }}</div>
        @endif

        <section class="wm-card wm-card--gear-bl">
            <h2 class="wm-title" style="font-size:1.05rem;">Record reading</h2>
            <form wire:submit="saveWm002" class="grid grid-cols-1 md:grid-cols-2 gap-3" style="margin-top:14px;">
                <div><label class="block text-xs font-medium text-slate-500 mb-1">Date</label><input type="date" wire:model.defer="wm002_date" class="w-full rounded-lg border-slate-300 text-sm" /></div>
                <div><label class="block text-xs font-medium text-slate-500 mb-1">Operator name</label><input wire:model.defer="wm002_operator_name" class="w-full rounded-lg border-slate-300 text-sm" /></div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 mb-1">Reading mg/l (target 100 +/- 2)</label>
                    <input type="number" step="0.001" wire:model.defer="wm002_reading" class="w-full rounded-lg border-slate-300 text-sm" />
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 mb-1">Deviation reason / action (required when out of tolerance)</label>
                    <input wire:model.defer="wm002_deviation_reason" class="w-full rounded-lg border-slate-300 text-sm" />
                    @error('wm002_deviation_reason')<div class="text-xs text-red-600 mt-1">{{ $message }}</div>@enderror
                </div>
                <div class="md:col-span-2">
                    <button type="submit" class="wm-btn-dark">Save WM002</button>
                </div>
            </form>
        </section>
    </div>
</div>
