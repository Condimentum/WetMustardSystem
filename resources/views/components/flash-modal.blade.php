{{--
    Flash modal for Settings-area change confirmations. Replaces the old inline banner: a tinted card pops up with a
    status word and the message, then fades away on its own (or on click / Esc).
    Usage: <x-flash-modal :message="$flash" :level="$flashLevel" />   (level: success | warning | error)
    `property` names the Livewire property to clear when the modal closes so the same message can show again.
--}}
@props(['message' => null, 'level' => 'success', 'property' => 'flash'])

@php
    $level = in_array($level, ['success', 'warning', 'error'], true) ? $level : 'success';

    $config = [
        'success' => ['word' => 'Success!', 'base' => 2800],
        'warning' => ['word' => 'Alert!', 'base' => 4500],
        'error' => ['word' => 'Failed!', 'base' => 4500],
    ][$level];

    // Longer messages need more reading time.
    $duration = max($config['base'], min(8000, 2000 + mb_strlen((string) $message) * 40));
@endphp

@if (filled($message))
    <div
        x-data="{
            show: false,
            timer: null,
            init() {
                this.$nextTick(() => {
                    this.show = true;
                    this.timer = setTimeout(() => this.close(), {{ $duration }});
                });
            },
            close() {
                if (! this.show) return;
                clearTimeout(this.timer);
                this.show = false;
                setTimeout(() => {
                    this.$wire.$set(@js($property), null, false);
                    this.$root.remove();
                }, 400);
            },
        }"
        x-show="show"
        x-cloak
        x-transition:enter="wmfx-fade-in"
        x-transition:enter-start="wmfx-fade-from"
        x-transition:enter-end="wmfx-fade-to"
        x-transition:leave="wmfx-fade-out"
        x-transition:leave-start="wmfx-fade-to"
        x-transition:leave-end="wmfx-fade-from"
        x-on:click="close()"
        x-on:keydown.escape.window="close()"
        class="wmfx-overlay"
        role="status"
        aria-live="polite"
    >
        <div class="wmfx-card wmfx-card--{{ $level }}" :class="show ? 'wmfx-card--in' : ''">
            <p class="wmfx-card__word">{{ $config['word'] }}</p>
            <p class="wmfx-card__message">{{ $message }}</p>
        </div>

        <style>
            .wmfx-overlay {
                position: fixed;
                inset: 0;
                z-index: 9990;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 16px;
                background: rgba(15, 23, 42, .45);
                backdrop-filter: blur(3px);
                cursor: pointer;
            }

            .wmfx-fade-in, .wmfx-fade-out { transition: opacity .35s ease; }
            .wmfx-fade-from { opacity: 0; }
            .wmfx-fade-to { opacity: 1; }

            .wmfx-card {
                width: min(360px, 100%);
                padding: 30px 26px 28px;
                border: 1px solid rgba(255, 255, 255, .7);
                border-radius: 14px;
                text-align: center;
                font-family: Figtree, ui-sans-serif, system-ui, sans-serif;
                box-shadow: 0 18px 50px rgba(0, 0, 0, .4), inset 0 1px 0 rgba(255, 255, 255, .25);
                opacity: 0;
                transform: translateY(28px) scale(.85);
                transition: transform .45s cubic-bezier(.2, 1.3, .4, 1), opacity .3s ease;
            }

            .wmfx-card--in { opacity: 1; transform: none; }

            .wmfx-card--success { background: linear-gradient(150deg, #34a265, #1d6e3d); color: #fff; }
            .wmfx-card--error { background: linear-gradient(150deg, #dc3b3b, #962121); color: #fff; }
            .wmfx-card--warning { background: linear-gradient(150deg, #fbd34d, #e0a800); color: #3a2c00; }

            .wmfx-card__word {
                margin: 0;
                font-size: 2.4rem;
                font-weight: 700;
                letter-spacing: 1px;
                line-height: 1.1;
            }

            .wmfx-card__message {
                margin: 12px 0 0;
                max-height: 40vh;
                overflow-y: auto;
                font-size: 1rem;
                font-weight: 500;
                line-height: 1.4;
                overflow-wrap: anywhere;
            }

            @media (prefers-reduced-motion: reduce) {
                .wmfx-card { transition: none; }
            }
        </style>
    </div>
@endif
