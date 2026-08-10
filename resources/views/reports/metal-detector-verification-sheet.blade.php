<!doctype html>
<html>
<head>
    <meta charset="utf-8" />
    <title>Metal detector verification sheet</title>
    <style>
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            margin: 20px;
            color: #111827;
            font-size: 12px;
            padding-bottom: 150px;
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
            font-size: 13px;
            color: #374151;
        }
        .ccp {
            text-align: center;
            color: #dc2626;
            font-weight: 700;
            margin: 10px 0 8px;
        }
        .sheet {
            border: 2px solid #111827;
            padding: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            border: 1px solid #111827;
            padding: 6px 5px;
            vertical-align: top;
        }
        th {
            background: #f3f4f6;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .center {
            text-align: center;
        }
        .pass {
            color: #15803d;
            font-weight: 700;
        }
        .fail {
            color: #b91c1c;
            font-weight: 700;
        }
        .na {
            color: #1d4ed8;
            font-weight: 700;
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
            @if ($logoSrc !== '')
                <img src="{{ $logoSrc }}" alt="Condimentum" style="width: 170px; height: auto;" />
            @else
                <div class="muted" style="font-size: 12px;">Condimentum</div>
            @endif
        </div>
        <div class="right">
            <h1 class="title">{{ $document?->code ? $document->code.' - ' : '' }}Metal detector verification sheet</h1>
        </div>
    </div>

    <div class="sheet">
        <div class="ccp">CCP - Frequency - start of shift, every hour and end of shift</div>

        <table>
            <thead>
                <tr>
                    <th class="center">#</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Ferrous 1.0</th>
                    <th>Non-Ferrous 1.5</th>
                    <th>SS 2.0</th>
                    <th>Bin Locked</th>
                    <th>Bin Empty</th>
                    <th>Check Type</th>
                    <th>Operator</th>
                    <th>Comments</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($checks as $index => $check)
                    @php
                        $startupColumnsRequired = (string) $check->check_type === \App\Models\MetalDetectorCheck::TYPE_START;
                        $startupLockedLabel = $startupColumnsRequired
                            ? ($check->bin_locked ? 'Yes' : 'No')
                            : 'Not Required';
                        $startupEmptyLabel = $startupColumnsRequired
                            ? ($check->bin_empty ? 'Yes' : 'No')
                            : 'Not Required';
                        $commentText = trim((string) $check->comments);
                        $failureText = trim((string) $check->failure_action);
                        if ($failureText !== '') {
                            $commentText = $commentText !== ''
                                ? $commentText.' | Failure action: '.$failureText
                                : 'Failure action: '.$failureText;
                        }
                    @endphp
                    <tr>
                        <td class="center">{{ $index + 1 }}</td>
                        <td>{{ $check->check_time?->format('Y-m-d') }}</td>
                        <td>{{ $check->check_time?->format('H:i:s') }}</td>
                        <td class="center {{ $check->fe10_pass ? 'pass' : 'fail' }}">{{ $check->fe10_pass ? 'Pass' : 'Fail' }}</td>
                        <td class="center {{ $check->non_fe15_pass ? 'pass' : 'fail' }}">{{ $check->non_fe15_pass ? 'Pass' : 'Fail' }}</td>
                        <td class="center {{ $check->ss20_pass ? 'pass' : 'fail' }}">{{ $check->ss20_pass ? 'Pass' : 'Fail' }}</td>
                        <td class="center {{ $startupColumnsRequired ? ($check->bin_locked ? 'pass' : 'fail') : 'na' }}">{{ $startupLockedLabel }}</td>
                        <td class="center {{ $startupColumnsRequired ? ($check->bin_empty ? 'pass' : 'fail') : 'na' }}">{{ $startupEmptyLabel }}</td>
                        <td>{{ \Illuminate\Support\Str::headline((string) $check->check_type) }}{{ $check->is_recheck ? ' (recheck)' : '' }}</td>
                        <td>{{ $check->signedBy?->name ?? '—' }}</td>
                        <td>{{ $commentText !== '' ? $commentText : '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="center muted">No checks recorded for this date.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="qa-line">
        QA Approval: Name: <span class="fill"></span>
        Signed: <span class="fill" style="min-width: 240px;"></span>
    </div>
    <div class="qa-line">
        Date: <span class="fill" style="min-width: 180px;"></span>
    </div>

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
                @forelse ($documentChanges as $change)
                    <tr>
                        <td>{{ $change->issue_version ?: '—' }}</td>
                        <td>{{ $change->date_issued?->format('d-m-Y') ?: '—' }}</td>
                        <td>{{ $change->issued_by ?: '—' }}</td>
                        <td>{{ $change->reason_for_change ?: '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td>{{ $document?->version ?: '—' }}</td>
                        <td>{{ $document?->issue_date?->format('d-m-Y') ?: '—' }}</td>
                        <td>—</td>
                        <td>No document issue history configured in Settings.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</body>
</html>
