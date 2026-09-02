<?php

use App\Models\NotificationEvent;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Notifications')] class extends Component {
    #[Computed]
    public function events()
    {
        return NotificationEvent::query()->latest('triggered_at')->limit(50)->get();
    }

    public function acknowledge(int $id): void
    {
        NotificationEvent::whereKey($id)->update([
            'status' => NotificationEvent::STATUS_ACKNOWLEDGED,
            'acknowledged_by' => auth()->id(),
            'acknowledged_at' => now(),
        ]);
    }

    public function resolve(int $id): void
    {
        NotificationEvent::whereKey($id)->update([
            'status' => NotificationEvent::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);
    }
}; ?>

<div class="py-8">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div style="background:#fff;border:1px solid #dbe1ea;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,0.08);">
            <div style="padding:24px 26px;background:linear-gradient(135deg,#f8fafc 0%,#e0ecff 100%);border-bottom:1px solid #dbe1ea;">
                <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
                    <div style="width:56px;height:56px;background:#ecfdf5;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid #86efac;overflow:hidden;flex-shrink:0;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#15803d" stroke-width="1.6" style="width:28px;height:28px;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5m6 0v1a3 3 0 1 1-6 0v-1m6 0H9" />
                        </svg>
                    </div>

                    <div>
                        <div style="font-size:1.3rem;font-weight:900;color:#1a1a2e;letter-spacing:-0.02em;line-height:1;">NOTIFICATIONS</div>
                        <div style="font-size:0.75rem;font-weight:700;color:#64748b;letter-spacing:.14em;margin-top:4px;">ALERTS RAISED ACROSS CHECKS &amp; PRODUCTION</div>
                    </div>

                    <a href="{{ route('dashboard') }}" wire:navigate style="margin-left:auto;display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;font-size:12px;font-weight:800;text-decoration:none;">
                        Back to Main Menu
                    </a>
                </div>
            </div>

            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead>
                    <tr style="background:#2d3f8f;color:#fff;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;">
                        <th class="px-4 py-3">Rule</th>
                        <th class="px-4 py-3">Severity</th>
                        <th class="px-4 py-3">Message</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">When</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($this->events as $event)
                        <tr>
                            <td class="px-4 py-2 font-mono text-xs">{{ $event->rule_key }}</td>
                            <td class="px-4 py-2"><span @class(['px-2 py-0.5 rounded-full text-xs', 'bg-red-100 text-red-800' => $event->severity === 'critical', 'bg-amber-100 text-amber-800' => $event->severity === 'warning', 'bg-gray-100 text-gray-700' => $event->severity === 'info'])>{{ $event->severity }}</span></td>
                            <td class="px-4 py-2">{{ $event->message }}</td>
                            <td class="px-4 py-2">{{ $event->status }}</td>
                            <td class="px-4 py-2 text-gray-500">{{ $event->triggered_at?->diffForHumans() }}</td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                @if ($event->status === 'open')
                                    <button wire:click="acknowledge({{ $event->id }})" class="text-indigo-600 hover:underline mr-2">Ack</button>
                                @endif
                                @if ($event->status !== 'resolved')
                                    <button wire:click="resolve({{ $event->id }})" class="text-green-600 hover:underline">Resolve</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-gray-500">No alerts raised yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>
