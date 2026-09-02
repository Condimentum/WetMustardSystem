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
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div>
            <h2 class="text-xl font-semibold text-gray-800">Notifications Setup</h2>
            <p class="text-sm text-gray-500">Configure alert rules and email recipients. Operators see the raised alerts on the <a href="{{ route('notifications.index') }}" wire:navigate class="text-indigo-600 hover:underline">Notifications</a> page.</p>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-2 shadow-sm">
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('settings.admin') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.admin') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">General</a>
                <a href="{{ route('settings.recipes') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.recipes') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Recipes</a>
                <a href="{{ route('settings.product-mapping') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.product-mapping') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Product Mapping</a>
                <a href="{{ route('settings.operator-sync') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.operator-sync') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Operator Sync</a>
                <a href="{{ route('settings.documents') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.documents') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Documents</a>
                <a href="{{ route('reporting.admin') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('reporting.*') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Reporting</a>
                <a href="{{ route('notifications.setup') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('notifications.setup') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Notifications Setup</a>
                <a href="{{ route('audit.index') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('audit.index') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Audit</a>
                <a href="{{ route('audit.errors') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('audit.errors') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Error Log</a>
            </div>
        </div>

        @if ($flash)
            <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-lg px-4 py-3">{{ $flash }}</div>
        @endif

        {{-- Rules --}}
        <div class="bg-white shadow-sm rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500 uppercase">
                    <tr><th class="px-4 py-3">Rule</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Severity</th><th class="px-4 py-3">Threshold</th><th class="px-4 py-3">Cooldown</th><th class="px-4 py-3">Enabled</th><th class="px-4 py-3"></th></tr>
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
                                <td class="px-4 py-2 text-right"><x-primary-button wire:click="saveRule">Save</x-primary-button></td>
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

        {{-- Recipients --}}
        <div class="bg-white shadow-sm rounded-lg p-6 space-y-4">
            <h3 class="font-medium text-gray-800">Alert Recipients</h3>
            <div class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
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
                <x-primary-button wire:click="addRecipient">Add</x-primary-button>
            </div>

            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="text-left text-xs text-gray-500 uppercase"><tr><th class="py-2">Rule</th><th class="py-2">Recipient</th><th class="py-2"></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($this->recipients as $r)
                        <tr>
                            <td class="py-2">{{ $r->rule_key ?? 'All rules' }}</td>
                            <td class="py-2">{{ $r->recipient_type === 'role' ? 'Role: '.$r->role_key : $r->recipient_email }}</td>
                            <td class="py-2 text-right"><button wire:click="removeRecipient({{ $r->id }})" class="text-red-600 hover:underline">Remove</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-4 text-center text-gray-500">No recipients configured.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>
