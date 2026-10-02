<?php

use App\Models\NotificationRecipient;
use App\Models\NotificationRule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Spatie\Permission\Models\Role;

new #[Layout('layouts.app')] #[Title('Notifications Setup')] class extends Component {
    public ?int $editingId = null;
    public string $editSeverity = 'warning';
    public int $editCooldown = 60;
    public string $editCondition = '';

    /** @var array<string, mixed> */
    public array $recipient = [
        'rule_key' => '', 'recipient_type' => 'direct', 'recipient_email' => '', 'recipient_name' => '', 'role_key' => '',
    ];

    public ?string $flash = null;

    #[Computed]
    public function rules()
    {
        return NotificationRule::query()->orderBy('rule_name')->get();
    }

    #[Computed]
    public function recipients()
    {
        return NotificationRecipient::query()->orderBy('rule_key')->get();
    }

    #[Computed]
    public function roles(): array
    {
        return Role::query()->pluck('name')->all();
    }

    public function toggleRule(int $id): void
    {
        $rule = NotificationRule::findOrFail($id);
        $rule->update(['enabled' => ! $rule->enabled]);
    }

    public function editRule(int $id): void
    {
        $rule = NotificationRule::findOrFail($id);
        $this->editingId = $rule->id;
        $this->editSeverity = $rule->severity;
        $this->editCooldown = $rule->cooldown_minutes;
        $this->editCondition = (string) $rule->trigger_condition;
    }

    public function saveRule(): void
    {
        $rule = NotificationRule::findOrFail($this->editingId);
        $rule->update([
            'severity' => $this->editSeverity,
            'cooldown_minutes' => $this->editCooldown,
            'trigger_condition' => $this->editCondition ?: null,
        ]);
        $this->editingId = null;
        $this->flash = 'Rule updated.';
    }

    public function addRecipient(): void
    {
        $data = $this->validate([
            'recipient.rule_key' => ['nullable', 'string'],
            'recipient.recipient_type' => ['required', 'in:direct,role'],
            'recipient.recipient_email' => ['nullable', 'email', 'required_if:recipient.recipient_type,direct'],
            'recipient.recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient.role_key' => ['nullable', 'string', 'required_if:recipient.recipient_type,role'],
        ])['recipient'];

        NotificationRecipient::create([
            'rule_key' => $data['rule_key'] ?: null,
            'recipient_type' => $data['recipient_type'],
            'recipient_email' => $data['recipient_email'] ?: null,
            'recipient_name' => $data['recipient_name'] ?: null,
            'role_key' => $data['role_key'] ?: null,
            'enabled' => true,
        ]);

        $this->recipient = ['rule_key' => '', 'recipient_type' => 'direct', 'recipient_email' => '', 'recipient_name' => '', 'role_key' => ''];
        $this->flash = 'Recipient added.';
    }

    public function removeRecipient(int $id): void
    {
        NotificationRecipient::whereKey($id)->delete();
    }
}; ?>

<div class="py-8">
    <x-mo-workspace-styles />
    <div class="wm-page max-w-7xl mx-auto space-y-6">

        <section class="wm-card wm-card--gear-tr">
            <h1 class="wm-title">Notifications Setup</h1>
            <p class="wm-sub" style="margin-top:4px;">Configure alert rules and email recipients. Operators see the raised alerts on the <a href="{{ route('notifications.index') }}" wire:navigate class="text-indigo-600 hover:underline">Notifications</a> page.</p>

            <x-settings-subnav />
        </section>

        @if ($flash)
            <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-lg px-4 py-3">{{ $flash }}</div>
        @endif

        {{-- Rules --}}
        <section class="wm-card wm-card--gear-bl">
            <h2 class="wm-title" style="font-size:1.05rem;">Rules</h2>
            <div class="wm-table" style="margin-top:14px;">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead>
                    <tr style="background:linear-gradient(180deg,#2b3238,#171c20);color:#fff;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;"><th class="px-4 py-3">Rule</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Severity</th><th class="px-4 py-3">Threshold</th><th class="px-4 py-3">Cooldown</th><th class="px-4 py-3">Enabled</th><th class="px-4 py-3"></th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($this->rules as $rule)
                        <tr>
                            <td class="px-4 py-2"><div class="font-medium text-gray-800">{{ $rule->rule_name }}</div><div class="text-xs text-gray-400 font-mono">{{ $rule->rule_key }}</div></td>
                            <td class="px-4 py-2">{{ $rule->event_type }}</td>
                            @if ($editingId === $rule->id)
                                <td class="px-4 py-2">
                                    <select wire:model="editSeverity" class="border-gray-300 rounded-md text-sm"><option value="info">info</option><option value="warning">warning</option><option value="critical">critical</option></select>
                                </td>
                                <td class="px-4 py-2"><input wire:model="editCondition" class="border-gray-300 rounded-md text-sm w-20" placeholder="hrs" /></td>
                                <td class="px-4 py-2"><input type="number" wire:model="editCooldown" class="border-gray-300 rounded-md text-sm w-20" /></td>
                                <td class="px-4 py-2"></td>
                                <td class="px-4 py-2 text-right"><button type="button" wire:click="saveRule" class="wm-btn-dark">Save</button></td>
                            @else
                                <td class="px-4 py-2">
                                    <span @class(['px-2 py-0.5 rounded-full text-xs font-medium', 'bg-red-100 text-red-800' => $rule->severity === 'critical', 'bg-amber-100 text-amber-800' => $rule->severity === 'warning', 'bg-gray-100 text-gray-700' => $rule->severity === 'info'])>{{ $rule->severity }}</span>
                                </td>
                                <td class="px-4 py-2 text-gray-500">{{ $rule->trigger_condition ? $rule->trigger_condition.'h' : '—' }}</td>
                                <td class="px-4 py-2 text-gray-500">{{ $rule->cooldown_minutes }}m</td>
                                <td class="px-4 py-2">
                                    <button wire:click="toggleRule({{ $rule->id }})" @class(['px-2 py-0.5 rounded-full text-xs font-medium', 'bg-green-100 text-green-800' => $rule->enabled, 'bg-gray-200 text-gray-600' => ! $rule->enabled])>{{ $rule->enabled ? 'Enabled' : 'Disabled' }}</button>
                                </td>
                                <td class="px-4 py-2 text-right"><button wire:click="editRule({{ $rule->id }})" class="text-indigo-600 hover:underline">Edit</button></td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            </div>
        </section>

        {{-- Recipients --}}
        <section class="wm-card wm-card--gear-tr">
            <h2 class="wm-title" style="font-size:1.05rem;">Alert Recipients</h2>
            <div class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end" style="margin-top:14px;">
                <div>
                    <label class="block text-xs text-gray-600 mb-1">Rule</label>
                    <select wire:model="recipient.rule_key" class="w-full border-gray-300 rounded-md text-sm">
                        <option value="">All rules</option>
                        @foreach ($this->rules as $r)
                            <option value="{{ $r->rule_key }}">{{ $r->rule_name }}</option>
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
                            @foreach ($this->roles as $role)<option value="{{ $role }}">{{ $role }}</option>@endforeach
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
                <button type="button" wire:click="addRecipient" class="wm-btn-dark">Add</button>
            </div>

            <div class="wm-table" style="margin-top:14px;">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead><tr style="background:linear-gradient(180deg,#2b3238,#171c20);color:#fff;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;"><th class="px-4 py-3">Rule</th><th class="px-4 py-3">Recipient</th><th class="px-4 py-3"></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($this->recipients as $r)
                        <tr>
                            <td class="px-4 py-2">{{ $r->rule_key ?? 'All rules' }}</td>
                            <td class="px-4 py-2">{{ $r->recipient_type === 'role' ? 'Role: '.$r->role_key : $r->recipient_email }}</td>
                            <td class="px-4 py-2 text-right"><button wire:click="removeRecipient({{ $r->id }})" class="text-red-600 hover:underline">Remove</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-6 text-center text-gray-500">No recipients configured.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
            </div>
        </section>
    </div>
</div>
