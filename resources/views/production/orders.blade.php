<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $title }}
            </h2>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <p class="text-sm text-gray-500">{{ $subtitle }}</p>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                @if (count($orders) === 0)
                    <div class="p-6 text-sm text-gray-500">No outstanding orders found.</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <th class="px-4 py-2">MO Ref</th>
                                    <th class="px-4 py-2">Product</th>
                                    <th class="px-4 py-2 text-right">Outstanding</th>
                                    <th class="px-4 py-2">Due</th>
                                    <th class="px-4 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($orders as $row)
                                    <tr>
                                        <td class="px-4 py-2 font-medium text-gray-900">{{ $row['mo_ref'] }}</td>
                                        <td class="px-4 py-2 text-gray-700">
                                            <div class="text-gray-500 text-xs">{{ $row['product_id'] }}</div>
                                            {{ \Illuminate\Support\Str::limit($row['product_description'], 70) }}
                                        </td>
                                        <td class="px-4 py-2 text-right">{{ rtrim(rtrim((string) $row['outstanding'], '0'), '.') }}</td>
                                        <td class="px-4 py-2">{{ $row['due_date'] ? \Illuminate\Support\Str::of($row['due_date'])->before(' ') : '—' }}</td>
                                        <td class="px-4 py-2 text-right">
                                            <a href="{{ route('manufacturing-orders.search', ['openMo' => $row['winman_mo']]) }}" wire:navigate class="text-sm text-indigo-600 hover:underline">Open in MO workspace</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
