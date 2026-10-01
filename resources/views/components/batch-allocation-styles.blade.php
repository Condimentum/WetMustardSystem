{{-- Cream/plum theme for the batch page tab bar and Ingredient Allocation list. --}}
<style>
    .ba-shell { border: 1px solid #e3d8bf; border-radius: 18px; overflow: hidden; box-shadow: 0 8px 22px rgba(110, 85, 35, .10);
        background: linear-gradient(rgba(255, 253, 247, .9), rgba(255, 253, 247, .9)), url('{{ asset('gear-graphic.png') }}') right -70px bottom -80px / 240px no-repeat, #fffdf7; }

    .ba-tabs-wrap { padding: 14px 14px 0; }
    .ba-tabs { display: flex; flex-wrap: wrap; gap: 6px; padding: 6px; border: 1px solid #d8ccb0; border-radius: 14px; background: linear-gradient(180deg, #f3efe4, #e6dfcd); box-shadow: inset 0 1px 2px rgba(0, 0, 0, .08); }
    .ba-tab { flex: 1 0 auto; display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 11px 18px; border: 1px solid #c9c4b8; border-radius: 10px; background: linear-gradient(180deg, #eeece6, #dad7cf); color: #6b6558; font-size: .9rem; font-weight: 700; line-height: 1; text-decoration: none; white-space: nowrap; cursor: pointer; }
    .ba-tab--back { border-color: #d8ccb0; background: linear-gradient(180deg, #fffdf7, #f6f1e4); color: #1f2330; }
    .ba-tab--active { border-color: #c9a24a; background: linear-gradient(180deg, #4a2a78, #2c1650); color: #f6e7b8; box-shadow: 0 3px 8px rgba(44, 22, 80, .35), inset 0 1px 0 rgba(255, 255, 255, .15); }

    .ba-list { container: ba / inline-size; }
    .ba-grid { display: grid; grid-template-columns: 52px minmax(80px, .8fr) minmax(180px, 2.3fr) minmax(90px, .8fr) minmax(80px, .7fr) minmax(104px, auto); grid-template-areas: "badge prod desc out alloc act"; align-items: center; column-gap: 14px; }
    .ba-head { padding: 4px 18px 8px; font-size: .72rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: #2f3d47; }
    .ba-rows { display: flex; flex-direction: column; gap: 10px; }
    .ba-row { padding: 12px 18px; border: 1px solid #e6dcc5; border-radius: 14px; background: linear-gradient(180deg, #fffdf8, #f7f2e6); box-shadow: 0 3px 8px rgba(110, 85, 35, .09), inset 0 1px 0 #fff; color: #1f2a33; font-size: .95rem; cursor: pointer; transition: box-shadow .15s, border-color .15s; }
    .ba-row:hover { border-color: #d4c29a; box-shadow: 0 6px 14px rgba(110, 85, 35, .14), inset 0 1px 0 #fff; }
    .ba-row--open { border-color: #b89a5a; }
    .ba-badge { grid-area: badge; width: 40px; height: 46px; }
    .ba-prod { grid-area: prod; color: #3b3a36; }
    .ba-desc { grid-area: desc; min-width: 0; font-weight: 600; line-height: 1.3; }
    .ba-out { grid-area: out; text-align: right; }
    .ba-alloc { grid-area: alloc; text-align: right; font-weight: 700; }
    .ba-alloc small { display: block; font-size: .72rem; font-weight: 500; color: #8a7a5c; }
    .ba-act { grid-area: act; text-align: right; }

    .ba-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-width: 92px; padding: 9px 18px; border: 1px solid #c9a24a; border-radius: 10px; background: linear-gradient(180deg, #4a2a78, #2c1650); color: #f6e7b8; font-size: .88rem; font-weight: 700; box-shadow: 0 3px 8px rgba(44, 22, 80, .3), inset 0 1px 0 rgba(255, 255, 255, .15); }
    .ba-btn:hover { filter: brightness(1.15); }
    .ba-btn--lg { padding: 12px 24px; border-radius: 999px; font-size: .95rem; }

    .ba-detail { margin-top: -4px; padding: 14px 16px; border: 1px solid #e6dcc5; border-top: 0; border-radius: 0 0 14px 14px; background: #fbf7ec; }
    .ba-detail-title { font-weight: 700; color: #2c1650; }
    .ba-detail-msg { margin-top: 4px; font-size: .78rem; color: #6b5a2f; }
    .ba-lots { margin-top: 10px; overflow: hidden; border: 1px solid #e6dcc5; border-radius: 10px; background: #fffdf8; font-size: .88rem; }
    .ba-lots-grid { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1.2fr) minmax(60px, .6fr) minmax(50px, .4fr) minmax(70px, .6fr); column-gap: 12px; padding: 9px 14px; }
    .ba-lots-head { background: #f1eadb; font-size: .7rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: #5b5140; }
    .ba-lots-grid + .ba-lots-grid { border-top: 1px solid #efe7d4; }
    .ba-num { text-align: right; }

    .ba-foot { padding: 6px 0 4px; }

    /* Ingredients Sign Off tab */
    .bs-wrap { container: bs / inline-size; }
    .bs-card { border: 1px solid #e6dcc5; border-radius: 16px; background: linear-gradient(180deg, #fffdf8, #f7f2e6); box-shadow: 0 3px 8px rgba(110, 85, 35, .09), inset 0 1px 0 #fff; }
    .bs-head { padding: 16px 20px; border-bottom: 1px solid #e6dcc5; }
    .bs-title { margin: 0; font-size: 1.1rem; font-weight: 800; color: #2c1650; }
    .bs-sub { margin-top: 3px; font-size: .82rem; color: #6b5a2f; }
    .bs-body { padding: 18px 20px 20px; }
    .bs-empty { font-size: .9rem; color: #8a7a5c; }
    .bs-roles { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
    .bs-role { padding: 12px 14px; border: 1px solid #e3d8bf; border-radius: 12px; background: #fffdf8; }
    .bs-role-label { display: block; margin-bottom: 8px; font-size: .7rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #5b5140; }
    .bs-select { width: 100%; border: 1px solid #d8ccb0; border-radius: 10px; background-color: #fffdf7; color: #1f2a33; font-size: .9rem; }
    .bs-select:focus { border-color: #4a2a78; box-shadow: 0 0 0 3px rgba(74, 42, 120, .18); }
    .bs-done { display: flex; align-items: center; gap: 10px; min-height: 40px; padding: 6px 12px; border: 1px solid #9fc8bc; border-radius: 10px; background: linear-gradient(180deg, #e2f0ec, #cfe6df); color: #1f4f4f; font-size: .88rem; font-weight: 700; }
    .bs-done-check { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; background: #1f5c61; color: #fff; font-size: 12px; }
    .bs-done-name { font-weight: 500; color: #2f5f5f; }
    .bs-actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 10px; margin-top: 14px; }
    .bs-btn-ghost { display: inline-flex; align-items: center; height: 40px; padding: 0 18px; border: 1px solid #c9a24a; border-radius: 10px; background: linear-gradient(180deg, #fffdf7, #f6ecd2); color: #6b4e14; font-size: .88rem; font-weight: 700; }
    .bs-btn-ghost:hover { background: #f3e5bf; }
    .bs-foot { display: flex; flex-direction: column; align-items: flex-end; gap: 10px; margin-top: 18px; }
    .bs-warn { box-sizing: border-box; width: 100%; display: flex; align-items: center; gap: 12px; padding: 12px 16px; border: 1px solid #ecd98e; border-radius: 12px; background: linear-gradient(180deg, #fdf6d8, #f8ecbd); color: #4a3a12; font-size: .9rem; font-weight: 700; animation: ingredient-signoff-pulse 1.7s ease-in-out infinite; }
    .bs-warn-icon { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 50%; background: linear-gradient(180deg, #e0b85a, #b8892f); color: #fff; font-weight: 900; }
    .ba-btn:disabled { border-color: #cfc8b8; background: linear-gradient(180deg, #ebe8e1, #dcd8cf); color: #8a8578; box-shadow: none; cursor: not-allowed; filter: none; }
    .bs-note { font-size: .78rem; color: #6b6147; text-align: right; }

    @container bs (max-width: 640px) {
        .bs-roles { grid-template-columns: minmax(0, 1fr); }
        .bs-actions, .bs-foot { justify-content: stretch; align-items: stretch; }
        .bs-actions > *, .bs-foot > .ba-btn { justify-content: center; }
        .bs-note { text-align: left; }
    }

    @container ba (max-width: 720px) {
        .ba-head { display: none; }
        .ba-grid { grid-template-columns: 44px repeat(3, minmax(0, 1fr)); grid-template-areas: "badge desc desc act" "badge prod out alloc"; row-gap: 10px; column-gap: 10px; }
        .ba-out, .ba-alloc { text-align: left; }
        .ba-row [data-label]::before { content: attr(data-label); display: block; margin-bottom: 3px; font-size: .64rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #8a7a5c; }
        .ba-btn { min-width: 0; padding: 8px 14px; }
        .ba-lots-grid { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(50px, auto); }
        .ba-lots-grid > :nth-child(4), .ba-lots-grid > :nth-child(5) { display: none; }
    }
</style>
