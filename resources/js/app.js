import './bootstrap';

// Tracks visited app pages as a stack (sessionStorage, per-tab) so the shared
// "Go Back" button can load the right previous page as a fresh page rather
// than relying on browser history.back() - which can land on a login/OAuth
// callback page. A single "previous page" pointer isn't enough: each Go Back
// navigation is itself a page load, so it must POP the stack instead of
// pushing, otherwise going back repeatedly (e.g. WM001 -> Daily Calibrations
// -> Main Menu) re-records the page you just left as the "previous" page of
// where you landed, breaking the next Go Back click.
function readNavStack() {
    try {
        return JSON.parse(sessionStorage.getItem('wmNavStack') || '[]');
    } catch (e) {
        return [];
    }
}

function writeNavStack(stack) {
    sessionStorage.setItem('wmNavStack', JSON.stringify(stack));
}

document.addEventListener('livewire:navigated', function () {
    var stack = readNavStack();
    var here = window.location.href;

    if (stack.length && stack[stack.length - 1] === here) {
        return; // Already the expected top (e.g. we just navigated here via Go Back).
    }

    stack.push(here);

    if (stack.length > 25) {
        stack.shift();
    }

    writeNavStack(stack);
});

document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-go-back]');

    if (! button) {
        return;
    }

    var stack = readNavStack();

    if (stack.length < 2) {
        return; // No previous app page tracked yet - let the default href (Main Menu) load normally.
    }

    stack.pop();
    var target = stack[stack.length - 1];
    writeNavStack(stack);

    event.preventDefault();
    window.location.href = target;
});
