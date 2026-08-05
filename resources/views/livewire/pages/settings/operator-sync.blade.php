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
    <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <h2 class="text-xl font-semibold text-gray-800">Operator Sync</h2>
        <p class="text-sm text-gray-600">Configure paperwork sign-off formatting and sync operator users from Microsoft Entra.</p>

        <div class="rounded-lg border border-slate-200 bg-white p-2 shadow-sm">
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('settings.admin') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.admin') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">General</a>
                <a href="{{ route('settings.recipes') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.recipes') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Recipes</a>
                <a href="{{ route('settings.product-mapping') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.product-mapping') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Product Mapping</a>
                <a href="{{ route('settings.operator-sync') }}" wire:navigate class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium {{ request()->routeIs('settings.operator-sync') ? 'bg-sky-700 text-white' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-700' }}">Operator Sync</a>
            </div>
        </div>

        @if ($flash)
            <div @class([
                'text-sm rounded-lg px-4 py-3 border',
                'bg-green-50 border-green-200 text-green-800' => $flashLevel === 'success',
                'bg-red-50 border-red-200 text-red-800' => $flashLevel === 'error',
            ])>{{ $flash }}</div>
        @endif

        <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 font-medium text-gray-800">Paperwork Sign-off Display</div>

            <div class="px-5 py-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-800 mb-2">Operator name format on paperwork</label>
                    <select wire:model="signoffDisplayMode" class="w-full max-w-md border-gray-300 rounded-md text-sm">
                        <option value="short_initials">Short initials: AB</option>
                        <option value="initial_last_name">Initial + last name: A Brown</option>
                        <option value="full_initials">Full initials: AMB</option>
                    </select>
                    <p class="mt-2 text-sm text-gray-600">Used for the weighed and tipped marks printed inside the ingredient batch-number cells.</p>
                </div>

                <div class="flex justify-end">
                    <x-primary-button type="button" wire:click="saveSignoffDisplay">Save display format</x-primary-button>
                </div>
            </div>
        </div>

        <div class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 font-medium text-gray-800">Microsoft Operator Sync</div>

            <div class="px-5 py-4 space-y-4">
                <div>
                    <div class="font-medium text-gray-800">Sync local operator list from Microsoft Entra</div>
                    <div class="text-sm text-gray-600">Pull enabled Microsoft users into the local users table so they can be selected for weighed and tipped sign-offs.</div>
                    <div class="text-xs text-gray-400 mt-1">Uses the configured operator group and Microsoft app credentials.</div>
                </div>

                <div class="flex justify-end">
                    <x-primary-button type="button" wire:click="syncMicrosoftUsers">Sync Microsoft Users</x-primary-button>
                </div>

                <div class="border-t border-gray-100 pt-4">
                    <div class="font-medium text-gray-800">Synced operators ({{ count($operators) }})</div>

                    @if ($operators === [])
                        <p class="mt-2 text-sm text-gray-600">No users are available yet. Run Sync Microsoft Users.</p>
                    @else
                        <div class="mt-3 overflow-x-auto">
                            <table class="min-w-full text-sm text-left">
                                <thead class="text-xs uppercase tracking-wide text-slate-500 border-b border-slate-200">
                                    <tr>
                                        <th class="px-3 py-2">Name</th>
                                        <th class="px-3 py-2">Email</th>
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
            </div>
        </div>
    </div>
</div>
