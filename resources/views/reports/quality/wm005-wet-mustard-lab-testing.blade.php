<!doctype html>
<html>
<head>
    <meta charset="utf-8" />
    <title>WM005 Wet mustard lab testing</title>
    <style>
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            margin: {{ (float) data_get($setup ?? [], 'margin_top', 20) }}px {{ (float) data_get($setup ?? [], 'margin_right', 20) }}px {{ (float) data_get($setup ?? [], 'margin_bottom', 20) }}px {{ (float) data_get($setup ?? [], 'margin_left', 20) }}px;
            color: #111827;
            font-size: {{ (float) data_get($setup ?? [], 'base_font_size', 11) }}px;
            line-height: {{ (float) data_get($setup ?? [], 'line_height', 1.25) }};
            padding-bottom: {{ (bool) data_get($setup ?? [], 'show_issue_history', true) ? 170 : 20 }}px;
        }
        .header {
            display: table;
            padding: 4px 3px;
            width: 100%;
            margin-bottom: 12px;
            line-height: 1.25;
            word-break: break-word;
            overflow-wrap: anywhere;
        }
        .header .left,
        .header .right {
            display: table-cell;
            vertical-align: middle;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.2px;
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
        .ccp {
            color: #dc2626;
            font-weight: 700;
            margin: 10px 0 8px;
            line-height: 1.35;
            text-align: left;
        }
        .sheet-wrap {
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
            padding: 4px;
            vertical-align: top;
        }
        th {
            background: #f3f4f6;
            font-size: {{ (float) data_get($setup ?? [], 'table_header_font_size', 10) }}px;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .qa-line {
            margin-top: 14px;
            font-size: 12px;
        }
        .qa-line .fill {
            display: inline-block;
            border-bottom: 1px solid #111827;
            min-width: 180px;
            height: 16px;
            vertical-align: middle;
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
        $ccpLines = collect(preg_split('/\r\n|\r|\n/', $ccpMessage) ?: [])
            ->map(fn ($line): string => trim((string) $line))
            ->filter(fn (string $line): bool => $line !== '')
            ->values();
        $showQaSignoff = (bool) ($resolvedSetup['show_qa_signoff'] ?? true);
        $showIssueHistory = (bool) ($resolvedSetup['show_issue_history'] ?? true);

        $defaultColumns = [
            ['key' => 'tested_date', 'label' => 'Date', 'width' => 6, 'visible' => true],
            ['key' => 'tested_time', 'label' => 'Time', 'width' => 4, 'visible' => true],
            ['key' => 'mo_number', 'label' => 'MO number', 'width' => 8, 'visible' => true],
            ['key' => 'batch_number', 'label' => 'Batch number', 'width' => 8, 'visible' => true],
            ['key' => 'ph', 'label' => 'pH', 'width' => 10, 'visible' => true],
            ['key' => 'acidity_acetic', 'label' => 'Acidity (as acetic)', 'width' => 10, 'visible' => true],
            ['key' => 'acidity_citric', 'label' => 'Acidity (as citric)', 'width' => 5, 'visible' => true],
            ['key' => 'salt', 'label' => 'Salt', 'width' => 12, 'visible' => true],
            ['key' => 'viscosity_brookfield', 'label' => 'Viscosity (Brookfield)', 'width' => 11, 'visible' => true],
            ['key' => 'viscosity_bostwick', 'label' => 'Viscosity (Bostwick)', 'width' => 5, 'visible' => true],
            ['key' => 'aw', 'label' => 'aW', 'width' => 5, 'visible' => true],
            ['key' => 'solids', 'label' => 'Solids', 'width' => 10, 'visible' => true],
            ['key' => 'appearance', 'label' => 'Appearance', 'width' => 10, 'visible' => true],
            ['key' => 'tested_by', 'label' => 'Test by (Print name)', 'width' => 11, 'visible' => true],
        ];

        $configuredColumns = collect(is_array($resolvedSetup['columns'] ?? null) ? $resolvedSetup['columns'] : [])
            ->filter(fn ($column): bool => is_array($column) && (bool) ($column['visible'] ?? false))
            ->values();

        $visibleColumns = $configuredColumns->isNotEmpty()
            ? $configuredColumns
            : collect($defaultColumns)->filter(fn ($column): bool => (bool) ($column['visible'] ?? false))->values();

        $resolveCell = static function ($row, string $key): string {
            return match ($key) {
                'tested_date' => $row->tested_date?->toDateString() ?? '—',
                'tested_time' => $row->tested_time ? \Illuminate\Support\Carbon::parse($row->tested_time)->format('H:i') : '—',
                'mo_number' => (string) ($row->mo_number ?? '—'),
                'batch_number' => (string) ($row->batch_number ?? '—'),
                'ph' => (string) ($row->ph ?? '—'),
                'acidity_acetic' => (string) ($row->acidity_acetic ?? '—'),
                'acidity_citric' => (string) ($row->acidity_citric ?? '—'),
                'salt' => (string) ($row->salt ?? '—'),
                'viscosity_brookfield' => (string) ($row->viscosity_brookfield ?? '—'),
                'viscosity_bostwick' => (string) ($row->viscosity_bostwick ?? '—'),
                'aw' => (string) ($row->aw ?? '—'),
                'solids' => (string) ($row->solids ?? '—'),
                'appearance' => (string) ($row->appearance ?? '—'),
                'tested_by' => (string) ($row->tested_by ?? '—'),
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
            <h1 class="title">WM005 - Wet mustard lab testing</h1>
        </div>
    </div>

    <div class="sheet-wrap">
        @if ($showCcpBlock)
            <div class="ccp">
                @foreach ($ccpLines as $line)
                    <div>{{ $line }}</div>
                @endforeach
            </div>
        @endif

        <table>
            <colgroup>
                @foreach ($visibleColumns as $column)
                    <col style="width: {{ is_numeric($column['width'] ?? null) ? (float) $column['width'] : 8 }}%;" />
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
                    @for ($i = 0; $i < 14; $i++)
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

    @if ($showQaSignoff)
        <div class="qa-line">
            QA Approval: Name: <span class="fill"></span>
            Signed: <span class="fill" style="min-width: 240px;"></span>
        </div>
        <div class="qa-line">
            Date: <span class="fill" style="min-width: 180px;"></span>
        </div>
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
                    @php
                        $latestChange = $changes->first();
                    @endphp
                    @if ($latestChange)
                        <tr>
                            <td>{{ $latestChange->issue_version ?: ($document?->version ?? '—') }}</td>
                            <td>{{ $latestChange->date_issued?->format('d-m-Y') ?: ($document?->issue_date?->format('d-m-Y') ?? '—') }}</td>
                            <td>{{ $latestChange->issued_by ?: '—' }}</td>
                            <td>{{ $latestChange->reason_for_change ?: '—' }}</td>
                        </tr>
                    @else
                        <tr>
                            <td>{{ $document?->version ?? '—' }}</td>
                            <td>{{ $document?->issue_date?->format('d-m-Y') ?? '—' }}</td>
                            <td>—</td>
                            <td>No document issue history configured in Settings.</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    @endif
</body>
</html>
