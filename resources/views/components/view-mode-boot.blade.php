{{--
    Desktop / mobile view switch - boot script. Include once in <head> of every layout.

    Responsive CSS (Tailwind breakpoints and @media rules) reacts to the viewport width, so squeezing the
    page into a narrow column would not trigger it. Instead, on large screens in "mobile" mode this script
    replaces the top-level document with a shell that loads the very same URL in a tablet-width iframe;
    every breakpoint then evaluates against that width exactly as it would on a real tablet.
--}}
<script>
    (function () {
        var KEY = 'wmViewMode';
        var LARGE_SCREEN_MIN = 1280; // keep in sync with the media query in the view-mode-toggle component
        var TABLET_WIDTH = 820;

        if (window.self !== window.top) {
            // Inside the shell: tell it where we are so a refresh of the outer window reopens this page.
            var report = function () {
                try {
                    window.parent.postMessage({
                        wmViewFrame: true,
                        url: window.location.href,
                        title: document.title
                    }, window.location.origin);
                } catch (e) {}
            };
            window.addEventListener('load', report);
            document.addEventListener('livewire:navigated', report);
            return;
        }

        var mode = null;
        try { mode = window.localStorage.getItem(KEY); } catch (e) {}

        if (mode !== 'mobile' || window.innerWidth < LARGE_SCREEN_MIN) {
            return;
        }

        var frameUrl = window.location.href;
        var pageTitle = document.title;

        // Rebuild the current document in place (no document.open) once the original parser is stopped.
        window.setTimeout(buildShell, 0);

        function buildShell() {
        var root = document.documentElement;
        while (root.firstChild) {
            root.removeChild(root.firstChild);
        }
        var head = document.createElement('head');
        var charset = document.createElement('meta');
        charset.setAttribute('charset', 'utf-8');
        var viewport = document.createElement('meta');
        viewport.name = 'viewport';
        viewport.content = 'width=device-width, initial-scale=1';
        head.appendChild(charset);
        head.appendChild(viewport);
        root.appendChild(head);
        root.appendChild(document.createElement('body'));
        document.title = pageTitle;

        var style = document.createElement('style');
        style.textContent =
            'html,body{height:100%;margin:0;}'
            + 'body{display:flex;flex-direction:column;align-items:center;background:#1f2937;font-family:Figtree,ui-sans-serif,system-ui,sans-serif;}'
            + '.wm-vm-bar{flex:none;display:flex;align-items:center;gap:14px;padding:8px 0;color:#e5e7eb;font-size:13px;}'
            + '.wm-vm-switch{display:inline-flex;align-items:center;gap:8px;padding:6px 14px;border:2px solid #c9a24a;border-radius:999px;'
            + 'background:radial-gradient(circle at 35% 30%,#3a4686,#1c2552 70%);color:#f3d98a;font:inherit;font-weight:700;cursor:pointer;}'
            + '.wm-vm-switch:hover{filter:brightness(1.15);}'
            + '.wm-vm-switch:focus-visible{outline:2px solid #93c5fd;outline-offset:2px;}'
            + '.wm-vm-frame{flex:1 1 auto;min-height:0;width:' + TABLET_WIDTH + 'px;max-width:100%;border:0;background:#fff;'
            + 'border-radius:14px 14px 0 0;box-shadow:0 0 0 6px #0b1020,0 10px 40px rgba(0,0,0,.6);}';
        document.head.appendChild(style);

        var bar = document.createElement('div');
        bar.className = 'wm-vm-bar';

        var label = document.createElement('span');
        label.textContent = 'Mobile / tablet view (' + TABLET_WIDTH + 'px)';

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'wm-vm-switch';
        button.textContent = 'Switch to desktop view';
        button.addEventListener('click', function () {
            try { window.localStorage.setItem(KEY, 'desktop'); } catch (e) {}
            window.location.reload();
        });

        bar.appendChild(label);
        bar.appendChild(button);

        var frame = document.createElement('iframe');
        frame.className = 'wm-vm-frame';
        frame.title = 'Mobile view';
        frame.src = frameUrl;

        document.body.appendChild(bar);
        document.body.appendChild(frame);

        window.addEventListener('message', function (event) {
            var data = event.data;

            if (event.origin !== window.location.origin || event.source !== frame.contentWindow || ! data || ! data.wmViewFrame) {
                return;
            }

            if (data.title) {
                document.title = data.title;
            }

            if (data.url && data.url !== window.location.href) {
                window.history.replaceState(null, '', data.url);
            }
        });

        // Dropping below the large-screen threshold means a real small screen: leave the shell.
        window.addEventListener('resize', function () {
            if (window.innerWidth < LARGE_SCREEN_MIN) {
                window.location.reload();
            }
        });
        }
    })();
</script>
