<?php

use App\Models\User;
use App\Operations\SyncMicrosoftUsersOperation;
use App\Support\FeatureSettings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Operator Sync')] class extends Component {
    public string $signoffDisplayMode = 'short_initials';

    public ?string $flash = null;

    public string $flashLevel = 'success';

    /** @var array<int, array{name:string,email:string}> */
    public array $operators = [];

    public function mount(): void
    {
        $this->signoffDisplayMode = FeatureSettings::value('paperwork.signoff_display_mode', 'short_initials') ?? 'short_initials';
        $this->loadOperators();
    }

    public function saveSignoffDisplay(): void
    {
        $validated = $this->validate([
            'signoffDisplayMode' => ['required', 'in:short_initials,initial_last_name,full_initials'],
        ]);

        FeatureSettings::setValue(
            'paperwork.signoff_display_mode',
            $validated['signoffDisplayMode'],
            'string',
            auth()->id(),
            'Controls how weighed/tipped operator names are abbreviated on paperwork.',
        );

        $this->flashLevel = 'success';
        $this->flash = 'Paperwork sign-off display updated.';
    }

    public function syncMicrosoftUsers(): void
    {
        try {
            $result = app(SyncMicrosoftUsersOperation::class)();
        } catch (\Throwable $e) {
            $this->flashLevel = 'error';
            $this->flash = 'Microsoft user sync failed. '.$e->getMessage();

            return;
        }

        $this->loadOperators();

        $this->flashLevel = 'success';
        $this->flash = 'Microsoft user sync completed. Synced '.$result['synced'].' user(s), skipped '.$result['skipped'].'.';
    }

    private function loadOperators(): void
    {
        $this->operators = User::query()
            ->orderBy('name')
            ->get(['name', 'email'])
            ->map(fn (User $user): array => [
                'name' => (string) $user->name,
                'email' => (string) $user->email,
            ])
            ->all();
    }
}; ?>

<div class="py-8">
    <x-mo-workspace-styles />
    <div class="wm-page max-w-4xl mx-auto space-y-6">
        <section class="wm-card wm-card--gear-tr">
            <h1 class="wm-title">Operator Sync</h1>
            <p class="wm-sub" style="margin-top:4px;">Configure paperwork sign-off formatting and sync operator users from Microsoft Entra.</p>

            <x-settings-subnav />
        </section>

        @if ($flash)
            <div @class([
                'text-sm rounded-lg px-4 py-3 border',
                'bg-green-50 border-green-200 text-green-800' => $flashLevel === 'success',
                'bg-red-50 border-red-200 text-red-800' => $flashLevel === 'error',
            ])>{{ $flash }}</div>
        @endif

        <section class="wm-card wm-card--gear-bl">
            <h2 class="wm-title" style="font-size:1.05rem;">Paperwork Sign-off Display</h2>

            <div style="margin-top:14px;">
                <label class="block text-sm font-medium text-gray-800 mb-2">Operator name format on paperwork</label>
                <select wire:model="signoffDisplayMode" class="w-full max-w-md border-gray-300 rounded-md text-sm">
                    <option value="short_initials">Short initials: AB</option>
                    <option value="initial_last_name">Initial + last name: A Brown</option>
                    <option value="full_initials">Full initials: AMB</option>
                </select>
                <p class="mt-2 text-sm text-gray-600">Used for the weighed and tipped marks printed inside the ingredient batch-number cells.</p>
            </div>

            <div class="flex justify-end" style="margin-top:14px;">
                <button type="button" wire:click="saveSignoffDisplay" class="wm-btn-dark">Save display format</button>
            </div>
        </section>

        <section class="wm-card wm-card--gear-tr">
            <h2 class="wm-title" style="font-size:1.05rem;">Microsoft Operator Sync</h2>

            <div style="margin-top:14px;">
                <div class="font-medium text-gray-800">Sync local operator list from Microsoft Entra</div>
                <div class="text-sm text-gray-600">Pull enabled Microsoft users into the local users table so they can be selected for weighed and tipped sign-offs.</div>
                <div class="text-xs text-gray-400 mt-1">Uses the configured operator group and Microsoft app credentials.</div>
            </div>

            <div class="flex justify-end" style="margin-top:14px;">
                <button type="button" wire:click="syncMicrosoftUsers" class="wm-btn-dark">Sync Microsoft Users</button>
            </div>

            <div class="wm-table" style="margin-top:14px;">
                <div class="px-5 py-3 wm-sub" style="font-weight:700;color:#1f3f4f;">Synced operators ({{ count($operators) }})</div>

                @if ($operators === [])
                    <p class="px-5 pb-4 text-sm text-gray-600">No users are available yet. Run Sync Microsoft Users.</p>
                @else
                    <div class="overflow-x-auto">
                    <table class="min-w-full text-sm text-left">
                        <thead>
                            <tr style="background:linear-gradient(180deg,#2b3238,#171c20);color:#fff;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;">
                                <th class="px-3 py-3">Name</th>
                                <th class="px-3 py-3">Email</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($operators as $operator)
                                <tr>
                                    <td class="px-3 py-2 text-slate-800">{{ $operator['name'] }}</td>
                                    <td class="px-3 py-2 text-slate-600">{{ $operator['email'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                @endif
            </div>
        </section>
    </div>
</div>
