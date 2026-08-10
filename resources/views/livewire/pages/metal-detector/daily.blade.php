<?php

use App\Features\MetalDetector\RecordMetalDetectorCheckFeature;
use App\Models\MetalDetectorCheck;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Daily Metal Detection')] class extends Component {
    public ?int $operator_id = null;
    public string $check_time_hm = '';

    public string $check_type = MetalDetectorCheck::TYPE_HOURLY;
    public bool $fe10_pass = true;
    public bool $non_fe15_pass = true;
    public bool $ss20_pass = true;
    public bool $bin_locked = true;
    public bool $bin_empty = true;
    public bool $is_recheck = false;
    public string $failure_action = '';
    public string $comments = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->operator_id = auth()->id();
        $this->check_time_hm = now()->format('H:i');
    }

    #[Computed]
    public function operators()
    {
        return User::query()
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function checkTimeOptions(): array
    {
        $options = [];
        for ($hour = 0; $hour < 24; $hour++) {
            for ($minute = 0; $minute < 60; $minute++) {
                $options[] = sprintf('%02d:%02d', $hour, $minute);
            }
        }

        return $options;
    }

    #[Computed]
    public function todayChecks()
    {
        return MetalDetectorCheck::query()
            ->with(['signedBy', 'batchRecord'])
            ->whereDate('check_time', today())
            ->orderByDesc('check_time')
            ->get();
    }

    #[Computed]
    public function rejectConfirmationLocked(): bool
    {
        return MetalDetectorCheck::query()
            ->whereNull('batch_record_id')
            ->whereDate('check_time', today())
            ->where('overall_result', MetalDetectorCheck::RESULT_PASS)
            ->exists();
    }

    public function record(): void
    {
        $this->flash = null;

        $validated = $this->validate([
            'operator_id' => ['required', 'integer', 'exists:users,id'],
            'check_time_hm' => ['required', 'date_format:H:i'],
            'check_type' => ['required', 'in:start_of_shift,hourly,end_of_shift'],
            'fe10_pass' => ['boolean'],
            'non_fe15_pass' => ['boolean'],
            'ss20_pass' => ['boolean'],
            'bin_locked' => ['boolean'],
            'bin_empty' => ['boolean'],
            'is_recheck' => ['boolean'],
            'failure_action' => ['nullable', 'string', 'max:500'],
            'comments' => ['nullable', 'string', 'max:500'],
        ]);

        $allPass = $this->fe10_pass && $this->non_fe15_pass && $this->ss20_pass;
        if (! $allPass && trim($this->failure_action) === '') {
            $this->addError('failure_action', 'A failure action / escalation note is required when a check fails.');

            return;
        }

        if ($this->rejectConfirmationLocked) {
            // Freeze reject confirmation once a successful daily check exists.
            $validated['bin_locked'] = true;
            $validated['bin_empty'] = true;
            $this->bin_locked = true;
            $this->bin_empty = true;
        }

        $validated['check_time'] = now()->format('Y-m-d').' '.$validated['check_time_hm'].':00';

        if ($this->check_type === MetalDetectorCheck::TYPE_START) {
            $alreadyRecorded = MetalDetectorCheck::query()
                ->whereNull('batch_record_id')
                ->where('check_type', MetalDetectorCheck::TYPE_START)
                ->whereDate('check_time', today())
                ->exists();

            if ($alreadyRecorded) {
                $this->addError('check_type', 'Start-of-shift check has already been recorded for today.');

                return;
            }
        }

        $operator = User::query()->find((int) $validated['operator_id']);
        if (! $operator) {
            $this->addError('operator_id', 'Selected operator is no longer available.');

            return;
        }

        app(RecordMetalDetectorCheckFeature::class)(null, $validated, $operator);

        $this->reset(['failure_action', 'comments', 'is_recheck']);
        $this->fe10_pass = $this->non_fe15_pass = $this->ss20_pass = true;
        $this->flash = 'Check recorded.';
        unset($this->todayChecks, $this->rejectConfirmationLocked);
    }
}; ?>

<div class="py-8">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Daily Metal Detector Verification</h2>
                <p class="text-sm text-gray-500">Standalone daily CCP register for start-of-shift, hourly, and end-of-shift checks.</p>
            </div>
            <div class="flex items-center gap-4">
                <a href="{{ route('metal-detector.daily.paperwork', ['date' => today()->toDateString()]) }}" class="text-sm text-indigo-600 hover:underline">Generate daily paperwork</a>
                <a href="{{ route('dashboard') }}" wire:navigate class="text-sm text-indigo-600 hover:underline">← Dashboard</a>
            </div>
        </div>

        @if ($flash)
            <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-lg px-4 py-3">{{ $flash }}</div>
        @endif

        <form wire:submit="record" class="bg-white shadow-sm rounded-lg p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Operator</label>
                    <select wire:model="operator_id" class="w-full border-gray-300 rounded-md shadow-sm text-sm">
                        <option value="">- select operator -</option>
                        @foreach ($this->operators as $operator)
                            <option value="{{ $operator->id }}">{{ $operator->name }}</option>
                        @endforeach
                    </select>
                    @error('operator_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Check type</label>
                    <select wire:model="check_type" class="w-full border-gray-300 rounded-md shadow-sm text-sm">
                        <option value="start_of_shift">Start of shift</option>
                        <option value="hourly">Hourly</option>
                        <option value="end_of_shift">End of shift</option>
                    </select>
                    @error('check_type') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Time checked</label>
                    <select wire:model="check_time_hm" class="w-full border-gray-300 rounded-md shadow-sm text-sm">
                        @foreach ($this->checkTimeOptions as $timeOption)
                            <option value="{{ $timeOption }}">{{ $timeOption }}</option>
                        @endforeach
                    </select>
                    @error('check_time_hm') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>
                @foreach ([['fe10_pass', 'Fe 1.0mm'], ['non_fe15_pass', 'Non-Fe 1.5mm'], ['ss20_pass', 'SS 2.0mm']] as [$field, $label])
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">{{ $label }}</label>
                        <select wire:model="{{ $field }}" class="w-full border-gray-300 rounded-md shadow-sm text-sm">
                            <option value="1">Pass</option>
                            <option value="0">Fail</option>
                        </select>
                    </div>
                @endforeach
            </div>

            <div class="space-y-3">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-600">Reject Confirmation Working</div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Reject bin locked</label>
                        <select
                            wire:model="bin_locked"
                            @disabled($this->rejectConfirmationLocked)
                            @class([
                                'w-full border-gray-300 rounded-md shadow-sm text-sm',
                                'bg-gray-100 text-gray-500 cursor-not-allowed' => $this->rejectConfirmationLocked,
                            ])>
                            <option value="1">Pass</option>
                            <option value="0">Fail</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Reject bin empty</label>
                        <select
                            wire:model="bin_empty"
                            @disabled($this->rejectConfirmationLocked)
                            @class([
                                'w-full border-gray-300 rounded-md shadow-sm text-sm',
                                'bg-gray-100 text-gray-500 cursor-not-allowed' => $this->rejectConfirmationLocked,
                            ])>
                            <option value="1">Pass</option>
                            <option value="0">Fail</option>
                        </select>
                    </div>

                    <div class="flex items-end pb-2">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="is_recheck" class="rounded border-gray-300"> This is a recheck</label>
                    </div>
                </div>

                @if ($this->rejectConfirmationLocked)
                    <div class="text-xs text-gray-500">Reject confirmation controls are locked after the first successful daily check.</div>
                @endif
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Failure action / escalation</label>
                    <input wire:model="failure_action" class="w-full border-gray-300 rounded-md shadow-sm text-sm" placeholder="Required if any check fails" />
                    @error('failure_action') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Comments</label>
                    <input wire:model="comments" class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
                </div>
            </div>

            <x-primary-button type="submit">Record check</x-primary-button>
        </form>

        <div class="bg-white shadow-sm rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-sm font-medium text-gray-900">Today&apos;s checks</h3>
                <div class="text-sm text-gray-500">{{ $this->todayChecks->count() }} recorded</div>
            </div>

            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-3">Time</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Context</th>
                        <th class="px-4 py-3">Fe</th>
                        <th class="px-4 py-3">Non-Fe</th>
                        <th class="px-4 py-3">SS</th>
                        <th class="px-4 py-3">Result</th>
                        <th class="px-4 py-3">Signed by</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($this->todayChecks as $check)
                        <tr>
                            <td class="px-4 py-2">{{ $check->check_time?->format('d M H:i') }}</td>
                            <td class="px-4 py-2">{{ \Illuminate\Support\Str::headline($check->check_type) }} @if ($check->is_recheck) <span class="text-xs text-gray-400">(recheck)</span> @endif</td>
                            <td class="px-4 py-2 text-gray-600">{{ $check->batchRecord?->batch_number ? 'Batch '.$check->batchRecord->batch_number : 'Daily register' }}</td>
                            <td class="px-4 py-2">{{ $check->fe10_pass ? '✓' : '✗' }}</td>
                            <td class="px-4 py-2">{{ $check->non_fe15_pass ? '✓' : '✗' }}</td>
                            <td class="px-4 py-2">{{ $check->ss20_pass ? '✓' : '✗' }}</td>
                            <td class="px-4 py-2">
                                <span @class([
                                    'inline-flex px-2 py-0.5 rounded-full text-xs font-medium',
                                    'bg-green-100 text-green-800' => $check->overall_result === 'pass',
                                    'bg-red-100 text-red-800' => $check->overall_result === 'fail',
                                ])>{{ ucfirst($check->overall_result) }}</span>
                            </td>
                            <td class="px-4 py-2 text-gray-600">{{ $check->signedBy?->name }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-8 text-center text-gray-500">No checks recorded yet today.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
