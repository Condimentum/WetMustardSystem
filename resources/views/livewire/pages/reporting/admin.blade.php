<?php

use App\Features\Reporting\SendReportNowFeature;
use App\Models\ReportConfig;
use App\Models\ReportRecipient;
use App\Models\ReportSendLog;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Spatie\Permission\Models\Role;

new #[Layout('layouts.app')] #[Title('Reporting Admin')] class extends Component {
    public string $dateFrom = '';
    public string $dateTo = '';

    public ?int $editingId = null;
    public string $editScheduleTime = '';
    public int $editFromDays = -1;
    public int $editToDays = -1;

    /** @var array<string, mixed> */
    public array $recipient = [
        'report_key' => '',
        'recipient_type' => 'direct',
        'recipient_email' => '',
        'recipient_name' => '',
        'role_key' => '',
        'is_cc' => false,
    ];

    public ?string $flash = null;

    public string $flashLevel = 'success';

    public function mount(): void
    {
        $this->dateFrom = now()->subDay()->toDateString();
        $this->dateTo = now()->subDay()->toDateString();
    }

    #[Computed]
    public function configs()
    {
        return ReportConfig::query()->orderBy('report_name')->get();
    }

    #[Computed]
    public function recipients()
    {
        return ReportRecipient::query()->orderBy('report_key')->get();
    }

    #[Computed]
    public function sendLogs()
    {
        return ReportSendLog::query()->latest('id')->limit(20)->get();
    }

    #[Computed]
    public function roles(): array
    {
        return Role::query()->pluck('name')->all();
    }

    public function toggleEnabled(int $configId): void
    {
        $config = ReportConfig::findOrFail($configId);
        $config->update(['enabled' => ! $config->enabled, 'updated_by' => auth()->id()]);
    }

    public function edit(int $configId): void
    {
        $config = ReportConfig::findOrFail($configId);
        $this->editingId = $config->id;
        $this->editScheduleTime = (string) $config->schedule_time;
        $this->editFromDays = $config->date_offset_from_days;
        $this->editToDays = $config->date_offset_to_days;
    }

    public function saveEdit(): void
    {
        $config = ReportConfig::findOrFail($this->editingId);
        $config->update([
            'schedule_time' => $this->editScheduleTime ?: null,
            'date_offset_from_days' => $this->editFromDays,
            'date_offset_to_days' => $this->editToDays,
            'updated_by' => auth()->id(),
        ]);
        $this->editingId = null;
        $this->flashLevel = 'success';
        $this->flash = 'Schedule updated.';
    }

    public function sendNow(string $reportKey): void
    {
        $config = ReportConfig::query()
            ->where('report_key', $reportKey)
            ->first(['date_offset_from_days', 'date_offset_to_days']);

        $baseDate = now();
        $log = app(SendReportNowFeature::class)(
            $reportKey,
            $config !== null
                ? $baseDate->copy()->addDays((int) $config->date_offset_from_days)
                : Carbon::parse($this->dateFrom),
            $config !== null
                ? $baseDate->copy()->addDays((int) $config->date_offset_to_days)
                : Carbon::parse($this->dateTo),
            auth()->user(),
        );

        $status = (string) $log->status;
        $this->flashLevel = match ($status) {
            'sent' => 'success',
            'skipped' => 'warning',
            default => 'error',
        };

        $suffix = $log->error_message ? ' '.$log->error_message : '';
        $fromUsed = $log->date_from?->toDateString() ?? '—';
        $toUsed = $log->date_to?->toDateString() ?? '—';
        $this->flash = "Send Now for {$reportKey}: {$status} ({$log->row_count} rows) [{$fromUsed} to {$toUsed}].{$suffix}";
    }

    public function addRecipient(): void
    {
        $data = $this->validate([
            'recipient.report_key' => ['nullable', 'string'],
            'recipient.recipient_type' => ['required', 'in:direct,role'],
            'recipient.recipient_email' => ['nullable', 'email', 'required_if:recipient.recipient_type,direct'],
            'recipient.recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient.role_key' => ['nullable', 'string', 'required_if:recipient.recipient_type,role'],
            'recipient.is_cc' => ['boolean'],
        ])['recipient'];

        ReportRecipient::create([
            'report_key' => $data['report_key'] ?: null,
            'recipient_type' => $data['recipient_type'],
            'recipient_email' => $data['recipient_email'] ?: null,
            'recipient_name' => $data['recipient_name'] ?: null,
            'role_key' => $data['role_key'] ?: null,
            'is_cc' => (bool) $data['is_cc'],
            'enabled' => true,
        ]);

        $this->recipient = ['report_key' => '', 'recipient_type' => 'direct', 'recipient_email' => '', 'recipient_name' => '', 'role_key' => '', 'is_cc' => false];
        $this->flashLevel = 'success';
        $this->flash = 'Recipient added.';
    }

    public function removeRecipient(int $id): void
    {
        ReportRecipient::whereKey($id)->delete();
    }
}; ?>

<div class="py-8">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <h2 class="text-xl font-semibold text-gray-800">Reporting Admin</h2>

        <div class="rounded-lg border border-slate-200 bg-white p-2 shadow-sm">
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('settings.admin') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.admin') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">General</a>
                <a href="{{ route('settings.recipes') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.recipes') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Recipes</a>
                <a href="{{ route('settings.product-mapping') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.product-mapping') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Product Mapping</a>
                <a href="{{ route('settings.operator-sync') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.operator-sync') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Operator Sync</a>
                <a href="{{ route('settings.documents') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.documents') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Documents</a>
                <a href="{{ route('reporting.admin') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('reporting.*') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Reporting</a>
                <a href="{{ route('notifications.admin') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('notifications.*') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Notifications</a>
                <a href="{{ route('audit.index') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('audit.*') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Audit</a>
            </div>
        </div>

        @if ($flash)
            <div @class([
                'text-sm rounded-lg px-4 py-3 border',
                'bg-green-50 border-green-200 text-green-800' => $flashLevel === 'success',
                'bg-amber-50 border-amber-200 text-amber-800' => $flashLevel === 'warning',
                'bg-red-50 border-red-200 text-red-800' => $flashLevel === 'error',
            ])>{{ $flash }}</div>
        @endif

        {{-- Send Now range --}}
        <div class="bg-white shadow-sm rounded-lg p-4 flex flex-wrap items-end gap-3">
            <div><label class="block text-xs text-gray-600 mb-1">Send Now — from</label><input type="date" wire:model="dateFrom" class="border-gray-300 rounded-md shadow-sm text-sm" /></div>
            <div><label class="block text-xs text-gray-600 mb-1">to</label><input type="date" wire:model="dateTo" class="border-gray-300 rounded-md shadow-sm text-sm" /></div>
            <span class="text-xs text-gray-400">Preview-only range. Row Send now uses each report's configured offsets.</span>
        </div>

        {{-- Reports --}}
        <div class="bg-white shadow-sm rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500 uppercase">
                    <tr><th class="px-4 py-3">Report</th><th class="px-4 py-3">Schedule</th><th class="px-4 py-3">Offsets</th><th class="px-4 py-3">Enabled</th><th class="px-4 py-3"></th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($this->configs as $config)
                        <tr>
                            <td class="px-4 py-2"><div class="font-medium text-gray-800">{{ $config->report_name }}</div><div class="text-xs text-gray-400 font-mono">{{ $config->report_key }}</div></td>
                            @if ($editingId === $config->id)
                                <td class="px-4 py-2"><input type="time" wire:model="editScheduleTime" class="border-gray-300 rounded-md text-sm w-28" /></td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center gap-1">
                                        <input type="number" wire:model="editFromDays" class="border-gray-300 rounded-md text-sm w-16" />
                                        <input type="number" wire:model="editToDays" class="border-gray-300 rounded-md text-sm w-16" />
                                    </div>
                                </td>
                                <td class="px-4 py-2"></td>
                                <td class="px-4 py-2 text-right"><x-primary-button wire:click="saveEdit">Save</x-primary-button></td>
                            @else
                                <td class="px-4 py-2">{{ $config->schedule_time ?? '—' }}</td>
                                <td class="px-4 py-2 text-gray-500">{{ $config->date_offset_from_days }} … {{ $config->date_offset_to_days }}</td>
                                <td class="px-4 py-2">
                                    <button wire:click="toggleEnabled({{ $config->id }})" @class(['px-2 py-0.5 rounded-full text-xs font-medium', 'bg-green-100 text-green-800' => $config->enabled, 'bg-gray-200 text-gray-600' => ! $config->enabled])>
                                        {{ $config->enabled ? 'Enabled' : 'Disabled' }}
                                    </button>
                                </td>
                                <td class="px-4 py-2 text-right whitespace-nowrap">
                                    <button wire:click="edit({{ $config->id }})" class="text-indigo-600 hover:underline mr-3">Edit</button>
                                    <button wire:click="sendNow('{{ $config->report_key }}')" class="text-indigo-600 hover:underline">Send now</button>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Recipients --}}
        <div class="bg-white shadow-sm rounded-lg p-6 space-y-4">
            <h3 class="font-medium text-gray-800">Recipients</h3>
            <div class="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Report</label>
                    <select wire:model="recipient.report_key" class="w-full border-gray-300 rounded-md text-sm">
                        <option value="">Global (all)</option>
                        @foreach ($this->configs as $c)
                            <option value="{{ $c->report_key }}">{{ $c->report_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Type</label>
                    <select wire:model.live="recipient.recipient_type" class="w-full border-gray-300 rounded-md text-sm"><option value="direct">Direct</option><option value="role">Role</option></select>
                </div>
                @if ($recipient['recipient_type'] === 'role')
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Role</label>
                        <select wire:model="recipient.role_key" class="w-full border-gray-300 rounded-md text-sm">
                            <option value="">— role —</option>
                            @foreach ($this->roles as $role)
                                <option value="{{ $role }}">{{ $role }}</option>
                            @endforeach
                        </select>
                        @error('recipient.role_key')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
                    </div>
                @else
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Email</label>
                        <input wire:model="recipient.recipient_email" class="w-full border-gray-300 rounded-md text-sm" />
                        @error('recipient.recipient_email')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
                    </div>
                @endif
                <div><label class="block text-xs text-gray-600 mb-1">Name</label><input wire:model="recipient.recipient_name" class="w-full border-gray-300 rounded-md text-sm" /></div>
                <label class="inline-flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="recipient.is_cc" class="rounded border-gray-300"> CC</label>
                <x-primary-button wire:click="addRecipient">Add</x-primary-button>
            </div>

            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="text-left text-xs text-gray-500 uppercase"><tr><th class="py-2">Scope</th><th class="py-2">Recipient</th><th class="py-2">To/CC</th><th class="py-2"></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($this->recipients as $r)
                        <tr>
                            <td class="py-2">{{ $r->report_key ?? 'Global' }}</td>
                            <td class="py-2">{{ $r->recipient_type === 'role' ? 'Role: '.$r->role_key : $r->recipient_email }}</td>
                            <td class="py-2">{{ $r->is_cc ? 'CC' : 'To' }}</td>
                            <td class="py-2 text-right"><button wire:click="removeRecipient({{ $r->id }})" class="text-red-600 hover:underline">Remove</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-4 text-center text-gray-500">No recipients configured.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Send log --}}
        <div class="bg-white shadow-sm rounded-lg overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-100 font-medium text-gray-800">Recent send log</div>
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500 uppercase"><tr><th class="px-4 py-3">Report</th><th class="px-4 py-3">Trigger</th><th class="px-4 py-3">Range</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Rows</th><th class="px-4 py-3">When</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($this->sendLogs as $log)
                        <tr>
                            <td class="px-4 py-2 font-mono text-xs">{{ $log->report_key }}</td>
                            <td class="px-4 py-2">{{ $log->trigger_mode }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ $log->date_from?->toDateString() }} → {{ $log->date_to?->toDateString() }}</td>
                            <td class="px-4 py-2"><span @class(['px-2 py-0.5 rounded-full text-xs', 'bg-green-100 text-green-800' => $log->status === 'sent', 'bg-amber-100 text-amber-800' => $log->status === 'skipped', 'bg-red-100 text-red-800' => $log->status === 'failed', 'bg-gray-200 text-gray-600' => $log->status === 'running'])>{{ $log->status }}</span></td>
                            <td class="px-4 py-2">{{ $log->row_count }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ $log->created_at?->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">No sends logged yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
