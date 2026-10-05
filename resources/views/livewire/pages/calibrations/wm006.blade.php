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
    <x-mo-workspace-styles />
    <div class="wm-page max-w-3xl mx-auto space-y-6">

        <section class="wm-card wm-card--gear-tr">
            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                <span class="wml-medal">
                    <img src="{{ asset('images/dashboard/wrench.png') }}" alt="WM006" />
                </span>
                <div>
                    <h1 class="wm-title">WM006 Viscosity Meter Autozero Check</h1>
                    <div class="wm-sub" style="text-transform:uppercase;letter-spacing:.1em;">Daily completion check</div>
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
            <h2 class="wm-title" style="font-size:1.05rem;">Record check</h2>
            <form wire:submit="saveWm006" class="grid grid-cols-1 md:grid-cols-2 gap-3" style="margin-top:14px;">
                <div><label class="block text-xs font-medium text-slate-500 mb-1">Date</label><input type="date" wire:model.defer="wm006_date" class="w-full rounded-lg border-slate-300 text-sm" /></div>
                <div><label class="block text-xs font-medium text-slate-500 mb-1">Operator name</label><input wire:model.defer="wm006_operator_name" class="w-full rounded-lg border-slate-300 text-sm" /></div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 mb-1">Complete</label>
                    <select wire:model.defer="wm006_complete" class="w-full rounded-lg border-slate-300 text-sm">
                        <option value="1">Yes</option>
                        <option value="0">No</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 mb-1">Deviation reason / action (required when not complete)</label>
                    <input wire:model.defer="wm006_deviation_reason" class="w-full rounded-lg border-slate-300 text-sm" />
                    @error('wm006_deviation_reason')<div class="text-xs text-red-600 mt-1">{{ $message }}</div>@enderror
                </div>
                <div class="md:col-span-2">
                    <button type="submit" class="wm-btn-dark">Save WM006</button>
                </div>
            </form>
        </section>
    </div>
</div>
