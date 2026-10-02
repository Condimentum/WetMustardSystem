<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Quality & Lab Testing')] class extends Component {
}; ?>

<div class="py-8">
    <x-mo-workspace-styles />
    <div class="wm-page max-w-5xl mx-auto space-y-6">
        <section class="wm-card wm-card--gear-tr">
            <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
                <span class="wml-medal">
                    <img src="{{ asset('images/dashboard/microscope.png') }}" alt="Quality & Lab Testing" />
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
