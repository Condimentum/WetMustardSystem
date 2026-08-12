<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Quality & Lab Testing')] class extends Component {
}; ?>

<div class="py-8">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="bg-slate-900 px-6 py-4 flex items-center justify-between">
                <h2 class="text-xl font-semibold text-white">Quality &amp; Lab Testing</h2>
                <a href="{{ route('dashboard') }}" wire:navigate class="text-sm text-white/90 hover:text-white underline">Back to Main Menu</a>
            </div>

            <div class="p-6 grid gap-4 md:grid-cols-3">
                <a href="{{ route('quality.lab-testing.ibc-traceability') }}" wire:navigate class="rounded-xl border border-slate-200 p-4 hover:border-indigo-300 hover:bg-indigo-50/40 transition">
                    <div class="font-semibold text-slate-900">IBC Traceability</div>
                    <div class="text-xs text-slate-500 mt-1">WM003 Vinegar IBC Traceability</div>
                </a>

                <a href="{{ route('quality.lab-testing.wet-mustard-lab') }}" wire:navigate class="rounded-xl border border-slate-200 p-4 hover:border-indigo-300 hover:bg-indigo-50/40 transition">
                    <div class="font-semibold text-slate-900">Wet Mustard Lab Testing</div>
                    <div class="text-xs text-slate-500 mt-1">WM005 Quality analytical checks</div>
                </a>

                <a href="{{ route('quality.lab-testing.rinse-water-test') }}" wire:navigate class="rounded-xl border border-slate-200 p-4 hover:border-indigo-300 hover:bg-indigo-50/40 transition">
                    <div class="font-semibold text-slate-900">Rinse Water Test</div>
                    <div class="text-xs text-slate-500 mt-1">WM010 Chemical &amp; sulphite checks</div>
                </a>
            </div>
        </div>
    </div>
</div>
