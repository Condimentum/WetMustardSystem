<!doctype html>
<html>
<head>
    <meta charset="utf-8" />
    <title>WM003 Vinegar IBC Traceability</title>
    <style>
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            margin: {{ (float) data_get($setup ?? [], 'margin_top', 20) }}px {{ (float) data_get($setup ?? [], 'margin_right', 20) }}px {{ (float) data_get($setup ?? [], 'margin_bottom', 20) }}px {{ (float) data_get($setup ?? [], 'margin_left', 20) }}px;
            color: #111827;
            font-size: {{ (float) data_get($setup ?? [], 'base_font_size', 12) }}px;
            line-height: {{ (float) data_get($setup ?? [], 'line_height', 1.25) }};
            padding-bottom: {{ (bool) data_get($setup ?? [], 'show_issue_history', true) ? 150 : 20 }}px;
        }
        .header {
            display: table;
            width: 100%;
            margin-bottom: 12px;
        }
        .header .left,
        .header .right {
            display: table-cell;
            vertical-align: middle;
        }
        .header .right {
            text-align: left;
            padding-left: 16px;
        }
        .title {
            font-size: 30px;
            font-weight: 700;
            margin: 0;
        }
        .subtitle {
            margin-top: 4px;
            font-size: 20px;
            font-style: italic;
            font-weight: 700;
            color: #111827;
        }
        .ccp {
            text-align: center;
            color: #111827;
            font-weight: 700;
            margin: 10px 0 8px;
            letter-spacing: 0.02em;
        }
        .sheet {
            border: 2px solid #111827;
            padding: {{ (float) data_get($setup ?? [], 'content_padding', 10) }}px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        th, td {
            border: 1px solid #111827;
            padding: 6px 5px;
            vertical-align: top;
        }
        th {
            background: #f3f4f6;
            font-size: {{ (float) data_get($setup ?? [], 'table_header_font_size', 11) }}px;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .center {
            text-align: center;
        }
        .changes {
            position: fixed;
            left: 20px;
            right: 20px;
            bottom: 20px;
        }
        .muted {
            color: #4b5563;
        }
    </style>
</head>
<body>
    @php
        $resolvedSetup = is_array($setup ?? null) ? $setup : [];
        $showLogo = (bool) ($resolvedSetup['show_logo'] ?? true);
        $showCcpBlock = (bool) ($resolvedSetup['show_ccp_block'] ?? true);
        $ccpMessage = trim((string) ($resolvedSetup['ccp_message'] ?? ''));
        $showIssueHistory = (bool) ($resolvedSetup['show_issue_history'] ?? true);

        $defaultColumns = [
            ['key' => 'date_used', 'label' => 'Date Used', 'visible' => true, 'width' => 16],
            ['key' => 'supplier_production_date', 'label' => 'Supplier Production Date', 'visible' => true, 'width' => 18],
            ['key' => 'best_before_date', 'label' => 'Best Before Date', 'visible' => true, 'width' => 16],
            ['key' => 'batch_no', 'label' => 'Batch No.', 'visible' => true, 'width' => 16],
            ['key' => 'time_on', 'label' => 'Time on', 'visible' => true, 'width' => 12],
            ['key' => 'operator_name', 'label' => 'Operator Name', 'visible' => true, 'width' => 22],
        ];

        $configuredColumns = collect(is_array($resolvedSetup['columns'] ?? null) ? $resolvedSetup['columns'] : [])
            ->filter(fn ($column): bool => is_array($column) && (bool) ($column['visible'] ?? false))
            ->values();
        $visibleColumns = $configuredColumns->isNotEmpty() ? $configuredColumns : collect($defaultColumns);

        $resolveCell = static function ($row, string $key): string {
            return match ($key) {
                'date_used' => $row->date_used?->toDateString() ?? '—',
                'supplier_production_date' => $row->supplier_production_date?->toDateString() ?? '—',
                'best_before_date' => $row->best_before_date?->toDateString() ?? '—',
                'batch_no' => (string) ($row->batch_no ?? '—'),
                'time_on' => $row->time_on ? \Illuminate\Support\Carbon::parse($row->time_on)->format('H:i') : '—',
                'operator_name' => (string) ($row->operator_name ?? '—'),
                default => '—',
            };
        };

        $logoPath = public_path('assets/condimentum-logo.png');
        $logoSrc = '';

        if (is_file($logoPath)) {
            $logoData = @file_get_contents($logoPath);
            if ($logoData !== false) {
                $logoSrc = 'data:image/png;base64,'.base64_encode($logoData);
            }
        }
    @endphp

    <div class="header">
        <div class="left" style="width: 180px;">
            @if ($showLogo)
                @if ($logoSrc !== '')
                    <img src="{{ $logoSrc }}" alt="Condimentum" style="width: 170px; height: auto;" />
                @else
                    <div class="muted" style="font-size: 12px;">Condimentum</div>
                @endif
            @endif
        </div>
        <div class="right">
            <h1 class="title">{{ $document?->code ? $document->code.' - ' : 'WM003 - ' }}Vinegar IBC Traceability</h1>
            <div class="subtitle">Note: Details taken from ibc label</div>
        </div>
    </div>

    <div class="sheet">
        <table>
            <colgroup>
                @foreach ($visibleColumns as $column)
                    <col style="width: {{ is_numeric($column['width'] ?? null) ? (float) $column['width'] : 16 }}%;" />
                @endforeach
            </colgroup>
            <thead>
                <tr>
                    @foreach ($visibleColumns as $column)
                        <th>{{ trim((string) ($column['label'] ?? '')) !== '' ? $column['label'] : strtoupper(str_replace('_', ' ', (string) ($column['key'] ?? ''))) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        @foreach ($visibleColumns as $column)
                            <td>{{ $resolveCell($row, (string) ($column['key'] ?? '')) }}</td>
                        @endforeach
                    </tr>
                @empty
                    @for ($i = 0; $i < 24; $i++)
                        <tr>
                            @for ($j = 0; $j < $visibleColumns->count(); $j++)
                                <td>{{ $j === 0 ? ' ' : '' }}</td>
                            @endfor
                        </tr>
                    @endfor
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($showCcpBlock && $ccpMessage !== '')
        <div class="ccp">{{ $ccpMessage }}</div>
    @endif

    @if ($showIssueHistory)
        <div class="changes">
            <table>
                <thead>
                    <tr>
                        <th>Issue Version</th>
                        <th>Date Issued</th>
                        <th>Issued By</th>
                        <th>Reason for Change</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>{{ $document?->version ?? '—' }}</td>
                        <td>{{ $document?->issue_date?->format('d-m-Y') ?? '—' }}</td>
                        <td>{{ $changes->first()?->issued_by ?? '—' }}</td>
                        <td>{{ $changes->first()?->reason_for_change ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    @endif
</body>
</html>
