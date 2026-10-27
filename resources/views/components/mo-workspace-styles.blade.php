{{-- Green/gear theme for the Batch & Pallecon overview on the MO Workspace. --}}
<style>
    .wm-page { background: #ffffff url('{{ asset('gear-graphic.png') }}') no-repeat right -60px top 40px / 300px; border: 1px solid #fff; border-radius: 24px; padding: 24px; box-shadow: 0 10px 30px rgba(11, 74, 58, .16); }

    .wm-card { position: relative; border: 1.5px solid #3f8f98; border-radius: 16px; padding: 22px 24px 24px; container-type: inline-size; box-shadow: 0 8px 24px rgba(11, 74, 58, .10);
        background: linear-gradient(rgba(255, 255, 255, .93), rgba(255, 255, 255, .93)), url('{{ asset('gear-graphic.png') }}') no-repeat, #fff; background-size: auto, 230px; }
    .wm-card--gear-tr { background-position: 0 0, right -60px top -70px; }
    .wm-card--gear-bl { background-position: 0 0, left -70px bottom -80px; }
    .wm-title { margin: 0; font-size: 1.35rem; font-weight: 800; color: #0b4a3a; letter-spacing: -.01em; }

    .wm-grid { display: grid; align-items: center; column-gap: 16px; }
    .wm-grid--batch { grid-template-columns: minmax(200px, 2.4fr) minmax(56px, .6fr) minmax(110px, 1fr) minmax(130px, 1fr) minmax(150px, 1.2fr) minmax(150px, 1fr); }
    .wm-grid--pallecon { grid-template-columns: minmax(200px, 2.6fr) minmax(70px, .7fr) minmax(120px, 1fr) minmax(120px, 1fr) minmax(130px, 1fr); }
    .wm-head > div { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #2f3d47; }
    .wm-head--batch { padding: 0 19px 0 25px; }

    .wm-rows { display: flex; flex-direction: column; gap: 10px; margin-top: 12px; }
    .wm-row { background: #fbfdfb; border: 1px solid #d3e6de; border-left: 6px solid var(--wm-strip, #3f8f98); border-radius: 14px; padding: 14px 18px; box-shadow: 0 2px 8px rgba(11, 74, 58, .08); font-size: .95rem; color: #1f2a33; }
    .wm-ref { display: flex; align-items: center; gap: 12px; min-width: 0; }
    .wm-ref-text { font-weight: 600; overflow-wrap: break-word; }
    .wm-sub { font-size: .75rem; color: #6f8c83; margin-top: 2px; }

    .wm-pill { display: inline-flex; align-items: center; gap: 8px; padding: 7px 14px; border-radius: 999px; font-size: .82rem; font-weight: 700; color: #fff; white-space: nowrap; box-shadow: inset 0 1px 0 rgba(255, 255, 255, .25), 0 1px 3px rgba(0, 0, 0, .15); }
    .wm-pill-dot { width: 8px; height: 8px; border-radius: 50%; flex: none; }

    .wm-alloc { position: relative; overflow: hidden; display: inline-flex; align-items: center; gap: 8px; min-width: 130px; padding: 7px 14px; border-radius: 999px; font-size: .82rem; font-weight: 700; line-height: 1.15; text-decoration: none; }
    .wm-alloc > :not(.wm-alloc-fill) { position: relative; }
    .wm-alloc-fill { position: absolute; inset: 0 auto 0 0; border-radius: 999px; }
    .wm-alloc--full { background: linear-gradient(180deg, #e2f0ec, #c9e2da); border: 1px solid #9fc8bc; color: #0b4a3a; }
    .wm-alloc--partial { background: #0a3f31; border: 1px solid #072f25; color: #fff; }
    .wm-alloc--partial .wm-alloc-fill { background: linear-gradient(90deg, #2b6f86, #3e97a8); }
    .wm-alloc--awaiting { background: linear-gradient(180deg, #e9dcbc, #d8c697); border: 1px solid #c7b17c; color: #0b4a3a; }
    .wm-alloc--awaiting .wm-alloc-fill { background: linear-gradient(90deg, #c9a24a, #b8892f); }
    .wm-orb { flex: none; width: 16px; height: 16px; border-radius: 50%; background: radial-gradient(circle at 35% 30%, #b6f2a8, #3aa33a 55%, #1d6b24); box-shadow: 0 0 6px rgba(58, 163, 58, .6); }

    .wm-action { display: flex; align-items: center; justify-content: flex-end; gap: 10px; }
    .wm-link { color: #0b4a3a; font-size: .88rem; font-weight: 600; text-align: center; text-decoration: underline; text-underline-offset: 3px; }
    .wm-btn-continue { display: inline-flex; align-items: center; padding: 12px 22px; border-radius: 999px; border: 1px solid #0a3f31; background: linear-gradient(180deg, #1d7a5f, #0f5642); color: #fff; font-size: .88rem; font-weight: 700; text-decoration: none; box-shadow: inset 0 1px 0 rgba(255, 255, 255, .3), 0 2px 5px rgba(11, 74, 58, .35); }
    .wm-btn-continue:hover { filter: brightness(1.07); }

    .wm-info { position: relative; display: inline-flex; color: #6b7280; cursor: help; outline: none; }
    .wm-tip { position: absolute; top: calc(100% + 8px); right: -6px; z-index: 20; width: 220px; padding: 10px 12px; border-radius: 8px; background: #0a3f31; color: #f3f4f6; font-size: .78rem; font-weight: 400; line-height: 1.35; text-align: left; box-shadow: 0 6px 16px rgba(0, 0, 0, .25); opacity: 0; visibility: hidden; transition: opacity .15s; }
    .wm-tip::before { content: ""; position: absolute; top: -6px; right: 10px; border: 6px solid transparent; border-top: 0; border-bottom-color: #0a3f31; }
    .wm-info:hover .wm-tip, .wm-info:focus .wm-tip { opacity: 1; visibility: visible; }

    .wm-warn { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-radius: 12px; border: 1px solid #ecd98e; background: linear-gradient(180deg, #fdf6d8, #f8ecbd); color: #4a3a12; font-size: .88rem; }
    .wm-warn svg { flex: none; color: #d4a017; }

    .wm-btn-dark { display: inline-flex; align-items: center; gap: 10px; padding: 11px 18px; border-radius: 10px; border: 1px solid #072f25; background: linear-gradient(180deg, #0f5642, #0a3f31); color: #fff; font-size: .8rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; box-shadow: 0 3px 8px rgba(0, 0, 0, .25); }
    .wm-btn-dark:hover { filter: brightness(1.15); }
    .wm-btn-dark:disabled { opacity: .6; }
    .wm-plus { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; border-radius: 50%; background: linear-gradient(180deg, #e0b85a, #b8892f); color: #072f25; font-size: 15px; font-weight: 900; line-height: 1; }

    .wm-table { margin-top: 14px; border: 1.5px solid #3f8f98; border-radius: 16px; background: #fff; box-shadow: 0 3px 10px rgba(11, 74, 58, .08); overflow: hidden; }
    .wm-table .wm-head { padding: 14px 20px; border-bottom: 1px solid #d3e6de; }
    .wm-prow { padding: 14px 20px; font-size: .95rem; color: #1f2a33; }
    .wm-prow + .wm-prow { border-top: 1px solid #e2efe9; }
    .wm-picon { flex: none; width: 40px; height: 40px; object-fit: contain; }
    .wm-note { font-size: .8rem; color: #34524a; text-align: right; }

    /* Forms, sections and modals (Settings pages). */
    .wm-label { display: block; margin-bottom: 4px; font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #4b6b62; }
    .wm-input { width: 100%; border-radius: 10px; border: 1.5px solid #3f8f98; background-color: #fff; color: #1f2a33; font-size: .875rem; box-shadow: 0 1px 2px rgba(11, 74, 58, .06); }
    .wm-input:focus { border-color: #0f5642; --tw-ring-color: #0f5642; outline: none; }
    .wm-input--sm { font-size: .75rem; padding-top: 5px; padding-bottom: 5px; }
    .wm-check { border-radius: 4px; border-color: #3f8f98; color: #0f5642; }
    .wm-check:focus { --tw-ring-color: #0f5642; }
    .wm-hint { font-size: .75rem; color: #6f8c83; }
    .wm-section { border: 1px solid #d3e6de; border-radius: 14px; background: #fbfdfb; padding: 20px; box-shadow: 0 2px 8px rgba(11, 74, 58, .06); }
    .wm-section-title { font-size: 1rem; font-weight: 800; color: #0b4a3a; }
    .wm-subhead { font-size: .85rem; font-weight: 800; color: #0b4a3a; }
    .wm-btn-outline { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 10px; border: 1px solid #b2d3c7; background: #fbfdfb; color: #0b4a3a; font-size: .8rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
    .wm-btn-outline:hover { background: #e8f4ee; }
    .wm-btn-outline:disabled { opacity: .5; cursor: not-allowed; }
    .wm-btn-outline--sm { padding: 6px 11px; font-size: .72rem; letter-spacing: .03em; text-transform: none; font-weight: 700; }
    .wm-btn-outline--gold { border-color: #3f8f98; background: #e8f4ee; color: #0b4a3a; }
    .wm-btn-outline--danger { border-color: #e7b4b4; color: #9b1c1c; }
    .wm-btn-outline--danger:hover { background: #fdf0f0; }
    .wm-band { background: #7cc0a2; color: #0b4a3a; font-size: .68rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
    .wm-choice { display: flex; cursor: pointer; align-items: flex-start; gap: 8px; border: 1px solid #c3ddd3; border-radius: 10px; background: #fbfdfb; padding: 9px 12px; font-size: .875rem; }
    .wm-choice--on { border-color: #0f5642; background: #e8f4ee; box-shadow: 0 0 0 1px #0f5642; }
    .wm-chip { display: inline-flex; align-items: center; border: 1px solid #c3ddd3; border-radius: 999px; background: #fbfdfb; padding: 2px 10px; color: #4b6b62; }
    .wm-chip:hover { background: #e8f4ee; }
    .wm-chip--on { border-color: #0f5642; background: #cfe9dd; color: #0b4a3a; }
    .wm-doc-row { display: block; width: 100%; padding: 12px 20px; text-align: left; }
    .wm-doc-row + .wm-doc-row { border-top: 1px solid #e2efe9; }
    .wm-doc-row:hover { background: #e8f4ee; }
    .wm-modal-backdrop { position: fixed; inset: 0; z-index: 50; display: flex; align-items: flex-start; justify-content: center; overflow-y: auto; background: rgba(15, 23, 42, .6); padding: 12px; }
    .wm-modal { position: relative; display: flex; flex-direction: column; overflow: hidden; width: 100%; margin: 8px auto; max-height: calc(100vh - 1.5rem); border: 1px solid #d3e6de; border-radius: 16px; background: #fbfdfb; box-shadow: 0 20px 50px rgba(0, 0, 0, .3); }
    .wm-modal-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 16px 22px; background: #0b4a3a; color: #fff; }
    .wm-modal-close { flex: none; border-radius: 8px; padding: 8px 12px; font-size: .875rem; color: rgba(255, 255, 255, .8); }
    .wm-modal-close:hover { background: rgba(255, 255, 255, .1); color: #fff; }

    /* Order list pages (MO Search, Packed): header with medallion, navy column band, cream rows. */
    @font-face { font-family: 'Libre Baskerville'; font-style: normal; font-weight: 400 700; font-display: swap; src: url('{{ asset('fonts/libre-baskerville-latin.woff2') }}') format('woff2'); }
    .wml-head { display: flex; align-items: center; gap: 18px; flex-wrap: wrap; }
    .wml-head > div { flex: 1 1 200px; min-width: 0; }
    .wml-medal { flex: none; display: flex; align-items: center; justify-content: center; width: 64px; height: 64px; border-radius: 50%; border: 3px solid #c45e2f;
        background: radial-gradient(circle at 35% 30%, #1f8a6a, #0b4a3a 70%); box-shadow: inset 0 0 0 2px #7d3416, inset 0 4px 10px rgba(0, 0, 0, .35), 0 3px 8px rgba(0, 0, 0, .25); }
    .wml-medal img { width: 86%; height: 86%; object-fit: contain; filter: drop-shadow(0 2px 2px rgba(0, 0, 0, .35)); }
    .wml-title { font-size: 1.45rem; font-weight: 800; line-height: 1.15; letter-spacing: .01em; text-transform: uppercase; color: #0b4a3a; }
    .wml-sub { margin-top: 5px; font-size: .78rem; letter-spacing: .1em; text-transform: uppercase; color: #34524a; }
    .wml-count { margin-left: auto; display: inline-flex; align-items: center; padding: 7px 14px; border-radius: 999px; border: 1px solid #172c4d; color: #fff; font-size: .8rem; font-weight: 800;
        background: #1f3b63; box-shadow: 0 2px 5px rgba(11, 74, 58, .25); }
    .wml-table { margin-top: 18px; overflow: hidden; border: 1.5px solid #3f8f98; border-radius: 16px; background: #fff; box-shadow: 0 3px 10px rgba(11, 74, 58, .08); }
    .wml-bar { padding: 12px 20px; color: #0b4a3a; font-size: .72rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase;
        background: #7cc0a2; }
    .wm-grid--molist { grid-template-columns: minmax(130px, 1fr) minmax(90px, .7fr) minmax(220px, 2.4fr) minmax(90px, .8fr) minmax(100px, .8fr) minmax(90px, auto); }
    .wm-grid--packed { grid-template-columns: minmax(130px, 1fr) minmax(220px, 2.6fr) minmax(90px, .8fr) minmax(100px, .8fr) minmax(170px, auto); }
    .wml-num { text-align: right; padding-right: 18px; font-weight: 800; color: #0f5642; }
    .wml-bar .wml-num { color: #0b4a3a; }
    .wml-ref { font-weight: 700; color: #1f2a33; overflow-wrap: break-word; }
    .wml-prod { font-weight: 700; color: #1f2a33; line-height: 1.35; }
    .wml-prod small { display: block; margin-top: 2px; font-size: .75rem; font-weight: 500; color: #6f8c83; }
    .wml-empty { padding: 40px 20px; text-align: center; color: #4b6b62; font-size: .9rem; }

    /* Row layout follows the card width (not the screen), so it also works beside the nav on mid-size screens. */
    @container (max-width: 940px) {
        .wm-head--batch { display: block; padding: 0; }
        .wm-head--batch > :not(.wm-title) { display: none; }
        .wm-table .wm-head, .wml-bar { display: none; }
        .wml-num { text-align: left; padding-right: 0; }
        .wml-prod { grid-column: 1 / -1; }
        .wml-count { margin-left: 0; }
        .wml-title { font-size: 1.15rem; }
        .wm-row.wm-grid, .wm-prow.wm-grid { grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); row-gap: 12px; }
        .wm-ref, .wm-action { grid-column: 1 / -1; }
        .wm-action { justify-content: flex-start; }
        .wm-grid [data-label]::before { content: attr(data-label); display: block; margin-bottom: 4px; font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #6f8c83; }
        .wm-tip { right: auto; left: -6px; }
        .wm-tip::before { right: auto; left: 10px; }
        .wm-note { text-align: left; }
    }

    @media (max-width: 767px) {
        .wm-page { padding: 14px; border-radius: 18px; }
        .wm-card { padding: 16px; }
    }
</style>
