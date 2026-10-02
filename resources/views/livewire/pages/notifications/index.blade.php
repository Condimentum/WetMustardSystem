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
    <x-mo-workspace-styles />
    <div class="wm-page max-w-7xl mx-auto space-y-6">

        <section class="wm-card wm-card--gear-tr">
            <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
                <span class="wml-medal">
                    <img src="{{ asset('images/dashboard/bell.png') }}" alt="" />
                </span>

                <div>
                    <h1 class="wm-title">Notifications</h1>
                    <div class="wm-sub" style="text-transform:uppercase;letter-spacing:.1em;">Alerts Raised Across Checks &amp; Production</div>
                </div>

                <a href="{{ route('dashboard') }}" wire:navigate class="wm-link" style="margin-left:auto;">Back to Main Menu</a>
            </div>

            <div class="wm-table" style="margin-top:18px;">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead>
                    <tr style="background:linear-gradient(180deg,#2b3238,#171c20);color:#fff;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;">
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
        </section>
    </div>
</div>
