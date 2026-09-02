<?php

use App\Models\LabScaleCalibration;
use App\Models\ProductionScaleCalibration;
use App\Models\SaltMeterCalibration;
use App\Models\ViscosityMeterAutozeroCheck;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Daily Calibrations')] class extends Component {
    #[Computed]
    public function todayStatus(): array
    {
        $today = now()->toDateString();

        return [
            'wm001' => LabScaleCalibration::query()->whereDate('checked_date', $today)->exists(),
            'wm002' => SaltMeterCalibration::query()->whereDate('checked_date', $today)->exists(),
            'wm006' => ViscosityMeterAutozeroCheck::query()->whereDate('checked_date', $today)->exists(),
            'wm013' => ProductionScaleCalibration::query()->whereDate('checked_date', $today)->exists(),
        ];
    }
}; ?>

<div class="py-8">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @php
            $checkCount = collect($this->todayStatus)->count();
            $completedTodayCount = collect($this->todayStatus)->filter()->count();
        @endphp

        <div style="background:#fff;border:1px solid #dbe1ea;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,0.08);">
            <div style="padding:24px 26px;background:linear-gradient(135deg,#f8fafc 0%,#e0ecff 100%);border-bottom:1px solid #dbe1ea;">
                <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
                    <div style="width:56px;height:56px;background:#ecfdf5;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid #86efac;overflow:hidden;flex-shrink:0;">
                        <img src="{{ asset('calibration-icon.png') }}" alt="Daily Calibrations" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" />
                    </div>

                    <div>
                        <div style="font-size:1.3rem;font-weight:900;color:#1a1a2e;letter-spacing:-0.02em;line-height:1;">DAILY CALIBRATIONS</div>
                        <div style="font-size:0.75rem;font-weight:700;color:#64748b;letter-spacing:.14em;margin-top:4px;">WM001 &middot; WM002 &middot; WM006 &middot; WM013 TOLERANCE CHECKS</div>
                    </div>

                    <span style="margin-left:auto;display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;font-size:12px;font-weight:800;">
                        {{ $completedTodayCount }} of {{ $checkCount }} completed today
                    </span>

                    <a href="{{ route('dashboard') }}" wire:navigate style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;font-size:12px;font-weight:800;text-decoration:none;">
                        Back to Main Menu
                    </a>
                </div>
            </div>

            <div class="p-6 grid gap-4 md:grid-cols-2">
                <a href="{{ route('calibrations.wm001') }}" wire:navigate class="rounded-xl border border-slate-200 p-4 hover:border-indigo-300 hover:bg-indigo-50/40 transition flex items-start justify-between gap-3">
                    <div>
                        <div class="font-semibold text-slate-900">WM001</div>
                        <div class="text-xs text-slate-500 mt-1">Lab Scales Daily Calibration</div>
                    </div>
                    <span style="display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;border:1px solid {{ $this->todayStatus['wm001'] ? '#86efac' : '#cbd5e1' }};background:{{ $this->todayStatus['wm001'] ? '#ecfdf5' : '#f1f5f9' }};color:{{ $this->todayStatus['wm001'] ? '#15803d' : '#475569' }};font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;">{{ $this->todayStatus['wm001'] ? 'Done' : 'Pending' }}</span>
                </a>

                <a href="{{ route('calibrations.wm002') }}" wire:navigate class="rounded-xl border border-slate-200 p-4 hover:border-indigo-300 hover:bg-indigo-50/40 transition flex items-start justify-between gap-3">
                    <div>
                        <div class="font-semibold text-slate-900">WM002</div>
                        <div class="text-xs text-slate-500 mt-1">Daily Salt Meter Calibration</div>
                    </div>
                    <span style="display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;border:1px solid {{ $this->todayStatus['wm002'] ? '#86efac' : '#cbd5e1' }};background:{{ $this->todayStatus['wm002'] ? '#ecfdf5' : '#f1f5f9' }};color:{{ $this->todayStatus['wm002'] ? '#15803d' : '#475569' }};font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;">{{ $this->todayStatus['wm002'] ? 'Done' : 'Pending' }}</span>
                </a>

                <a href="{{ route('calibrations.wm006') }}" wire:navigate class="rounded-xl border border-slate-200 p-4 hover:border-indigo-300 hover:bg-indigo-50/40 transition flex items-start justify-between gap-3">
                    <div>
                        <div class="font-semibold text-slate-900">WM006</div>
                        <div class="text-xs text-slate-500 mt-1">Viscosity Meter Autozero Check</div>
                    </div>
                    <span style="display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;border:1px solid {{ $this->todayStatus['wm006'] ? '#86efac' : '#cbd5e1' }};background:{{ $this->todayStatus['wm006'] ? '#ecfdf5' : '#f1f5f9' }};color:{{ $this->todayStatus['wm006'] ? '#15803d' : '#475569' }};font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;">{{ $this->todayStatus['wm006'] ? 'Done' : 'Pending' }}</span>
                </a>

                <a href="{{ route('calibrations.wm013') }}" wire:navigate class="rounded-xl border border-slate-200 p-4 hover:border-indigo-300 hover:bg-indigo-50/40 transition flex items-start justify-between gap-3">
                    <div>
                        <div class="font-semibold text-slate-900">WM013</div>
                        <div class="text-xs text-slate-500 mt-1">Production Scales Daily Calibration</div>
                    </div>
                    <span style="display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;border:1px solid {{ $this->todayStatus['wm013'] ? '#86efac' : '#cbd5e1' }};background:{{ $this->todayStatus['wm013'] ? '#ecfdf5' : '#f1f5f9' }};color:{{ $this->todayStatus['wm013'] ? '#15803d' : '#475569' }};font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;">{{ $this->todayStatus['wm013'] ? 'Done' : 'Pending' }}</span>
                </a>
            </div>
        </div>
    </div>
</div>
