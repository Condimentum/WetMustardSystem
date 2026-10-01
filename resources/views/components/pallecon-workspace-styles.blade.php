{{-- Pallecon Workspace page: builds on the shared wm-* theme (x-mo-workspace-styles) with page-specific pieces. --}}
<style>
    .pw-hero { display: flex; flex-wrap: wrap; align-items: center; gap: 16px; }
    .pw-hero-icon { flex: none; display: flex; align-items: center; justify-content: center; width: 64px; height: 64px; border-radius: 50%; border: 3px solid #c9a24a; background: radial-gradient(circle at 35% 30%, #ffffff, #efe6d0 75%); box-shadow: inset 0 0 0 2px #e6d4a3, 0 3px 8px rgba(0, 0, 0, .18); }
    .pw-hero-icon img { width: 70%; height: 70%; object-fit: contain; }
    .pw-hero-text { flex: 1 1 220px; min-width: 0; }
    .pw-back { margin-left: auto; display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; border: 1px solid #c9a24a; border-radius: 999px; background: linear-gradient(180deg, #fffdf7, #f6ecd2); color: #6b4e14; font-size: .82rem; font-weight: 700; text-decoration: none; }
    .pw-back:hover { background: #f3e5bf; }
    .pw-stats { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; }
    .pw-stat { flex: 1 1 150px; min-width: 0; padding: 10px 14px; border: 1px solid rgba(214, 202, 176, .9); border-radius: 12px; background: linear-gradient(180deg, rgba(255, 255, 255, .85), rgba(247, 243, 233, .7)); box-shadow: 0 3px 8px rgba(110, 85, 35, .08), inset 0 1px 0 #fff; }
    .pw-stat-label { font-size: .68rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #5b5140; }
    .pw-stat-value { margin-top: 3px; font-family: 'Libre Baskerville', Georgia, serif; font-size: 1.02rem; font-weight: 700; color: #1f1f1f; overflow-wrap: anywhere; }

    .pw-flash { padding: 12px 16px; border-radius: 12px; font-size: .9rem; font-weight: 600; }
    .pw-flash--ok { border: 1px solid #9fc8bc; background: linear-gradient(180deg, #e2f0ec, #d3e8e1); color: #1f4f4f; }
    .pw-flash--err { border: 1px solid #f1b5b5; background: linear-gradient(180deg, #fdecec, #f9dcdc); color: #8b2323; }

    .pw-booking { padding: 16px 18px; border-radius: 14px; border: 1px solid #9fc8bc; background: #f1f8f5; }
    .pw-booking--err { border-color: #f1b5b5; background: #fdf3f3; }
    .pw-booking-title { margin: 0 0 12px; font-size: .92rem; font-weight: 800; color: #1f4f4f; }
    .pw-booking--err .pw-booking-title { color: #8b2323; }
    .pw-kv { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 8px 16px; padding: 14px; border: 1px solid #e6dcc5; border-radius: 10px; background: #fffdf8; font-size: .86rem; }
    .pw-kv span:first-child { color: #8a7a5c; }
    .pw-kv strong { color: #1f2a33; }
    .pw-kv-wide { grid-column: 1 / -1; }

    .wm-grid--pwbatch { grid-template-columns: minmax(170px, 2fr) minmax(70px, .7fr) minmax(70px, .7fr) minmax(80px, .8fr) minmax(150px, auto); }
    .wm-prow--muted { opacity: .55; }
    .pw-pending { display: block; margin-top: 4px; font-size: .72rem; font-weight: 700; color: #9a5b12; }
    .pw-note { margin-top: 12px; font-size: .82rem; color: #9a5b12; }
    .pw-note a { font-weight: 700; color: #6b4e14; text-decoration: underline; }

    .wm-btn-continue:disabled { border-color: #cfc8b8; background: linear-gradient(180deg, #ebe8e1, #dcd8cf); color: #8a8578; box-shadow: none; cursor: not-allowed; filter: none; }

    .pw-pallecon { display: flex; flex-wrap: wrap; align-items: center; gap: 14px; }
    .pw-pallecon img { flex: none; width: 52px; height: 52px; object-fit: contain; }
    .pw-serial { font-family: 'Libre Baskerville', Georgia, serif; font-size: 1.3rem; font-weight: 700; color: #1a1a1a; }
    .pw-ref { font-size: .8rem; color: #6b6147; }
    .pw-ref code { color: #1f2a33; }
    .pw-weight { margin-left: auto; text-align: right; font-size: .9rem; color: #4b4636; }
    .pw-weight strong { display: block; font-family: 'Libre Baskerville', Georgia, serif; font-size: 1.15rem; color: #1f2a33; }
    .pw-bar { height: 14px; margin-top: 14px; overflow: hidden; border: 1px solid #d8ccb0; border-radius: 999px; background: #f3eee2; box-shadow: inset 0 1px 3px rgba(0, 0, 0, .08); }
    .pw-bar > span { display: block; height: 100%; border-radius: 999px; background: linear-gradient(90deg, #d4ad55, #b8892f); box-shadow: inset 0 1px 0 rgba(255, 255, 255, .35); }
    .pw-bar--full > span { background: linear-gradient(90deg, #2b7a80, #1f5c61); }

    .pw-section { margin-top: 18px; padding-top: 16px; border-top: 1px solid #ebe2cd; }
    .pw-label { display: block; margin-bottom: 6px; font-size: .7rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #5b5140; }
    .pw-input { width: 100%; padding: 9px 12px; border: 1px solid #d8ccb0; border-radius: 10px; background-color: #fffdf7; color: #1f2a33; font-size: .9rem; }
    .pw-input:focus { border-color: #1f5c61; box-shadow: 0 0 0 3px rgba(31, 92, 97, .18); }
    .pw-error { display: block; margin-top: 4px; font-size: .75rem; color: #b42318; }
    .pw-seal { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
    .pw-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
    .pw-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 11px 20px; border: 1px solid #14464a; border-radius: 999px; background: linear-gradient(180deg, #2b7a80, #1f5c61); color: #fff; font-size: .9rem; font-weight: 700; box-shadow: 0 3px 8px rgba(31, 92, 97, .3), inset 0 1px 0 rgba(255, 255, 255, .2); }
    .pw-btn:hover { filter: brightness(1.1); }
    .pw-btn:disabled { border-color: #cfc8b8; background: linear-gradient(180deg, #ebe8e1, #dcd8cf); color: #8a8578; box-shadow: none; cursor: not-allowed; filter: none; }
    .pw-btn-ghost { display: inline-flex; align-items: center; padding: 10px 18px; border: 1px solid #d8ccb0; border-radius: 999px; background: #fffdf7; color: #4b4636; font-size: .88rem; font-weight: 700; }
    .pw-btn-warn { display: inline-flex; align-items: center; padding: 9px 16px; border: 1px solid #a07a2c; border-radius: 10px; background: linear-gradient(180deg, #f3d98a, #d4ad55); color: #3b2a0a; font-size: .85rem; font-weight: 800; box-shadow: 0 2px 5px rgba(90, 60, 20, .2); }
    .pw-hint { font-size: .78rem; color: #9a5b12; }

    .pw-fills { margin-top: 12px; }
    .pw-fill { display: flex; justify-content: space-between; gap: 12px; padding: 8px 12px; border-bottom: 1px solid #efe7d4; font-size: .9rem; color: #3b3a36; }
    .pw-empty { font-size: .88rem; font-style: italic; color: #8a7a5c; }

    .pw-tiles { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-top: 16px; }
    .pw-subhead { margin: 20px 0 8px; font-size: .72rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: #5b5140; }
    .pw-mini { overflow: hidden; border: 1px solid #e6dcc5; border-radius: 10px; background: #fffdf8; font-size: .88rem; }
    .pw-mini-row { display: grid; grid-template-columns: var(--pw-cols, minmax(0, 2fr) minmax(0, 1fr)); gap: 12px; padding: 9px 14px; align-items: center; }
    .pw-mini-row + .pw-mini-row { border-top: 1px solid #efe7d4; }
    .pw-mini-head { background: #f1eadb; font-size: .7rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: #5b5140; }
    .pw-num { text-align: right; }
    .pw-tag { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 999px; font-size: .75rem; font-weight: 700; }
    .pw-tag--ok { background: #d3e8e1; color: #1f4f4f; }
    .pw-tag--err { background: #f9dcdc; color: #8b2323; }
    .pw-tag--muted { background: #ece8df; color: #6b6558; }

    .pw-completed { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
    .pw-completed + .pw-completed { border-top: 1px solid #efe7d4; }
    .pw-completed-main { flex: 1 1 260px; min-width: 0; display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
    .pw-completed-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }

    .wml-title, .pw-stat-value, .pw-serial, .pw-weight strong { font-variant-numeric: lining-nums; font-feature-settings: "lnum" 1; }

    /* Batch table: Batch | Planned | Filled | Remaining | Issue. The Issue button always sits at the
       right end of the figures; only the batch number moves onto its own line when the card is narrow. */
    .wm-grid--pwbatch .wm-ref { display: block; }
    .wm-grid--pwbatch .wm-action { justify-content: flex-end; }
    @container (min-width: 640px) {
        .wml-bar.wm-grid--pwbatch { display: grid; }
        .wm-prow.wm-grid--pwbatch { grid-template-columns: minmax(170px, 2fr) minmax(70px, .7fr) minmax(70px, .7fr) minmax(80px, .8fr) minmax(150px, auto); row-gap: 0; }
        .wm-grid--pwbatch [data-label]::before { display: none; }
        .wm-grid--pwbatch .wm-ref, .wm-grid--pwbatch .wm-action { grid-column: auto; }
        .wm-grid--pwbatch .wml-num { text-align: right; padding-right: 18px; }
    }
    @container (max-width: 639.98px) {
        .wm-prow.wm-grid--pwbatch { grid-template-columns: repeat(3, minmax(0, 1fr)) auto; align-items: end; }
        .wm-grid--pwbatch .wm-action { grid-column: auto; }
    }
    @container (max-width: 400px) {
        .wm-prow.wm-grid--pwbatch { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .wm-grid--pwbatch .wm-action { grid-column: 1 / -1; }
    }

    @container (max-width: 700px) {
        .pw-seal, .pw-tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .pw-weight { margin-left: 0; text-align: left; }
    }
    @container (max-width: 440px) {
        .pw-seal, .pw-tiles { grid-template-columns: minmax(0, 1fr); }
        .pw-mini-row { --pw-cols: minmax(0, 1fr) minmax(0, 1fr) !important; }
    }
</style>
