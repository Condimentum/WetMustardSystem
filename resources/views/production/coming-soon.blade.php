<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $title }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-8 text-center">
                <p class="text-sm font-medium uppercase tracking-wide text-indigo-500">Coming soon</p>
                <p class="mt-2 text-gray-600">{{ $title }} isn't built yet. This tile is a placeholder in the new navigation.</p>
            </div>
        </div>
    </div>
</x-app-layout>
