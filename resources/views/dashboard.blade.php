<x-app-layout>
    <style>
        @font-face { font-family: 'Libre Baskerville'; font-style: normal; font-weight: 400 700; font-display: swap; src: url('{{ asset('fonts/libre-baskerville-latin.woff2') }}') format('woff2'); }

        .dash { position: relative; max-width: 42rem; margin: 0 auto; padding: 18px; border-radius: 24px;
            background-color: #f8f4ea;
            background-image: linear-gradient(rgba(248, 244, 234, .82), rgba(248, 244, 234, .82)), url('{{ asset('gear-graphic.png') }}'), url('{{ asset('workspace-bg.png') }}?v={{ filemtime(public_path('workspace-bg.png')) }}');
            background-repeat: no-repeat, no-repeat, no-repeat;
            background-position: 0 0, right -40px top -30px, center;
            background-size: auto, 280px, cover; }

        .dash-banner { position: relative; overflow: hidden; display: flex; align-items: center; gap: 18px; min-height: 150px; margin-bottom: 18px; padding: 24px 26px; border-radius: 20px; box-shadow: 0 8px 22px rgba(80, 60, 20, .22);
            background: url('{{ asset('dashboard-header-bg.png') }}?v={{ filemtime(public_path('dashboard-header-bg.png')) }}') center / cover no-repeat; }
        .dash-banner::before { content: ""; position: absolute; inset: 0; background: linear-gradient(90deg, rgba(255, 250, 235, .55), rgba(255, 250, 235, .15) 70%, transparent); }
        .dash-banner-avatar { position: absolute; top: 14px; right: 14px; z-index: 5; }
        .dash-logo { position: relative; flex: none; width: 72px; height: 72px; padding: 5px; border-radius: 18px; background: #f8f5ec; border: 2px solid #d9d3bf; box-shadow: 0 4px 10px rgba(0, 0, 0, .12); }
        .dash-logo img { display: block; width: 100%; height: 100%; border-radius: 13px; object-fit: cover; }
        .dash-title { position: relative; margin: 0; font-family: 'Libre Baskerville', Georgia, serif; font-size: 1.75rem; font-weight: 700; line-height: 1.2; color: #1c1d26; text-shadow: 0 0 12px rgba(255, 250, 235, .95), 0 0 4px rgba(255, 250, 235, .9); }

        .dash-tiles { display: flex; flex-direction: column; gap: 12px; }
        .dash-tile { display: flex; align-items: center; gap: 16px; padding: 12px 18px 12px 12px; border: 1px solid #e2d7bf; border-radius: 16px; text-decoration: none; color: inherit;
            background: linear-gradient(180deg, #fdfbf5, #f2ecde); box-shadow: 0 4px 10px rgba(80, 60, 20, .14), inset 0 1px 0 #fff; transition: transform .15s, box-shadow .15s; }
        .dash-tile:hover { transform: translateY(-1px); box-shadow: 0 8px 18px rgba(80, 60, 20, .2), inset 0 1px 0 #fff; }
        .dash-medal { flex: none; display: flex; align-items: center; justify-content: center; width: 60px; height: 60px; border-radius: 50%; border: 3px solid #c9a24a; color: #e8c877;
            background: radial-gradient(circle at 35% 30%, #3a4686, #1c2552 70%); box-shadow: inset 0 0 0 2px #7a5f22, inset 0 4px 10px rgba(0, 0, 0, .35), 0 3px 8px rgba(0, 0, 0, .25); }
        .dash-medal img { width: 88%; height: 88%; object-fit: contain; filter: drop-shadow(0 2px 2px rgba(0, 0, 0, .35)); }
        .dash-text { flex: 1; min-width: 0; }
        .dash-name { display: block; font-size: 1.15rem; font-weight: 700; color: #1f2330; }
        .dash-sub { display: block; margin-top: 2px; font-size: .86rem; color: #4b4636; }
        .dash-chevron { flex: none; width: 24px; height: 24px; color: #9a9384; transition: transform .15s, color .15s; }
        .dash-tile:hover .dash-chevron { transform: translateX(3px); color: #6b5a2f; }

        @media (max-width: 640px) {
            .dash { padding: 12px; border-radius: 18px; }
            .dash-banner { min-height: 110px; gap: 12px; padding: 16px; }
            .dash-logo { width: 54px; height: 54px; border-radius: 14px; }
            .dash-logo img { border-radius: 10px; }
            .dash-title { font-size: 1.2rem; }
            .dash-banner-avatar { top: 10px; right: 10px; }
            .dash-tile { gap: 12px; padding: 10px 12px 10px 10px; }
            .dash-medal { width: 50px; height: 50px; }
            .dash-name { font-size: 1rem; }
            .dash-sub { font-size: .8rem; }
        }
    </style>

    <div class="py-8">
        <div class="dash">
            <div class="dash-banner">
                <div class="dash-banner-avatar">
                    <livewire:layout.user-menu />
                </div>
                <span class="dash-logo">
                    <img src="{{ asset('wet-mustard-booking-icon.png') }}" alt="Wet Mustard Booking System" />
                </span>
                <h1 class="dash-title">Wet Mustard Booking System</h1>
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
