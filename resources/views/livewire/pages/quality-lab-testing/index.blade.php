<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Quality & Lab Testing')] class extends Component {
}; ?>

<x-mo-workspace-styles />

<div class="py-8">
    <div class="wm-page max-w-5xl mx-auto space-y-6">
        <section class="wm-card wm-card--gear-tr">
            <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
                <span style="width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid #c9a24a;overflow:hidden;flex-shrink:0;background:#fffdf7;">
                    <img src="{{ asset('lab-testing-icon.png') }}" alt="Quality & Lab Testing" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" />
                </span>

                <div>
                    <h1 class="wm-title">QUALITY &amp; LAB TESTING</h1>
                    <div class="wm-sub" style="text-transform:uppercase;letter-spacing:.1em;">WM005 &middot; WM010 Check Sheets</div>
                </div>

                <a href="{{ route('dashboard') }}" wire:navigate class="wm-link" style="margin-left:auto;">Back to Main Menu</a>
            </div>

            <div class="wm-rows">
                <a href="{{ route('quality.lab-testing.wet-mustard-lab') }}" wire:navigate class="wm-row" style="display:block;text-decoration:none;--wm-strip:#c9a24a;">
                    <div class="wm-ref-text">Wet Mustard Lab Testing</div>
                    <div class="wm-sub">WM005 Quality analytical checks</div>
                </a>

                <a href="{{ route('quality.lab-testing.rinse-water-test') }}" wire:navigate class="wm-row" style="display:block;text-decoration:none;--wm-strip:#c9a24a;">
                    <div class="wm-ref-text">Rinse Water Test</div>
                    <div class="wm-sub">WM010 Chemical &amp; sulphite checks</div>
                </a>
            </div>
        </section>
    </div>
</div>
