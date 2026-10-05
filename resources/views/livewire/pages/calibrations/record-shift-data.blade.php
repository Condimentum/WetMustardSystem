<?php

use App\Domains\FactoryPerformance\Jobs\FetchDowntimeEventsJob;
use App\Domains\FactoryPerformance\Jobs\FetchDowntimeReasonsJob;
use App\Domains\FactoryPerformance\Jobs\FetchDowntimeSubmissionJob;
use App\Domains\FactoryPerformance\Jobs\FetchShiftDayJob;
use App\Domains\FactoryPerformance\Jobs\FetchTeamLeadersJob;
use App\Domains\FactoryPerformance\Jobs\SetDowntimeSubmittedJob;
use App\Features\FactoryPerformance\RemoveShiftDayFeature;
use App\Features\FactoryPerformance\SaveDowntimeEventsFeature;
use App\Features\FactoryPerformance\SaveShiftDayFeature;
use App\Features\FactoryPerformance\UnlockShiftDayFeature;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Record Shift Data')] class extends Component {
    public array $shiftForm = [
        'is_working_day' => true,
        'shift_start' => '',
        'production_start' => '',
        'shift_finish' => '',
        'production_finish' => '',
        'recorded_by' => '',
        'shift_end_recorded_by' => '',
        'packing_completed' => false,
        'packing_hours' => '',
    ];

    public bool $shiftSubmitted = false;

    public array $downtimeEvents = [];

    public bool $downtimeSubmitted = false;

    public string $shiftFlash = '';

    public function mount(): void
    {
        $departmentId = (int) config('fpt.manufacturing_department_id');
        $date = now()->toDateString();

        $row = app(FetchShiftDayJob::class)($departmentId, $date);

        if ($row) {
            $this->shiftForm = [
                'is_working_day' => (bool) $row->IsWorkingDay,
                'shift_start' => $this->toHoursMinutes($row->ShiftStartTime),
                'production_start' => $this->toHoursMinutes($row->ProductionStartTime),
                'shift_finish' => $this->toHoursMinutes($row->ShiftFinishTime),
                'production_finish' => $this->toHoursMinutes($row->ProductionFinishTime),
                'recorded_by' => (string) $row->RecordedBy,
                'shift_end_recorded_by' => (string) $row->ShiftEndRecordedBy,
                'packing_completed' => (bool) $row->PackingCompleted,
                'packing_hours' => $row->PackingHours !== null ? (string) $row->PackingHours : '',
            ];
            $this->shiftSubmitted = (bool) $row->IsSubmitted;
        }

        $this->downtimeEvents = app(FetchDowntimeEventsJob::class)($departmentId, $date)
            ->map(fn ($event) => [
                'reason_id' => $event->ReasonId !== null ? (string) $event->ReasonId : '',
                'start_time' => $this->toHoursMinutes($event->StartTime),
                'end_time' => $this->toHoursMinutes($event->EndTime),
                'comment' => (string) $event->Comment,
            ])
            ->all();

        $this->downtimeSubmitted = app(FetchDowntimeSubmissionJob::class)($departmentId, $date);
    }

    private function toHoursMinutes(?string $time): string
    {
        return $time ? substr($time, 0, 5) : '';
    }

    #[Computed]
    public function teamLeaderOptions()
    {
        return app(FetchTeamLeadersJob::class)((int) config('fpt.manufacturing_department_id'));
    }

    #[Computed]
    public function downtimeReasonOptions()
    {
        return app(FetchDowntimeReasonsJob::class)((int) config('fpt.manufacturing_department_id'));
    }

    public function saveShift(bool $submit = false): void
    {
        $departmentId = (int) config('fpt.manufacturing_department_id');
        $date = now()->toDateString();

        $status = app(SaveShiftDayFeature::class)(
            $departmentId,
            $date,
            (bool) $this->shiftForm['is_working_day'],
            $this->shiftForm['shift_start'] ?: null,
            $this->shiftForm['production_start'] ?: null,
            $this->shiftForm['shift_finish'] ?: null,
            $this->shiftForm['production_finish'] ?: null,
            trim((string) $this->shiftForm['recorded_by']),
            $this->shiftForm['shift_end_recorded_by'] ?: null,
            (bool) $this->shiftForm['packing_completed'],
            $this->shiftForm['packing_hours'] !== '' ? (float) $this->shiftForm['packing_hours'] : null,
            $submit,
            auth()->user(),
        );

        $this->shiftSubmitted = $status === 'submitted';
        $this->shiftFlash = match ($status) {
            'cleared' => 'Cleared - no shift data recorded for today.',
            'submitted' => 'Shift submitted and locked.',
            default => 'Shift saved.',
        };
    }

    public function removeShift(): void
    {
        $departmentId = (int) config('fpt.manufacturing_department_id');
        $date = now()->toDateString();

        app(RemoveShiftDayFeature::class)($departmentId, $date, auth()->user());

        $this->shiftForm = [
            'is_working_day' => true,
            'shift_start' => '',
            'production_start' => '',
            'shift_finish' => '',
            'production_finish' => '',
            'recorded_by' => '',
            'shift_end_recorded_by' => '',
            'packing_completed' => false,
            'packing_hours' => '',
        ];
        $this->shiftSubmitted = false;
        $this->downtimeEvents = [];
        $this->downtimeSubmitted = false;
        $this->shiftFlash = 'Removed.';
    }

    public function unlockShift(): void
    {
        $departmentId = (int) config('fpt.manufacturing_department_id');
        app(UnlockShiftDayFeature::class)($departmentId, now()->toDateString(), auth()->user());
        $this->shiftSubmitted = false;
    }

    public function addDowntimeRow(): void
    {
        $this->downtimeEvents[] = ['reason_id' => '', 'start_time' => '', 'end_time' => '', 'comment' => ''];
    }

    public function removeDowntimeRow(int $index): void
    {
        unset($this->downtimeEvents[$index]);
        $this->downtimeEvents = array_values($this->downtimeEvents);
    }

    public function saveDowntime(bool $submit = false): void
    {
        $departmentId = (int) config('fpt.manufacturing_department_id');
        $recordedBy = trim((string) $this->shiftForm['recorded_by']);

        app(SaveDowntimeEventsFeature::class)($departmentId, now()->toDateString(), $recordedBy, $this->downtimeEvents, $submit);

        $this->downtimeSubmitted = $submit;
        $this->shiftFlash = $submit ? 'Downtime submitted and locked.' : 'Downtime draft saved.';
    }

    public function unlockDowntime(): void
    {
        $departmentId = (int) config('fpt.manufacturing_department_id');
        app(SetDowntimeSubmittedJob::class)($departmentId, now()->toDateString(), false);
        $this->downtimeSubmitted = false;
    }
}; ?>

<div class="py-8">
    <x-mo-workspace-styles />
    <div class="wm-page max-w-5xl mx-auto space-y-6">

        <section class="wm-card wm-card--gear-tr">
            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                <span class="wml-medal">
                    <img src="{{ asset('images/dashboard/wrench.png') }}" alt="Record Shift Data" />
                </span>
                <div>
                    <h1 class="wm-title">Record Shift Data</h1>
                    <div class="wm-sub" style="text-transform:uppercase;letter-spacing:.1em;">Wet Mustard - Manufacturing &middot; {{ now()->format('l, j M Y') }}</div>
                </div>
            </div>
            <div class="wm-sub" style="margin-top:10px;">Shared live with Factory Performance Tracker &mdash; data saved here appears there too, and vice versa.</div>
        </section>

        @if ($shiftFlash)
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ $shiftFlash }}</div>
        @endif

        <section class="wm-card wm-card--gear-bl">
            @if ($shiftSubmitted)
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 mb-4 flex items-center justify-between gap-3 flex-wrap">
                    <span>Submitted and locked.</span>
                    <button type="button" wire:click="unlockShift" class="wm-btn-dark">Unlock</button>
                </div>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div class="md:col-span-4 flex items-center gap-2">
                    <input type="checkbox" wire:model.live="shiftForm.is_working_day" id="is_working_day" @disabled($shiftSubmitted) class="rounded border-slate-300" />
                    <label for="is_working_day" class="text-sm font-medium text-slate-700">Working day</label>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Shift Start *</label>
                    <input type="time" wire:model.defer="shiftForm.shift_start" @disabled($shiftSubmitted) class="w-full rounded-lg border-slate-300 text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Production Start</label>
                    <input type="time" wire:model.defer="shiftForm.production_start" @disabled($shiftSubmitted) class="w-full rounded-lg border-slate-300 text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Production Finish</label>
                    <input type="time" wire:model.defer="shiftForm.production_finish" @disabled($shiftSubmitted) class="w-full rounded-lg border-slate-300 text-sm" />
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Shift Finish *</label>
                    <input type="time" wire:model.defer="shiftForm.shift_finish" @disabled($shiftSubmitted) class="w-full rounded-lg border-slate-300 text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Start By *</label>
                    <select wire:model.defer="shiftForm.recorded_by" @disabled($shiftSubmitted) class="w-full rounded-lg border-slate-300 text-sm">
                        <option value="">- select -</option>
                        @foreach ($this->teamLeaderOptions as $leader)
                            <option value="{{ $leader->TeamLeaderName }}">{{ $leader->TeamLeaderName }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">End By *</label>
                    <select wire:model.defer="shiftForm.shift_end_recorded_by" @disabled($shiftSubmitted) class="w-full rounded-lg border-slate-300 text-sm">
                        <option value="">- select -</option>
                        @foreach ($this->teamLeaderOptions as $leader)
                            <option value="{{ $leader->TeamLeaderName }}">{{ $leader->TeamLeaderName }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="md:col-span-4 flex items-center gap-3 flex-wrap">
                    <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input type="checkbox" wire:model.live="shiftForm.packing_completed" @disabled($shiftSubmitted) class="rounded border-slate-300" /> Packing
                    </label>
                    @if ($shiftForm['packing_completed'])
                        <input type="number" step="0.25" min="0" placeholder="Hrs" wire:model.defer="shiftForm.packing_hours" @disabled($shiftSubmitted) class="w-24 rounded-lg border-slate-300 text-sm" />
                    @endif
                </div>
            </div>

            @unless ($shiftSubmitted)
                <div class="flex flex-wrap gap-2 mt-4">
                    <button type="button" wire:click="saveShift" class="wm-btn-dark">Save</button>
                    <button type="button" wire:click="saveShift(true)" class="wm-btn-dark">Submit</button>
                    <button type="button" wire:click="removeShift" wire:confirm="Remove today's shift record? This also clears its downtime events." class="wm-btn-dark" style="background:linear-gradient(180deg,#8a271b,#5c1710);">Remove</button>
                </div>
            @endunless

            <div class="mt-8 pt-6" style="border-top:1px solid #e6dcc5;">
                <div class="flex items-center justify-between flex-wrap gap-2 mb-3">
                    <h3 class="wm-title" style="font-size:.95rem;">Downtime Events</h3>
                    @unless ($downtimeSubmitted)
                        <button type="button" wire:click="addDowntimeRow" class="wm-btn-dark" style="padding:8px 14px;">+ Add Event</button>
                    @endunless
                </div>

                @if ($downtimeSubmitted)
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 mb-4 flex items-center justify-between gap-3 flex-wrap">
                        <span>Downtime submitted and locked.</span>
                        <button type="button" wire:click="unlockDowntime" class="wm-btn-dark">Unlock</button>
                    </div>
                @endif

                @if (empty($downtimeEvents))
                    <div class="text-sm text-slate-500">No downtime logged for today.</div>
                @else
                    <div class="space-y-3">
                        @foreach ($downtimeEvents as $index => $event)
                            <div class="grid grid-cols-1 md:grid-cols-5 gap-2 items-end">
                                <div class="md:col-span-2">
                                    <label class="block text-xs font-medium text-slate-500 mb-1">Reason</label>
                                    <select wire:model.defer="downtimeEvents.{{ $index }}.reason_id" @disabled($downtimeSubmitted) class="w-full rounded-lg border-slate-300 text-sm">
                                        <option value="">-- Select reason --</option>
                                        @foreach ($this->downtimeReasonOptions as $reason)
                                            <option value="{{ $reason->ReasonId }}">{{ $reason->ReasonName }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 mb-1">Start</label>
                                    <input type="time" wire:model.defer="downtimeEvents.{{ $index }}.start_time" @disabled($downtimeSubmitted) class="w-full rounded-lg border-slate-300 text-sm" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-500 mb-1">End</label>
                                    <input type="time" wire:model.defer="downtimeEvents.{{ $index }}.end_time" @disabled($downtimeSubmitted) class="w-full rounded-lg border-slate-300 text-sm" />
                                </div>
                                <div class="flex items-end gap-2">
                                    <div class="flex-1">
                                        <label class="block text-xs font-medium text-slate-500 mb-1">Comment</label>
                                        <input type="text" placeholder="Optional comment" wire:model.defer="downtimeEvents.{{ $index }}.comment" @disabled($downtimeSubmitted) class="w-full rounded-lg border-slate-300 text-sm" />
                                    </div>
                                    @unless ($downtimeSubmitted)
                                        <button type="button" wire:click="removeDowntimeRow({{ $index }})" class="text-red-600 hover:text-red-800 px-2 text-lg leading-none" title="Remove">&times;</button>
                                    @endunless
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @unless ($downtimeSubmitted)
                    <div class="flex flex-wrap gap-2 mt-4">
                        <button type="button" wire:click="saveDowntime" class="wm-btn-dark">Save Downtime</button>
                        <button type="button" wire:click="saveDowntime(true)" class="wm-btn-dark">Submit Downtime</button>
                    </div>
                @endunless
            </div>
        </section>
    </div>
</div>
