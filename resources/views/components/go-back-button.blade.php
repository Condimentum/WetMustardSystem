{{--
    Navy/gold "go back" badge used app-wide for backwards navigation,
    both outside a page's content frame (layouts.app) and inside a wm-card header.
    Navigates to whatever app page the user actually came from (tracked in
    resources/js/app.js), as a fresh page load - not a browser-history pop, so
    it can never land back on a login/OAuth callback page. Falls back to
    $href when no previous app page is known (e.g. the very first page after
    signing in). Set :useStack="false" to always go straight to $href instead
    (e.g. Settings sub-pages should always return to the Main Menu, not to
    whatever other Settings sub-page was visited before).
--}}
@props(['href', 'label' => 'Go back', 'useStack' => true])

<a
    href="{{ $href }}"
    @if ($useStack) data-go-back @endif
    aria-label="{{ $label }}"
    title="{{ $label }}"
    {{ $attributes->merge(['class' => 'wm-go-back']) }}
>
    Go Back
</a>
