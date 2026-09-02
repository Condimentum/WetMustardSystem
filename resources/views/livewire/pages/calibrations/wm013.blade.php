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
    <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div style="background:#fff;border:1px solid #dbe1ea;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,0.08);">
            <div style="padding:24px 26px;background:linear-gradient(135deg,#f8fafc 0%,#e0ecff 100%);border-bottom:1px solid #dbe1ea;">
                <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
                    <div>
                        <div style="font-size:1.3rem;font-weight:900;color:#1a1a2e;letter-spacing:-0.02em;line-height:1;">WM013 PRODUCTION SCALES DAILY CALIBRATION</div>
                        <div style="font-size:0.75rem;font-weight:700;color:#64748b;letter-spacing:.14em;margin-top:4px;">POWDER &middot; PALLECON &middot; BUCKET FILLER SCALES</div>
                    </div>

                    <a href="{{ route('calibrations.daily') }}" wire:navigate style="margin-left:auto;display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;font-size:12px;font-weight:800;text-decoration:none;">
                        Back to Daily Calibrations
                    </a>
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
