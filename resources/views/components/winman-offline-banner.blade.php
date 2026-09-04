@props(['message' => 'WinMan connection is currently unavailable. Live order data can\'t be refreshed right now — existing batches and records remain fully usable.'])

<div {{ $attributes->merge(['class' => 'rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 flex items-start gap-2']) }}>
    <svg class="w-5 h-5 flex-shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
    </svg>
    <span>{{ $message }}</span>
</div>
