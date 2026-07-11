<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Profile') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <section class="space-y-6">
                        <header>
                            <h2 class="text-lg font-medium text-gray-900">{{ __('Profile Information') }}</h2>
                            <p class="mt-1 text-sm text-gray-600">
                                {{ __('Your account profile is managed by Microsoft Entra ID (tenant controlled).') }}
                            </p>
                        </header>

                        <div class="space-y-4">
                            <div>
                                <x-input-label :value="__('Name')" />
                                <x-text-input type="text" class="mt-1 block w-full bg-gray-50" :value="auth()->user()?->name" readonly disabled />
                            </div>

                            <div>
                                <x-input-label :value="__('Email')" />
                                <x-text-input type="email" class="mt-1 block w-full bg-gray-50" :value="auth()->user()?->email" readonly disabled />
                            </div>
                        </div>

                        <div class="rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-800">
                            {{ __('Changes to name, email, password, and account lifecycle must be managed in Microsoft Entra ID.') }}
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
