<style>
    /* Replaced by the circular indicator below. */
    #nprogress { display: none !important; }
</style>

<div
    x-data="{
        show: false,
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
    }"
    x-on:livewire:navigating.window="start()"
    x-on:livewire:navigated.window="finish()"
    x-show="show"
    x-transition.opacity.duration.200ms
    style="display:none;"
    class="fixed inset-0 z-[9999] flex items-center justify-center"
>
    <div class="absolute inset-0 bg-white/60 backdrop-blur-sm"></div>

    <div class="relative flex items-center justify-center" style="width:120px;height:120px;">
        <svg width="120" height="120" viewBox="0 0 120 120" style="transform:rotate(-90deg);">
            <defs>
                <linearGradient id="loadingIndicatorGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" stop-color="#f59e0b" />
                    <stop offset="100%" stop-color="#4f46e5" />
                </linearGradient>
            </defs>
            <circle cx="60" cy="60" r="52" fill="none" stroke="#e2e8f0" stroke-width="10" />
            <circle
                cx="60" cy="60" r="52" fill="none"
                stroke="url(#loadingIndicatorGradient)"
                stroke-width="10"
                stroke-linecap="round"
                stroke-dasharray="326.7"
                x-bind:stroke-dashoffset="326.7 * (1 - percent / 100)"
                style="transition: stroke-dashoffset 0.15s linear;"
            />
        </svg>
        <div class="absolute text-xl font-extrabold text-slate-800" x-text="Math.round(percent) + '%'"></div>
    </div>
</div>
