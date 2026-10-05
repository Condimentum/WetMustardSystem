<?php

use App\Models\ProductionScaleCalibration;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('WM013 Production Scales Daily Calibration')] class extends Component {
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
        $this->wm013_date = now()->toDateString();
        $this->wm013_operator_name = (string) (auth()->user()?->name ?? '');
    }

    #[Computed]
    public function doneToday(): bool
    {
        return ProductionScaleCalibration::query()->whereDate('checked_date', now()->toDateString())->exists();
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
        unset($this->doneToday);
    }
}; ?>

<div class="py-8">
    <x-mo-workspace-styles />
    <div class="wm-page max-w-3xl mx-auto space-y-6">

        <section class="wm-card wm-card--gear-tr">
            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                <span class="wml-medal">
                    <img src="{{ asset('images/dashboard/wrench.png') }}" alt="WM013" />
                </span>
                <div>
                    <h1 class="wm-title">WM013 Production Scales Daily Calibration</h1>
                    <div class="wm-sub" style="text-transform:uppercase;letter-spacing:.1em;">Powder &middot; Pallecon &middot; Bucket Filler Scales</div>
                </div>
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
            <h2 class="wm-title" style="font-size:1.05rem;">Record readings</h2>
            <form wire:submit="saveWm013" class="space-y-3" style="margin-top:14px;">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div><label class="block text-xs font-medium text-slate-500 mb-1">Date</label><input type="date" wire:model.defer="wm013_date" class="w-full rounded-lg border-slate-300 text-sm" /></div>
                    <div><label class="block text-xs font-medium text-slate-500 mb-1">Operator name</label><input wire:model.defer="wm013_operator_name" class="w-full rounded-lg border-slate-300 text-sm" /></div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div><label class="block text-xs font-medium text-slate-500 mb-1">Powder 3kg scale (target 100 +/- 0.02)</label><input type="number" step="0.001" wire:model.defer="wm013_powder_3kg" class="w-full rounded-lg border-slate-300 text-sm" /></div>
                    <div><label class="block text-xs font-medium text-slate-500 mb-1">Powder 30kg scale (target 10 +/- 0.1)</label><input type="number" step="0.001" wire:model.defer="wm013_powder_30kg" class="w-full rounded-lg border-slate-300 text-sm" /></div>
                    <div><label class="block text-xs font-medium text-slate-500 mb-1">Pallecon scale (target 10 +/- 1)</label><input type="number" step="0.001" wire:model.defer="wm013_pallecon" class="w-full rounded-lg border-slate-300 text-sm" /></div>
                    <div><label class="block text-xs font-medium text-slate-500 mb-1">Bucket filler scale (target 10 +/- 0.1)</label><input type="number" step="0.001" wire:model.defer="wm013_bucket_filler" class="w-full rounded-lg border-slate-300 text-sm" /></div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Deviation reason / action (required when any reading is out of tolerance)</label>
                    <input wire:model.defer="wm013_deviation_reason" class="w-full rounded-lg border-slate-300 text-sm" />
                    @error('wm013_deviation_reason')<div class="text-xs text-red-600 mt-1">{{ $message }}</div>@enderror
                </div>
                <div>
                    <button type="submit" class="wm-btn-dark">Save WM013</button>
                </div>
            </form>
        </section>
    </div>
</div>
