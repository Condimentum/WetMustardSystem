<x-app-layout>
    <div class="py-8">
        <x-mo-workspace-styles />
        <div class="wm-page max-w-5xl mx-auto space-y-4">
            <section class="wm-card wm-card--gear-tr">
                <div class="wml-head">
                    <span class="wml-medal"><img src="{{ asset('images/dashboard/trolley.png') }}" alt="" /></span>
                    <div>
                        <div class="wml-title">{{ strtoupper($title) }}</div>
                        <div class="wml-sub">{{ strtoupper($subtitle) }}</div>
                    </div>
                    <span class="wml-count">{{ count($orders) }} shown</span>
                </div>

                <div class="wml-table">
                    @if (count($orders) === 0)
                        <div class="wml-empty">No outstanding orders found.</div>
                    @else
                        <div class="wml-bar wm-grid wm-grid--packed">
                            <div>MO Ref</div>
                            <div>Product</div>
                            <div class="wml-num">Outstanding</div>
                            <div>Due</div>
                            <div></div>
                        </div>

                        @foreach ($orders as $row)
                            <div class="wm-prow wm-grid wm-grid--packed">
                                <div class="wm-ref wml-ref">{{ $row['mo_ref'] }}</div>
                                <div class="wml-prod" data-label="Product">
                                    {{ \Illuminate\Support\Str::limit($row['product_description'], 70) }}
                                    <small>{{ $row['product_id'] }}</small>
                                </div>
                                <div class="wml-num" data-label="Outstanding">{{ rtrim(rtrim((string) $row['outstanding'], '0'), '.') }}</div>
                                <div data-label="Due">{{ $row['due_date'] ? \Illuminate\Support\Str::of($row['due_date'])->before(' ') : '—' }}</div>
                                <div class="wm-action">
                                    <a href="{{ route('manufacturing-orders.search', ['openMo' => $row['winman_mo']]) }}" wire:navigate class="wm-btn-continue">Open in MO workspace</a>
                                </div>
                            </div>
                        @endforeach
                    @endif
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
