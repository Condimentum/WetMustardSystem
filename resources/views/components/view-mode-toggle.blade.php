{{-- Large-screen-only button that switches the app into mobile/tablet view. See the view-mode-boot component. --}}
<style>
    .wm-view-toggle {
        display: none;
        position: fixed;
        right: 14px;
        bottom: 14px;
        z-index: 9000;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        border: 2px solid #c9a24a;
        border-radius: 999px;
        background: radial-gradient(circle at 35% 30%, #3a4686, #1c2552 70%);
        color: #f3d98a;
        font-size: .72rem;
        font-weight: 700;
        cursor: pointer;
        opacity: .85;
        box-shadow: 0 3px 8px rgba(0, 0, 0, .25);
    }

    .wm-view-toggle:hover { opacity: 1; }
    .wm-view-toggle:focus-visible { outline: 2px solid #93c5fd; outline-offset: 2px; }

    @media (min-width: 1280px) {
        .wm-view-toggle { display: inline-flex; }
    }
</style>

<button
    type="button"
    class="wm-view-toggle"
    title="Preview this page at tablet width, as it appears on mobile"
    onclick="try { localStorage.setItem('wmViewMode', 'mobile'); } catch (e) {} window.location.reload();"
>
    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="2" width="12" height="20" rx="2"/><line x1="11" y1="18" x2="13" y2="18"/></svg>
    Mobile view
</button>
