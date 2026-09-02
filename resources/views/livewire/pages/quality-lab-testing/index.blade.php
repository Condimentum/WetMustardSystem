<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Quality & Lab Testing')] class extends Component {
}; ?>

<div class="py-8">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <div style="background:#fff;border:1px solid #dbe1ea;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,0.08);">
            <div style="padding:24px 26px;background:linear-gradient(135deg,#f8fafc 0%,#e0ecff 100%);border-bottom:1px solid #dbe1ea;">
                <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
                    <div style="width:56px;height:56px;background:#ecfdf5;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid #86efac;overflow:hidden;flex-shrink:0;">
                        <img src="{{ asset('lab-testing-icon.png') }}" alt="Quality & Lab Testing" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" />
                    </div>

                    <div>
                        <div style="font-size:1.3rem;font-weight:900;color:#1a1a2e;letter-spacing:-0.02em;line-height:1;">QUALITY &amp; LAB TESTING</div>
                        <div style="font-size:0.75rem;font-weight:700;color:#64748b;letter-spacing:.14em;margin-top:4px;">WM005 &middot; WM010 CHECK SHEETS</div>
                    </div>

                    <a href="{{ route('dashboard') }}" wire:navigate style="margin-left:auto;display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;font-size:12px;font-weight:800;text-decoration:none;">
                        Back to Main Menu
                    </a>
                </div>
            </div>

            <div class="p-6 grid gap-4 md:grid-cols-2">
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
