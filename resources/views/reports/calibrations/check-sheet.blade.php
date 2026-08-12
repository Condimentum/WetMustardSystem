<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #111827; font-size: 12px; padding-bottom: 170px; }
        .head { margin-bottom: 12px; }
        .logo-wrap { margin-bottom: 8px; }
        .logo-fallback { font-size: 12px; color: #4b5563; }
        .code { font-size: 11px; color: #6b7280; }
        h1 { margin: 4px 0 8px 0; font-size: 20px; }
        .qa-meta { position: fixed; left: 0; right: 0; bottom: 0; }
        .meta { border-collapse: collapse; width: 100%; }
        .meta td, .meta th { border: 1px solid #d1d5db; padding: 6px; font-size: 11px; text-align: left; }
        .box { border: 1px solid #d1d5db; padding: 10px; margin-bottom: 12px; }
        .box h2 { margin: 0 0 8px 0; font-size: 12px; }
        .sheet { border-collapse: collapse; width: 100%; }
        .sheet th, .sheet td { border: 1px solid #9ca3af; padding: 6px; font-size: 11px; vertical-align: top; }
        .sheet th { background: #f3f4f6; }
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

    <div class="head">
        <div class="logo-wrap">
            @if ($logoSrc !== '')
                <img src="{{ $logoSrc }}" alt="Condimentum" style="width: 170px; height: auto;" />
            @else
                <div class="logo-fallback">Condimentum</div>
            @endif
        </div>
        <div class="code">{{ $documentCode }}</div>
        <h1>{{ $title }}</h1>
    </div>

    <div class="box">
        <h2>Instructions</h2>
        <ul style="margin:0 0 0 16px; padding:0;">
            @foreach ($instructions as $instruction)
                <li style="margin:0 0 4px 0;">{{ $instruction }}</li>
            @endforeach
        </ul>
    </div>

    <table class="sheet">
        <thead>
            <tr>
                @foreach ($headers as $header)
                    <th>{{ $header }}</th>
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
                    <td colspan="{{ count($headers) }}" style="text-align:center;color:#6b7280;">No records for the selected period.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="qa-meta">
        <table class="meta">
            <tr>
                <th style="width:25%;">Revision Number</th>
                <td>{{ $document?->version ?? '—' }}</td>
                <th style="width:25%;">Issue Date</th>
                <td>{{ $document?->issue_date?->toDateString() ?? '—' }}</td>
            </tr>
            <tr>
                <th>Issued By</th>
                <td>{{ $changes->first()?->issued_by ?? '—' }}</td>
                <th>Reason for Issue</th>
                <td>{{ $changes->first()?->reason_for_change ?? '—' }}</td>
            </tr>
        </table>
    </div>
</body>
</html>
