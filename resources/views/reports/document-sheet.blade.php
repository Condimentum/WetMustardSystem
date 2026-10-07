<!doctype html>
<html>
<head>
    <meta charset="utf-8" />
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            margin: {{ (float) data_get($setup, 'margin_top', 20) }}px {{ (float) data_get($setup, 'margin_right', 20) }}px {{ (float) data_get($setup, 'margin_bottom', 20) }}px {{ (float) data_get($setup, 'margin_left', 20) }}px;
            color: #111827;
            font-size: {{ (float) data_get($setup, 'base_font_size', 11) }}px;
            line-height: {{ (float) data_get($setup, 'line_height', 1.25) }};
            padding-bottom: {{ (bool) data_get($setup, 'show_issue_history', true) ? 170 : 20 }}px;
        }
        .header {
            display: table;
            padding: 4px 3px;
            width: 100%;
            margin-bottom: 12px;
            line-height: 1.25;
        }
        .header .left,
        .header .right {
            display: table-cell;
            vertical-align: middle;
        }
        .header .right {
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
        }
        .sheet-wrap {
            border: 2px solid #111827;
            padding: {{ (float) data_get($setup, 'content_padding', 10) }}px;
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
            word-wrap: break-word;
        }
        th {
            background: #f3f4f6;
            font-size: {{ (float) data_get($setup, 'table_header_font_size', 10) }}px;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .empty {
            text-align: center;
            color: #6b7280;
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
    </style>
</head>
<body>
    @php
        $ccpLines = collect(preg_split('/\r\n|\r|\n/', trim((string) data_get($setup, 'ccp_message', ''))) ?: [])
            ->map(fn ($line): string => trim((string) $line))
            ->filter(fn (string $line): bool => $line !== '')
            ->values();

        $logoSrc = '';
        $logoPath = public_path('assets/condimentum-logo.png');
        if ((bool) data_get($setup, 'show_logo', true) && is_file($logoPath)) {
            $logoData = @file_get_contents($logoPath);
            if ($logoData !== false) {
                $logoSrc = 'data:image/png;base64,'.base64_encode($logoData);
            }
        }
    @endphp

    <div class="header">
        <div class="left" style="width: 180px;">
            @if ($logoSrc !== '')
                <img src="{{ $logoSrc }}" alt="Condimentum" style="width: 170px; height: auto;" />
            @endif
        </div>
        <div class="right">
            <h1 class="title">{{ $title }}</h1>
        </div>
    </div>

    <div class="sheet-wrap">
        @if ((bool) data_get($setup, 'show_ccp_block', true) && $ccpLines->isNotEmpty())
            <div class="ccp">
                @foreach ($ccpLines as $line)
                    <div>{{ $line }}</div>
                @endforeach
            </div>
        @endif

        <table>
            <colgroup>
                @foreach ($columns as $column)
                    <col style="width: {{ (float) $column['width'] }}%;" />
                @endforeach
            </colgroup>
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th>{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        @foreach ($row as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ max(1, count($columns)) }}" class="empty">No records for the selected period.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ((bool) data_get($setup, 'show_qa_signoff', true))
        <div class="qa-line">
            QA Approval: Name: <span class="fill"></span>
            Signed: <span class="fill" style="min-width: 240px;"></span>
        </div>
        <div class="qa-line">
            Date: <span class="fill" style="min-width: 180px;"></span>
        </div>
    @endif

    @if ((bool) data_get($setup, 'show_issue_history', true))
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
