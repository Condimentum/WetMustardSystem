<?php

use App\Models\ViscosityMeterAutozeroCheck;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('WM006 Viscosity Meter Autozero Check')] class extends Component {
    public string $wm006_date = '';
    public bool $wm006_complete = true;
    public string $wm006_operator_name = '';
    public string $wm006_deviation_reason = '';

    public ?string $flash = null;
    public string $flashLevel = 'success';

    public function mount(): void
    {
        $this->wm006_date = now()->toDateString();
        $this->wm006_operator_name = (string) (auth()->user()?->name ?? '');
    }

    #[Computed]
    public function doneToday(): bool
    {
        return ViscosityMeterAutozeroCheck::query()->whereDate('checked_date', now()->toDateString())->exists();
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
        unset($this->doneToday);
    }
}; ?>

<div class="py-8">
    <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div style="background:#fff;border:1px solid #dbe1ea;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,0.08);">
            <div style="padding:24px 26px;background:linear-gradient(135deg,#f8fafc 0%,#e0ecff 100%);border-bottom:1px solid #dbe1ea;">
                <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
                    <div>
                        <div style="font-size:1.3rem;font-weight:900;color:#1a1a2e;letter-spacing:-0.02em;line-height:1;">WM006 VISCOSITY METER AUTOZERO CHECK</div>
                        <div style="font-size:0.75rem;font-weight:700;color:#64748b;letter-spacing:.14em;margin-top:4px;">DAILY COMPLETION CHECK</div>
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
            </div>
        </div>
    </div>
</div>
