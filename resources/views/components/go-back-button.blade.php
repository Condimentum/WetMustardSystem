{{--
    Navy/gold "go back" badge used app-wide for backwards navigation,
    both outside a page's content frame (layouts.app) and inside a wm-card header.
    Navigates to whatever app page the user actually came from (tracked in
    resources/js/app.js), as a fresh page load - not a browser-history pop, so
    it can never land back on a login/OAuth callback page. Falls back to
    $href when no previous app page is known (e.g. the very first page after
    signing in).
--}}
@props(['href', 'label' => 'Go back'])

<a
    href="{{ $href }}"
    data-go-back
    aria-label="{{ $label }}"
    title="{{ $label }}"
    {{ $attributes->merge(['class' => 'wm-go-back']) }}
>
    Go Back
</a>
