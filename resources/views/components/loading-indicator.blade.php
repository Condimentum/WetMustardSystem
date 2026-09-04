<style>
    /* Livewire's built-in top progress bar (#nprogress) fires for EVERY ajax
       request (wire:click/wire:submit/etc), not just full-page loads/
       wire:navigate transitions covered by the jar overlay below - keep both
       visible so ordinary component actions still show a loading cue. */
    #nprogress .bar { background: #f59e0b !important; height: 3px !important; }
</style>

<div
    x-data="{
        show: true,
        percent: 0,
        timer: null,
        start() {
            clearInterval(this.timer);
            this.percent = 1;
            this.show = true;
            this.timer = setInterval(() => {
                this.percent = Math.min(90, this.percent + Math.max(0.5, (90 - this.percent) / 12));
            }, 100);
        },
        finish() {
            clearInterval(this.timer);
            this.percent = 100;
            setTimeout(() => { this.show = false; this.percent = 0; }, 350);
        },
        init() {
            // Covers full page loads/refreshes (not just wire:navigate): show
            // the overlay immediately, ramp it, then finish once the browser
            // reports the page is fully loaded.
            this.start();
            if (document.readyState === 'complete') {
                this.finish();
            } else {
                window.addEventListener('load', () => this.finish());
            }
        },
    }"
    x-on:livewire:navigating.window="start()"
    x-on:livewire:navigated.window="finish()"
    x-show="show"
    x-transition.opacity.duration.200ms
    class="fixed inset-0 z-[9999] flex items-center justify-center"
>
    <div class="absolute inset-0 bg-white/70 backdrop-blur-sm"></div>

    <div class="relative flex flex-col items-center gap-3">
        <div class="relative" style="width:110px;height:140px;">
            {{-- Amber fill rises behind the jar outline; the outline PNG's white
                 background blends away via multiply, leaving only its black
                 line-art visible on top of the fill (no real alpha channel needed). --}}
            <div
                class="absolute rounded-b-2xl"
                style="left:19%;right:19%;bottom:15%;background:linear-gradient(180deg,#fde68a 0%,#f59e0b 55%,#b45309 100%);transition:height .18s ease-out;"
                x-bind:style="'left:19%;right:19%;bottom:15%;background:linear-gradient(180deg,#fde68a 0%,#f59e0b 55%,#b45309 100%);transition:height .18s ease-out;height:' + (percent / 100 * 62) + '%'"
            ></div>
            <img
                src="{{ asset('mustard-jar-loading.png') }}"
                alt=""
                class="absolute inset-0 w-full h-full object-contain pointer-events-none select-none"
                style="mix-blend-mode:multiply;"
            />
        </div>
        <div class="text-lg font-extrabold text-amber-800" x-text="Math.round(percent) + '%'"></div>
    </div>
</div>
