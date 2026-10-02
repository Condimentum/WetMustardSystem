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
    <x-mo-workspace-styles />
    <div class="wm-page max-w-5xl mx-auto space-y-6">
        @php
            $checkCount = collect($this->todayStatus)->count();
            $completedTodayCount = collect($this->todayStatus)->filter()->count();

            $tiles = [
                ['key' => 'wm001', 'code' => 'WM001', 'label' => 'Lab Scales Daily Calibration', 'route' => 'calibrations.wm001'],
                ['key' => 'wm002', 'code' => 'WM002', 'label' => 'Daily Salt Meter Calibration', 'route' => 'calibrations.wm002'],
                ['key' => 'wm006', 'code' => 'WM006', 'label' => 'Viscosity Meter Autozero Check', 'route' => 'calibrations.wm006'],
                ['key' => 'wm013', 'code' => 'WM013', 'label' => 'Production Scales Daily Calibration', 'route' => 'calibrations.wm013'],
            ];
        @endphp

        <section class="wm-card wm-card--gear-tr">
            <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
                <span style="width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid #c9a24a;overflow:hidden;flex-shrink:0;background:#fffdf7;">
                    <img src="{{ asset('calibration-icon.png') }}" alt="Daily Calibrations" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" />
                </span>

                <div>
                    <h1 class="wm-title">DAILY CALIBRATIONS</h1>
                    <div class="wm-sub" style="text-transform:uppercase;letter-spacing:.1em;">WM001 &middot; WM002 &middot; WM006 &middot; WM013 Tolerance Checks</div>
                </div>

                <span class="wm-pill" style="margin-left:auto;background:linear-gradient(180deg,#2b6f86,#1d4f61);">{{ $completedTodayCount }} of {{ $checkCount }} completed today</span>
                <a href="{{ route('dashboard') }}" wire:navigate class="wm-link">Back to Main Menu</a>
            </div>

            <div class="wm-rows">
                @foreach ($tiles as $tile)
                    <a href="{{ route($tile['route']) }}" wire:navigate class="wm-row" style="display:flex;align-items:center;justify-content:space-between;gap:12px;text-decoration:none;--wm-strip: {{ $this->todayStatus[$tile['key']] ? '#3aa33a' : '#c9a24a' }};">
                        <div>
                            <div class="wm-ref-text">{{ $tile['code'] }}</div>
                            <div class="wm-sub">{{ $tile['label'] }}</div>
                        </div>
                        <span class="wm-pill" style="background:{{ $this->todayStatus[$tile['key']] ? 'linear-gradient(180deg,#3aa33a,#1d6b24)' : 'linear-gradient(180deg,#9a8b6d,#6b5d42)' }};">{{ $this->todayStatus[$tile['key']] ? 'Done' : 'Pending' }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    </div>
</div>

