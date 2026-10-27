<x-app-layout>
    <style>
        @font-face { font-family: 'Libre Baskerville'; font-style: normal; font-weight: 400 700; font-display: swap; src: url('{{ asset('fonts/libre-baskerville-latin.woff2') }}') format('woff2'); }

        .dash { position: relative; max-width: 1400px; margin: 0 auto; padding: 0 18px 18px; border-radius: 24px;
            background-color: #ffffff;
            background-image: url('{{ asset('gear-graphic.png') }}');
            background-repeat: no-repeat;
            background-position: right -50px top 200px;
            background-size: 300px;
            border: 1px solid #fff;
            box-shadow: 0 8px 24px rgba(11, 74, 58, .18); }

        .dash-banner { position: relative; z-index: 10; display: flex; align-items: center; gap: 16px; margin: 0 -18px 18px; padding: 14px 24px; border-radius: 23px 23px 0 0; background: #12423c; box-shadow: 0 4px 10px rgba(11, 74, 58, .25); }
        .dash-banner-avatar { margin-left: auto; }
        .dash-banner-avatar .h-14 { height: 2.75rem; width: 2.75rem; }
        .dash-logo { flex: none; width: 40px; height: 40px; padding-right: 16px; box-sizing: content-box; border-right: 1px solid rgba(255, 255, 255, .25); }
        .dash-logo img { display: block; width: 100%; height: 100%; object-fit: contain; }
        .dash-title { margin: 0; font-size: 1.1rem; font-weight: 700; line-height: 1.2; color: #fff; }
        .dash-tiles { display: flex; flex-direction: column; gap: 12px; }
        .dash-tile { position: relative; display: flex; align-items: center; gap: 16px; padding: 12px 18px 12px 12px; border: 1.5px solid #3f8f98; border-radius: 16px; text-decoration: none; color: inherit;
            background-color: #f0f6f2;
            background-image: linear-gradient(180deg, rgba(255, 255, 255, .95), rgba(250, 252, 251, .95)), url('{{ asset('gear-graphic.png') }}');
            background-repeat: no-repeat, no-repeat;
            background-size: auto, 140px; background-position: 0 0, right -45px top -55px;
            box-shadow: 0 4px 10px rgba(11, 74, 58, .14), inset 0 1px 0 #fff; transition: transform .15s, box-shadow .15s; }
        .dash-tile:nth-child(even) { background-position: 0 0, left -45px bottom -55px; }
        .dash-tile:hover { transform: translateY(-1px); box-shadow: 0 8px 18px rgba(11, 74, 58, .2), inset 0 1px 0 #fff; }
        .dash-medal { flex: none; display: flex; align-items: center; justify-content: center; width: 60px; height: 60px; border-radius: 50%; border: 3px solid #c45e2f; color: #ffd2b0;
            background: radial-gradient(circle at 35% 30%, #1f8a6a, #0b4a3a 70%); box-shadow: inset 0 0 0 2px #7d3416, inset 0 4px 10px rgba(0, 0, 0, .35), 0 3px 8px rgba(0, 0, 0, .25); }
        .dash-medal img { width: 88%; height: 88%; object-fit: contain; filter: drop-shadow(0 2px 2px rgba(0, 0, 0, .35)); }
        .dash-text { flex: 1; min-width: 0; }
        .dash-name { display: block; font-size: 1.15rem; font-weight: 800; letter-spacing: .01em; text-transform: uppercase; color: #0b4a3a; }
        .dash-sub { display: block; margin-top: 2px; font-size: .86rem; color: #34524a; }
        .dash-chevron { flex: none; width: 24px; height: 24px; color: #6f8c83; transition: transform .15s, color .15s; }
        .dash-tile:hover .dash-chevron { transform: translateX(3px); color: #0f5642; }

        @media (max-width: 640px) {
            .dash { padding: 0 12px 12px; border-radius: 18px; }
            .dash-banner { gap: 12px; margin: 0 -12px 14px; padding: 10px 16px; border-radius: 17px 17px 0 0; }
            .dash-logo { width: 34px; height: 34px; padding-right: 12px; }
            .dash-title { font-size: 1rem; }
            .dash-tile { gap: 12px; padding: 10px 12px 10px 10px; }
            .dash-medal { width: 50px; height: 50px; }
            .dash-name { font-size: 1rem; }
            .dash-sub { font-size: .8rem; }
        }
    </style>

    <div class="py-1 -mx-4 sm:-mx-6 lg:-mx-8 -mt-6">
        <div class="dash">
            <div class="dash-banner">
                <span class="dash-logo">
                    <img src="{{ asset('condimentum-logo-mark.png') }}" alt="Wet Mustard Booking System" />
                </span>
                <h1 class="dash-title">Wet Mustard Booking System</h1>
                <div class="dash-banner-avatar">
                    <livewire:layout.user-menu />
                </div>
            </div>

            <div class="dash-tiles">
                @foreach ($tiles as $tile)
                    @php
                        $iconFile = 'images/dashboard/'.$tile['icon'].'.png';
                    @endphp
                    <a href="{{ route($tile['route']) }}" wire:navigate class="dash-tile">
                        <span class="dash-medal">
                            @if (file_exists(public_path($iconFile)))
                                <img src="{{ asset($iconFile) }}" alt="" />
                            @else
                                <x-menu-tile-icon :icon="$tile['icon']" />
                            @endif
                        </span>

                        <span class="dash-text">
                            <span class="dash-name">{{ $tile['title'] }}</span>
                            @if ($tile['subtitle'])
                                <span class="dash-sub">{{ $tile['subtitle'] }}</span>
                            @endif
                        </span>

                        <svg class="dash-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6" />
                        </svg>
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>
