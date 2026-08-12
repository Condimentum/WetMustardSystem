@props(['icon'])

@php
    $paths = match ($icon) {
        'wrench' => '<path stroke-linecap="round" stroke-linejoin="round" d="M14.7 6.3a4 4 0 1 1-5.4 5.4L4 17l3 3 5.3-5.3a4 4 0 0 0 5.4-5.4l-2.4 2.4-2-2 2.4-2.4z" />',
        'scanner' => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 20V10a8 8 0 0 1 16 0v10M4 20h2.5M17.5 20H20M4 14.5h2.5M17.5 14.5H20" />',
        'microscope' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8 20h8M12 20v-3.5M6.5 16.5h11M10 13a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM12.1 9.1l2.9-2.9M15.8 6.2l1.4-1.4" />',
        'gear' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />',
        'trolley' => '<rect x="5" y="7" width="12" height="9" rx="1.2" stroke-linecap="round" stroke-linejoin="round" /><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" /><circle cx="8" cy="19" r="1.4" /><circle cx="14" cy="19" r="1.4" />',
        'bucket' => '<path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14l-1.5 10.2a2 2 0 0 1-2 1.8H8.5a2 2 0 0 1-2-1.8L5 8z" /><path stroke-linecap="round" stroke-linejoin="round" d="M8 8V6.2A1.2 1.2 0 0 1 9.2 5h5.6A1.2 1.2 0 0 1 16 6.2V8" /><path stroke-linecap="round" d="M4 8h16" />',
        default => '',
    };
@endphp

<svg {{ $attributes->merge(['class' => 'h-6 w-6', 'viewBox' => '0 0 24 24', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.6']) }}>
    {!! $paths !!}
</svg>
