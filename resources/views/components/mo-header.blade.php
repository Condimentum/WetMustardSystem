{{-- Manufacturing order header card shared by the MO Workspace and the batch page. --}}
@props([
    'systemType' => '',
    'moNumber' => '—',
    'product' => '—',
    'description' => '—',
    'dateLabel' => 'Due Date',
    'dateValue' => '—',
    'planned' => 0.0,
    'made' => 0.0,
    'outstanding' => 0.0,
    'batches' => 0,
    'fmt',
])

@php
    $moStatus = strtoupper(trim((string) $systemType));
    $status = match ($moStatus) {
        'C', 'CANCELLED', 'CANCELED' => ['bg' => '#9b2c2c', 'dot' => '#fecaca', 'label' => 'Cancelled'],
        'F' => ['bg' => '#1e4f8a', 'dot' => '#bfdbfe', 'label' => 'Firm'],
        'R' => ['bg' => '#9a5b12', 'dot' => '#fde68a', 'label' => 'Released'],
        'I' => ['bg' => '#2f5d3a', 'dot' => '#86efac', 'label' => 'Issued'],
        default => ['bg' => '#4b5563', 'dot' => '#d1d5db', 'label' => $moStatus !== '' ? $moStatus : 'Unknown'],
    };

    $planned = (float) $planned;
    $madeShare = $planned > 0 ? min(1, max(0, (float) $made / $planned)) : 0;
    $outstandingShare = $planned > 0 ? min(1, max(0, (float) $outstanding / $planned)) : 0;

    // Clock face: shade the outstanding share clockwise from 12 o'clock.
    $angle = $outstandingShare * 2 * M_PI;
    $wedgeX = round(30 + 24 * sin($angle), 2);
    $wedgeY = round(30 - 24 * cos($angle), 2);
    $wedgeLarge = $outstandingShare > 0.5 ? 1 : 0;

    $plannedText = $fmt($planned);
    $coinSize = match (true) {
        strlen($plannedText) <= 4 => '.95rem',
        strlen($plannedText) <= 6 => '.75rem',
        default => '.6rem',
    };
    $img = fn (string $name): string => asset('images/mo-header/'.$name);
@endphp

<style>
    @font-face { font-family: 'Libre Baskerville'; font-style: normal; font-weight: 400 700; font-display: swap; src: url('{{ asset('fonts/libre-baskerville-latin.woff2') }}') format('woff2'); }

    .moh { position: relative; overflow: hidden; border: 1px solid #e3d8bf; border-radius: 18px; padding: 24px 28px 20px; container-type: inline-size; box-shadow: 0 8px 24px rgba(110, 85, 35, .12);
        background: #f8f4ea url('{{ asset('workspace-bg.png') }}?v={{ filemtime(public_path('workspace-bg.png')) }}') center / cover no-repeat; }
    .moh-serif { font-family: 'Libre Baskerville', Georgia, 'Times New Roman', serif; font-variant-numeric: lining-nums; font-feature-settings: "lnum" 1; }

    .moh-top { display: grid; grid-template-columns: 150px minmax(0, 1fr); grid-template-areas: "photo head" "photo tiles"; column-gap: 26px; row-gap: 14px; align-items: center; }
    .moh-photo { grid-area: photo; width: 150px; height: 150px; padding: 7px; border-radius: 50%; background: linear-gradient(145deg, #f4f4f4, #9ca3af 40%, #e5e7eb 60%, #6b7280); box-shadow: 0 8px 16px rgba(0, 0, 0, .28), inset 0 1px 2px rgba(255, 255, 255, .8); }
    .moh-photo img { display: block; width: 100%; height: 100%; border-radius: 50%; object-fit: cover; object-position: 55% 55%; box-shadow: inset 0 0 0 2px rgba(255, 255, 255, .5); }

    .moh-head { grid-area: head; display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
    .moh-title { font-size: 1.7rem; font-weight: 700; line-height: 1.1; color: #1a1a1a; letter-spacing: .01em; }
    .moh-sub { margin-top: 6px; font-size: .8rem; letter-spacing: .12em; color: #4b4636; }
    .moh-status { display: inline-flex; flex: none; align-items: center; gap: 8px; padding: 7px 16px; border-radius: 999px; color: #fff; font-size: .82rem; font-weight: 700; box-shadow: inset 0 1px 0 rgba(255, 255, 255, .25), 0 3px 8px rgba(0, 0, 0, .25); }
    .moh-status-dot { width: 8px; height: 8px; border-radius: 50%; }

    .moh-tiles { grid-area: tiles; display: flex; flex-wrap: wrap; gap: 12px; }
    .moh-tile { flex: 1 1 auto; display: flex; align-items: center; gap: 10px; min-width: 0; padding: 14px 16px; border: 1px solid rgba(214, 202, 176, .9); border-radius: 12px; background: linear-gradient(180deg, rgba(255, 255, 255, .8), rgba(247, 243, 233, .65)); box-shadow: 0 4px 10px rgba(110, 85, 35, .10), inset 0 1px 0 #fff; }
    .moh-tile-icon { flex: none; color: #4b4636; }
    .moh-tile--desc { flex: 1.6 1 250px; }
    .moh-tile-plant { flex: none; width: 54px; height: 64px; object-fit: contain; }
    .moh-label { font-size: .7rem; text-transform: uppercase; letter-spacing: .05em; color: #3a3a3a; }
    .moh-value { margin-top: 4px; font-size: 1.05rem; font-weight: 700; color: #1f1f1f; white-space: nowrap; }
    .moh-value--desc { font-size: .9rem; line-height: 1.3; white-space: normal; }

    .moh-qty-bar { margin-top: 20px; padding: 11px 18px; border-radius: 12px 12px 0 0; color: #fff; font-size: .78rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase;
        background: radial-gradient(ellipse 60% 140% at 25% 0%, rgba(90, 84, 170, .55), transparent 70%), radial-gradient(ellipse 50% 120% at 80% 100%, rgba(70, 64, 150, .4), transparent 70%), linear-gradient(180deg, #2a2766, #1b1946);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, .15); }
    .moh-qty { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); border: 1px solid #e3d8bf; border-top: 0; border-radius: 0 0 12px 12px; background: rgba(255, 253, 247, .88); }
    .moh-qty-cell { display: flex; align-items: center; justify-content: center; gap: 12px; min-width: 0; padding: 14px 12px; border-left: 1px solid #e3d8bf; }
    .moh-qty-cell:first-child { border-left: 0; }
    .moh-qty-img { flex: none; width: 58px; height: 58px; object-fit: contain; }
    .moh-qty-value { margin-top: 3px; font-size: 1.15rem; font-weight: 700; }
    .moh-coin { flex: none; display: flex; align-items: center; justify-content: center; width: 58px; height: 58px; border-radius: 50%; border: 2px solid #a07a2c; color: #3b2a0a; font-weight: 700;
        background: radial-gradient(circle at 35% 30%, #fff3c4, #e6bf55 45%, #b8892f 80%, #8a6420); box-shadow: inset 0 0 0 4px rgba(255, 240, 190, .45), 0 3px 6px rgba(0, 0, 0, .25); }
    .moh-bar { width: 110px; height: 8px; margin-top: 6px; overflow: hidden; border: 1px solid #cfc6b0; border-radius: 999px; background: #fff; }
    .moh-bar > span { display: block; height: 100%; background: linear-gradient(90deg, #2f6b3a, #4a8f4f); }

    .moh-flower { position: absolute; right: -4px; bottom: 0; height: 104px; pointer-events: none; }
    .moh-foot { padding-top: 12px; }
    .moh-foot:empty { display: none; }

    /* Layout follows the card width (not the screen), so it also works beside the nav on mid-size screens. */
    @container (max-width: 900px) {
        .moh-qty { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .moh-qty-cell:nth-child(odd) { border-left: 0; }
        .moh-qty-cell:nth-child(n+3) { border-top: 1px solid #e3d8bf; }
        .moh-flower { display: none; }
    }
    @media (max-width: 767px) {
        .moh { padding: 16px 14px 14px; }
    }
    @container (max-width: 620px) {
        .moh-top { grid-template-columns: 72px minmax(0, 1fr); grid-template-areas: "photo head" "tiles tiles"; column-gap: 14px; }
        .moh-photo { width: 72px; height: 72px; padding: 4px; }
        .moh-head { flex-wrap: wrap; }
        .moh-title { font-size: 1.15rem; }
        .moh-tile { padding: 10px 12px; }
        .moh-tile-plant { width: 38px; height: 46px; }
        .moh-qty-cell { flex-wrap: wrap; gap: 8px; padding: 12px 8px; text-align: center; }
        .moh-qty-img { width: 42px; height: 42px; }
        .moh-coin { display: none; }
        .moh-tile--desc { flex-basis: 100%; }
        .moh-bar { width: 80px; margin-left: auto; margin-right: auto; }
    }
</style>

<div {{ $attributes->merge(['class' => 'moh']) }}>
    <div class="moh-top">
        <div class="moh-photo">
            <img src="{{ $img('mustard-jar.png') }}" alt="Mustard" />
        </div>

        <div class="moh-head">
            <div>
                <div class="moh-title moh-serif">MANUFACTURING ORDER</div>
                <div class="moh-sub">DETAILS</div>
            </div>
            <span class="moh-status" style="background:{{ $status['bg'] }};">
                <span class="moh-status-dot" style="background:{{ $status['dot'] }};"></span>
                {{ $status['label'] }}
            </span>
        </div>

        <div class="moh-tiles">
            <div class="moh-tile">
                <svg class="moh-tile-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                <div style="min-width:0;">
                    <div class="moh-label">MO Number</div>
                    <div class="moh-value moh-serif" style="color:#1f5130;">{{ $moNumber }}</div>
                </div>
            </div>

            <div class="moh-tile">
                <svg class="moh-tile-icon" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="2" y="4" width="2" height="16"/><rect x="5.5" y="4" width="1" height="16"/><rect x="8" y="4" width="2" height="16"/><rect x="11.5" y="4" width="1" height="16"/><rect x="14" y="4" width="3" height="16"/><rect x="18.5" y="4" width="1" height="16"/><rect x="21" y="4" width="1.5" height="16"/></svg>
                <div style="min-width:0;">
                    <div class="moh-label">Product</div>
                    <div class="moh-value moh-serif">{{ $product }}</div>
                </div>
            </div>

            <div class="moh-tile moh-tile--desc">
                <img class="moh-tile-plant" src="{{ $img('plant.png') }}" alt="" />
                <div style="min-width:0;">
                    <div class="moh-label">Product Description</div>
                    <div class="moh-value moh-value--desc moh-serif">{{ $description }}</div>
                </div>
            </div>

            <div class="moh-tile">
                <svg class="moh-tile-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4M7.5 14h1M11.5 14h1M15.5 14h1M7.5 17.5h1M11.5 17.5h1"/></svg>
                <div style="min-width:0;">
                    <div class="moh-label">{{ $dateLabel }}</div>
                    <div class="moh-value moh-serif">{{ $dateValue }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="moh-qty-bar">Quantities</div>
    <div class="moh-qty">
        <div class="moh-qty-cell">
            <img class="moh-qty-img" src="{{ $img('arrow.png') }}" alt="" />
            <div>
                <div class="moh-label">On Order</div>
                <div class="moh-qty-value moh-serif" style="color:#8a6a2a;">{{ $plannedText }}</div>
            </div>
            <div class="moh-coin moh-serif" style="font-size:{{ $coinSize }};" aria-hidden="true">{{ $plannedText }}</div>
        </div>

        <div class="moh-qty-cell">
            <img class="moh-qty-img" src="{{ $img('jars.png') }}" alt="" />
            <div>
                <div class="moh-label">Made</div>
                <div class="moh-qty-value moh-serif" style="color:#1f5130;">{{ $fmt((float) $made) }}</div>
                <div class="moh-bar" title="{{ round($madeShare * 100) }}% of order made"><span style="width:{{ round($madeShare * 100, 1) }}%;"></span></div>
            </div>
        </div>

        <div class="moh-qty-cell">
            <svg class="moh-qty-img" viewBox="0 0 60 60" aria-hidden="true">
                <circle cx="30" cy="30" r="28" fill="#3a3a3a"/>
                <circle cx="30" cy="30" r="25.5" fill="#f7f1e1"/>
                @if ($outstandingShare >= 0.999)
                    <circle cx="30" cy="30" r="24" fill="#e3a82b" opacity=".85"/>
                @elseif ($outstandingShare > 0)
                    <path d="M30 30 L30 6 A24 24 0 {{ $wedgeLarge }} 1 {{ $wedgeX }} {{ $wedgeY }} Z" fill="#e3a82b" opacity=".85"/>
                @endif
                @for ($t = 0; $t < 12; $t++)
                    <line x1="30" y1="7" x2="30" y2="{{ $t % 3 === 0 ? 11 : 9.5 }}" stroke="#2b2b2b" stroke-width="{{ $t % 3 === 0 ? 2 : 1.2 }}" transform="rotate({{ $t * 30 }} 30 30)"/>
                @endfor
                <line x1="30" y1="30" x2="30" y2="15" stroke="#1f1f1f" stroke-width="2.4" stroke-linecap="round"/>
                <line x1="30" y1="30" x2="{{ $wedgeX }}" y2="{{ $wedgeY }}" stroke="#1f1f1f" stroke-width="1.6" stroke-linecap="round"/>
                <circle cx="30" cy="30" r="2.4" fill="#1f1f1f"/>
            </svg>
            <div>
                <div class="moh-label">Outstanding</div>
                <div class="moh-qty-value moh-serif" style="color:#2b2a6b;">{{ $fmt((float) $outstanding) }}</div>
            </div>
        </div>

        <div class="moh-qty-cell">
            <img class="moh-qty-img" src="{{ $img('tanks.png') }}" alt="" />
            <div>
                <div class="moh-label">Batches</div>
                <div class="moh-qty-value moh-serif" style="color:#5b3b8f;">{{ $batches }}</div>
            </div>
        </div>
    </div>

    <img class="moh-flower" src="{{ $img('flower.png') }}" alt="" />

    <div class="moh-foot">{{ $slot }}</div>
</div>
