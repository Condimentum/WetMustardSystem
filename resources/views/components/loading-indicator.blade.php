<style>
    #nprogress { display: none !important; }
</style>

<div
    x-data="{
        show: true,
        activeRequests: 0,
        start() {
            this.show = true;
        },
        finish() {
            setTimeout(() => { this.show = false; }, 350);
        },
        requestStarted() {
            this.activeRequests++;
            this.start();
        },
        requestFinished() {
            this.activeRequests = Math.max(0, this.activeRequests - 1);
            if (this.activeRequests === 0) this.finish();
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

            document.addEventListener('livewire:init', () => {
                Livewire.hook('commit', ({ succeed, fail }) => {
                    this.requestStarted();
                    succeed(() => this.requestFinished());
                    fail(() => this.requestFinished());
                });
            });
        },
    }"
    x-on:livewire:navigating.window="requestStarted()"
    x-on:livewire:navigated.window="requestFinished()"
    x-show="show"
    x-transition.opacity.duration.200ms
    class="fixed inset-0 z-[9999] flex items-center justify-center"
>
    <div class="absolute inset-0 bg-white/70 backdrop-blur-sm"></div>

    <div class="relative flex items-center justify-center" style="width:150px;height:150px;">
        <img
            src="{{ asset('assets/loadingbar.svg') }}"
            alt="Loading"
            class="h-full w-full object-contain pointer-events-none select-none"
        />
    </div>
</div>
