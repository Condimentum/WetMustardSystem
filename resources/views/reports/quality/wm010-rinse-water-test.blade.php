<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            color: #111827;
            font-size: {{ (float) data_get($setup ?? [], 'base_font_size', 11) }}px;
            line-height: {{ (float) data_get($setup ?? [], 'line_height', 1.25) }};
            margin: {{ (float) data_get($setup ?? [], 'margin_top', 20) }}px {{ (float) data_get($setup ?? [], 'margin_right', 20) }}px {{ (float) data_get($setup ?? [], 'margin_bottom', 20) }}px {{ (float) data_get($setup ?? [], 'margin_left', 20) }}px;
            padding-bottom: {{ (bool) data_get($setup ?? [], 'show_issue_history', true) ? 140 : 20 }}px;
        }
        .head { margin-bottom: 10px; }
        .title { font-size: 34px; font-weight: 800; margin: 8px 0; }
        h3 { margin: 12px 0 6px; font-size: 20px; }
        .note { font-weight: 700; margin-bottom: 4px; }
        .note-red { color: #b91c1c; font-weight: 700; }
        .sheet { border-collapse: collapse; width: 100%; margin-top: 6px; }
        .sheet th, .sheet td { border: 1px solid #111827; padding: 4px; font-size: {{ (float) data_get($setup ?? [], 'base_font_size', 11) - 1 }}px; }
        .sheet th { background: #f3f4f6; font-size: {{ (float) data_get($setup ?? [], 'table_header_font_size', 10) }}px; }
        .qa-meta { position: fixed; left: 0; right: 0; bottom: 0; }
        .meta { border-collapse: collapse; width: 100%; }
        .meta td, .meta th { border: 1px solid #d1d5db; padding: 6px; font-size: 10px; text-align: left; }
    </style>
</head>
<body>
    @php
        $resolvedSetup = is_array($setup ?? null) ? $setup : [];
        $showLogo = (bool) ($resolvedSetup['show_logo'] ?? true);
        $showQaSignoff = (bool) ($resolvedSetup['show_qa_signoff'] ?? true);
        $showIssueHistory = (bool) ($resolvedSetup['show_issue_history'] ?? true);

        $logoPath = public_path('assets/condimentum-logo.png');
        $logoSrc = '';

        if (is_file($logoPath)) {
            $logoData = @file_get_contents($logoPath);
            if ($logoData !== false) {
                $logoSrc = 'data:image/png;base64,'.base64_encode($logoData);
            }
        }

        $cleaning = collect($rows)->where('section', \App\Models\Wm010RinseWaterTestEntry::SECTION_CLEANING_CHEMICALS)->values();
        $sulphites = collect($rows)->where('section', \App\Models\Wm010RinseWaterTestEntry::SECTION_SULPHITES)->values();
        $titration = collect($rows)->where('section', \App\Models\Wm010RinseWaterTestEntry::SECTION_CHEMICAL_TITRATION)->values();
    @endphp

    <div class="head">
        @if ($showLogo && $logoSrc !== '')
            <img src="{{ $logoSrc }}" alt="Condimentum" style="width: 170px; height: auto;" />
        @endif
        <div class="title">WM010 Rinse water test sheet - chemical &amp; sulphite</div>
    </div>

    <h3>CLEANING CHEMICALS</h3>
    <div class="note">Post cleaning, test rinse water with pH stick. A result of 6.0 - 8.0 is a pass.</div>
    <table class="sheet">
        <thead><tr><th>Equipment</th><th>pH reading</th><th>Pass or Fail</th><th>Action taken if failed</th><th>Operator Name</th></tr></thead>
        <tbody>
            @forelse ($cleaning as $row)
                <tr><td>{{ $row->equipment ?? '—' }}</td><td>{{ $row->reading ?? '—' }}</td><td>{{ $row->pass_or_fail ?? '—' }}</td><td>{{ $row->action_taken_if_failed ?? '—' }}</td><td>{{ $row->operator_name ?? '—' }}</td></tr>
            @empty
                @for ($i = 0; $i < 8; $i++)
                    <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>
                @endfor
            @endforelse
        </tbody>
    </table>

    <h3>SULPHITES</h3>
    <div class="note">Post cleaning, test rinse water with Sulphite 500 test stick. A result of 0mg/Ltr is a pass.</div>
    <div class="note-red">ONLY REQUIRED AFTER A SULPHITE CONTAINING RECIPE HAS BEEN PRODUCED.</div>
    <table class="sheet">
        <thead><tr><th>Equipment</th><th>Reading (mg/Ltr)</th><th>Pass or Fail</th><th>Action taken if failed</th><th>Operator Name</th></tr></thead>
        <tbody>
            @forelse ($sulphites as $row)
                <tr><td>{{ $row->equipment ?? '—' }}</td><td>{{ $row->reading ?? '—' }}</td><td>{{ $row->pass_or_fail ?? '—' }}</td><td>{{ $row->action_taken_if_failed ?? '—' }}</td><td>{{ $row->operator_name ?? '—' }}</td></tr>
            @empty
                @for ($i = 0; $i < 8; $i++)
                    <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>
                @endfor
            @endforelse
        </tbody>
    </table>

    <h3>Chemical titration check</h3>
    <table class="sheet">
        <thead><tr><th>Chemical used</th><th>Target</th><th>Result</th><th>Pass / Fail</th></tr></thead>
        <tbody>
            @forelse ($titration as $row)
                <tr><td>{{ $row->chemical_used ?? '—' }}</td><td>{{ $row->target ?? '—' }}</td><td>{{ $row->result ?? '—' }}</td><td>{{ $row->pass_or_fail ?? '—' }}</td></tr>
            @empty
                <tr><td>&nbsp;</td><td></td><td></td><td></td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($showQaSignoff)
        <div style="margin-top:10px; font-weight:700;">QA Signature..........................................................</div>
    @endif

    @if ($showIssueHistory)
        <div class="qa-meta">
            <table class="meta">
                <tr>
                    <th style="width:15%;">Issue Version</th>
                    <td>{{ $document?->version ?? '—' }}</td>
                    <th style="width:15%;">Date Issued</th>
                    <td>{{ $document?->issue_date?->toDateString() ?? '—' }}</td>
                    <th style="width:15%;">Issued by</th>
                    <td>{{ $changes->first()?->issued_by ?? '—' }}</td>
                    <th style="width:20%;">Reason for Change</th>
                    <td>{{ $changes->first()?->reason_for_change ?? '—' }}</td>
                </tr>
            </table>
        </div>
    @endif
</body>
</html>
