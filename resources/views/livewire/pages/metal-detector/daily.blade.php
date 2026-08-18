<?php

use App\Features\MetalDetector\RecordMetalDetectorCheckFeature;
use App\Models\MetalDetectorCheck;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Daily Metal Detection')] class extends Component {
    use WithPagination;

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
            ->paginate(8);
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

    #[Computed]
    public function todayStats(): array
    {
        return [
            'total' => MetalDetectorCheck::query()->whereDate('check_time', today())->count(),
            'pass' => MetalDetectorCheck::query()->whereDate('check_time', today())->where('overall_result', MetalDetectorCheck::RESULT_PASS)->count(),
            'fail' => MetalDetectorCheck::query()->whereDate('check_time', today())->where('overall_result', MetalDetectorCheck::RESULT_FAIL)->count(),
        ];
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
        unset($this->todayChecks, $this->rejectConfirmationLocked, $this->todayStats);
    }
}; ?>

<div class="py-8">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div style="background:#fff;border:1px solid #dbe1ea;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,0.08);">
            <div style="padding:24px 26px;background:linear-gradient(135deg,#f8fafc 0%,#e0ecff 100%);border-bottom:1px solid #dbe1ea;">
                <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
                    <div style="width:56px;height:56px;background:#ecfdf5;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid #86efac;overflow:hidden;flex-shrink:0;">
                        <img src="{{ asset('metal-detector-icon.png') }}" alt="Metal Detection" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" />
                    </div>

                    <div>
                        <div style="font-size:1.3rem;font-weight:900;color:#1a1a2e;letter-spacing:-0.02em;line-height:1;">METAL DETECTION CHECK LOG</div>
                        <div style="font-size:0.75rem;font-weight:700;color:#64748b;letter-spacing:.14em;margin-top:4px;">START OF SHIFT &middot; HOURLY &middot; END OF SHIFT CHECKS</div>
                    </div>

                    <span style="margin-left:auto;display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;font-size:12px;font-weight:800;">
                        {{ $this->todayStats['total'] }} checks today
                    </span>
                </div>

                <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
                    <span style="display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;background:#ecfdf5;border:1px solid #86efac;color:#15803d;font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;">Pass: {{ $this->todayStats['pass'] }}</span>
                    <span style="display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c;font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;">Fail: {{ $this->todayStats['fail'] }}</span>
                </div>
            </div>

            <div style="padding:24px 26px;">

        @if ($flash)
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 mb-6">{{ $flash }}</div>
        @endif

        <form wire:submit="record" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between px-6 py-4" style="background:#2d3f8f;">
                <h2 class="text-lg font-semibold text-white">Record a check</h2>
                <svg class="h-6 w-6 text-white/70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 4.5A1.5 1.5 0 0 1 10.5 3h3A1.5 1.5 0 0 1 15 4.5V5a1.5 1.5 0 0 1-1.5 1.5h-3A1.5 1.5 0 0 1 9 5v-.5z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 13.5l2 2 4-4.5" />
                </svg>
            </div>

            <div class="space-y-5 p-6">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm text-slate-600">Operator</label>
                        <select wire:model="operator_id" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">- select operator -</option>
                            @foreach ($this->operators as $operator)
                                <option value="{{ $operator->id }}">{{ $operator->name }}</option>
                            @endforeach
                        </select>
                        @error('operator_id') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-slate-600">Check type</label>
                        <select wire:model="check_type" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="start_of_shift">Start of shift</option>
                            <option value="hourly">Hourly</option>
                            <option value="end_of_shift">End of shift</option>
                        </select>
                        @error('check_type') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-slate-600">Time checked</label>
                        <select wire:model="check_time_hm" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach ($this->checkTimeOptions as $timeOption)
                                <option value="{{ $timeOption }}">{{ $timeOption }}</option>
                            @endforeach
                        </select>
                        @error('check_time_hm') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    @foreach ([['fe10_pass', 'Fe 1.0mm'], ['non_fe15_pass', 'Non-Fe 1.5mm'], ['ss20_pass', 'SS 2.0mm']] as [$field, $label])
                        <div>
                            <label class="mb-1 block text-sm text-slate-600">{{ $label }}</label>
                            <select wire:model="{{ $field }}" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="1">Pass</option>
                                <option value="0">Fail</option>
                            </select>
                        </div>
                    @endforeach
                </div>

                <div class="space-y-3">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-sm text-slate-600">Reject bin locked</label>
                            <select
                                wire:model="bin_locked"
                                @disabled($this->rejectConfirmationLocked)
                                @class([
                                    'block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500',
                                    'cursor-not-allowed bg-slate-100 text-slate-500' => $this->rejectConfirmationLocked,
                                ])>
                                <option value="1">Pass</option>
                                <option value="0">Fail</option>
                            </select>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm text-slate-600">Reject bin empty</label>
                            <select
                                wire:model="bin_empty"
                                @disabled($this->rejectConfirmationLocked)
                                @class([
                                    'block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500',
                                    'cursor-not-allowed bg-slate-100 text-slate-500' => $this->rejectConfirmationLocked,
                                ])>
                                <option value="1">Pass</option>
                                <option value="0">Fail</option>
                            </select>
                        </div>

                        <div class="flex items-end pb-2">
                            <label class="inline-flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" wire:model="is_recheck" class="rounded border-slate-300"> This is a recheck</label>
                        </div>
                    </div>

                    @if ($this->rejectConfirmationLocked)
                        <div class="text-xs text-slate-500">Reject confirmation controls are locked after the first successful daily check.</div>
                    @endif
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm text-slate-600">Failure action / escalation</label>
                        <input wire:model="failure_action" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Required if any check fails" />
                        @error('failure_action') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-slate-600">Comments</label>
                        <input wire:model="comments" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                </div>

                <x-primary-button type="submit" class="!rounded-lg !px-5 !py-2.5">Record check</x-primary-button>
            </div>
        </form>

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between px-6 py-4" style="background:#2d3f8f;">
                <h3 class="text-lg font-semibold text-white">Today&apos;s Check Summary</h3>
                <svg class="h-6 w-6 text-white/70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                    <rect x="3.5" y="5" width="17" height="15" rx="2" />
                    <path stroke-linecap="round" d="M3.5 9.5h17M8 3v3M16 3v3" />
                </svg>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead>
                        <tr style="background:#2d3f8f;color:#fff;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;">
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
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($this->todayChecks as $check)
                            <tr>
                                <td class="px-4 py-2">{{ $check->check_time?->format('d M H:i') }}</td>
                                <td class="px-4 py-2">{{ \Illuminate\Support\Str::headline($check->check_type) }} @if ($check->is_recheck) <span class="text-xs text-slate-400">(recheck)</span> @endif</td>
                                <td class="px-4 py-2 text-slate-600">{{ $check->batchRecord?->batch_number ? 'Batch '.$check->batchRecord->batch_number : 'Daily register' }}</td>
                                <td class="px-4 py-2">{{ $check->fe10_pass ? '✓' : '✗' }}</td>
                                <td class="px-4 py-2">{{ $check->non_fe15_pass ? '✓' : '✗' }}</td>
                                <td class="px-4 py-2">{{ $check->ss20_pass ? '✓' : '✗' }}</td>
                                <td class="px-4 py-2">
                                    <span @class([
                                        'inline-flex rounded-full px-2 py-0.5 text-xs font-medium',
                                        'bg-green-100 text-green-800' => $check->overall_result === 'pass',
                                        'bg-red-100 text-red-800' => $check->overall_result === 'fail',
                                    ])>{{ ucfirst($check->overall_result) }}</span>
                                </td>
                                <td class="px-4 py-2 text-slate-600">{{ $check->signedBy?->name }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-8 text-center text-slate-500">No checks recorded yet today.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($this->todayChecks->hasPages())
                <div class="flex items-center justify-center gap-2 border-t border-slate-100 px-4 py-4">
                    <button
                        wire:click="previousPage"
                        type="button"
                        @disabled($this->todayChecks->onFirstPage())
                        class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 text-slate-400 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
                    </button>

                    @php
                        $current = $this->todayChecks->currentPage();
                        $last = $this->todayChecks->lastPage();
                        $window = collect([1, $current - 1, $current, $current + 1, $last])
                            ->filter(fn ($page) => $page >= 1 && $page <= $last)
                            ->unique()
                            ->sort()
                            ->values();
                    @endphp

                    @foreach ($window as $index => $page)
                        @if ($index > 0 && $page - $window[$index - 1] > 1)
                            <span class="px-1 text-sm text-slate-300">&hellip;</span>
                        @endif
                        <button
                            wire:click="gotoPage({{ $page }})"
                            type="button"
                            @class([
                                'flex h-8 w-8 items-center justify-center rounded-lg text-sm font-medium transition',
                                'bg-slate-900 text-white' => $page === $current,
                                'text-slate-600 hover:bg-slate-50' => $page !== $current,
                            ])
                        >{{ $page }}</button>
                    @endforeach

                    <button
                        wire:click="nextPage"
                        type="button"
                        @disabled(! $this->todayChecks->hasMorePages())
                        class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 text-slate-400 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
                    </button>
                </div>
            @endif
        </div>
            </div>
        </div>
    </div>
</div>

