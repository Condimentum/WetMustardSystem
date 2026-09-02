<x-app-layout>
    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @php
                $icon = 'ibc-production-icon.png';
            @endphp

            <div style="background:#fff;border:1px solid #dbe1ea;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,0.08);">
                <div style="padding:24px 26px;background:linear-gradient(135deg,#f8fafc 0%,#e0ecff 100%);border-bottom:1px solid #dbe1ea;">
                    <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
                        <div style="width:56px;height:56px;background:#ecfdf5;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid #86efac;overflow:hidden;flex-shrink:0;">
                            <img src="{{ asset($icon) }}" alt="{{ $title }}" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" />
                        </div>

                        <div>
                            <div style="font-size:1.3rem;font-weight:900;color:#1a1a2e;letter-spacing:-0.02em;line-height:1;">{{ strtoupper($title) }}</div>
                            <div style="font-size:0.75rem;font-weight:700;color:#64748b;letter-spacing:.14em;margin-top:4px;">{{ strtoupper($subtitle) }}</div>
                        </div>

                        <span style="margin-left:auto;display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;font-size:12px;font-weight:800;">
                            {{ count($orders) }} shown
                        </span>
                    </div>
                </div>

                @if (count($orders) === 0)
                    <div class="p-6 text-sm text-gray-500">No outstanding orders found.</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr style="background:#2d3f8f;color:#fff;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;">
                                    <th class="px-4 py-3">MO Ref</th>
                                    <th class="px-4 py-3">Product</th>
                                    <th class="px-4 py-3 text-right">Outstanding</th>
                                    <th class="px-4 py-3">Due</th>
                                    <th class="px-4 py-3"></th>
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
