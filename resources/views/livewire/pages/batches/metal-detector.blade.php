<?php

use App\Features\MetalDetector\RecordMetalDetectorCheckFeature;
use App\Models\BatchRecord;
use App\Models\MetalDetectorCheck;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Metal Detector')] class extends Component {
    public BatchRecord $batch;

    public string $check_type = MetalDetectorCheck::TYPE_HOURLY;
    public bool $fe10_pass = true;
    public bool $non_fe15_pass = true;
    public bool $ss20_pass = true;
    public bool $bin_locked = true;
    public bool $bin_empty = true;
    public bool $is_recheck = false;
    public string $failure_action = '';
    public string $comments = '';

    public function mount(BatchRecord $batch): void
    {
        $this->batch = $batch->load(['metalDetectorChecks.signedBy']);
    }

    public function record(): void
    {
        $validated = $this->validate([
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

        app(RecordMetalDetectorCheckFeature::class)($this->batch, $validated, auth()->user());

        $this->reset(['failure_action', 'comments', 'is_recheck']);
        $this->fe10_pass = $this->non_fe15_pass = $this->ss20_pass = true;
        $this->batch = $this->batch->fresh(['metalDetectorChecks.signedBy']);
    }
}; ?>

<div class="py-8">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Metal Detector Verification</h2>
                <p class="text-sm text-gray-500">Batch {{ $batch->batch_number }} · CCP checks (WM011)</p>
            </div>
            <a href="{{ route('batches.show', $batch) }}" wire:navigate class="text-sm text-indigo-600 hover:underline">← Batch record</a>
        </div>

        <form wire:submit="record" class="bg-white shadow-sm rounded-lg p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Check type</label>
                    <select wire:model="check_type" class="w-full border-gray-300 rounded-md shadow-sm text-sm">
                        <option value="start_of_shift">Start of shift</option>
                        <option value="hourly">Hourly</option>
                        <option value="end_of_shift">End of shift</option>
                    </select>
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

            <div class="flex flex-wrap gap-6 text-sm text-gray-700">
                <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="bin_locked" class="rounded border-gray-300"> Reject bin locked</label>
                <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="bin_empty" class="rounded border-gray-300"> Reject bin empty</label>
                <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="is_recheck" class="rounded border-gray-300"> This is a recheck</label>
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
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-4 py-3">Time</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Fe</th>
                        <th class="px-4 py-3">Non-Fe</th>
                        <th class="px-4 py-3">SS</th>
                        <th class="px-4 py-3">Result</th>
                        <th class="px-4 py-3">Signed by</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($batch->metalDetectorChecks->sortByDesc('check_time') as $check)
                        <tr>
                            <td class="px-4 py-2">{{ $check->check_time?->format('d M H:i') }}</td>
                            <td class="px-4 py-2">{{ \Illuminate\Support\Str::headline($check->check_type) }} @if ($check->is_recheck) <span class="text-xs text-gray-400">(recheck)</span> @endif</td>
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
                        <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500">No checks recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>
