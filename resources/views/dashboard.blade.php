<x-app-layout>
    <div class="py-8">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="mb-6 text-center">
                <h1 class="text-2xl font-bold tracking-tight text-slate-900">Manufacturing Dashboard</h1>
                <p class="mt-1 text-sm font-medium uppercase tracking-wide text-slate-400">QC &amp; Production</p>
            </div>

            <div class="space-y-3">
                @foreach ($tiles as $tile)
                    <a
                        href="{{ route($tile['route']) }}"
                        wire:navigate
                        class="group flex items-center gap-4 rounded-2xl border border-slate-200 bg-white px-5 py-4 shadow-sm transition hover:border-indigo-300 hover:shadow-md"
                    >
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-700 group-hover:bg-indigo-600 group-hover:text-white transition">
                            <x-menu-tile-icon :icon="$tile['icon']" />
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold text-slate-900">{{ $tile['title'] }}</span>
                            @if ($tile['subtitle'])
                                <span class="block text-sm text-slate-500">{{ $tile['subtitle'] }}</span>
                            @endif
                        </span>

                        <svg class="h-5 w-5 shrink-0 text-slate-300 transition group-hover:text-indigo-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6" />
                        </svg>
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>
