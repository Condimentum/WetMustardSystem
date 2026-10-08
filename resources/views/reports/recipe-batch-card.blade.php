<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    @php
        $batchColumns = \App\Domains\Reporting\Support\BatchCardBuilder::BATCH_COLUMNS;
        $batchArea = (float) ($setup['batch_area_percent'] ?? 54);
        $ingredientArea = 100 - $batchArea;
        $columnTotal = max(0.01, (float) collect($columns)->sum(fn ($column): float => (float) $column['width']));
        $batchColumnWidth = $batchArea / $batchColumns;
        $fields = collect(app(\App\Domains\Reporting\Support\DocumentSources::class)->fields(\App\Domains\Reporting\Support\DocumentSources::RECIPE_BATCH_CARD))->keyBy('key');
        $numericKeys = ['percent', 'quantity'];
        // "Total" sits in the last text column before the first numeric column.
        $firstNumeric = collect($columns)->search(fn ($column): bool => in_array($column['key'], $numericKeys, true));
        $totalLabelIndex = $firstNumeric === false || $firstNumeric === 0 ? null : $firstNumeric - 1;
        $ccpLines = collect(preg_split('/\r\n|\r|\n/', (string) ($setup['ccp_message'] ?? '')) ?: [])->map(fn ($line): string => trim((string) $line))->filter()->values();
        $baseFont = (float) ($setup['base_font_size'] ?? 8);
        $headerFont = (float) ($setup['table_header_font_size'] ?? 8);
    @endphp
    <style>
        @page { margin: {{ (float) $setup['margin_top'] }}px {{ (float) $setup['margin_right'] }}px {{ (float) $setup['margin_bottom'] }}px {{ (float) $setup['margin_left'] }}px; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: {{ $baseFont }}px; line-height: {{ (float) ($setup['line_height'] ?? 1.25) }}; color: #111; margin: 0; }
        .sheet { page-break-inside: avoid; }
        .top-rule { border-top: 1px solid #222; margin-bottom: 1px; }
        .header-line { text-align: center; font-size: 11px; font-weight: 700; color: #d70000; padding: 1px 0; }
        .ref-line { background: #ffcd00; border: 1px solid #222; text-align: center; font-size: 11px; font-weight: 700; padding: 2px 0; }
        .red-line { color: #d70000; font-size: 10px; font-weight: 700; text-align: center; margin-top: 1px; }
        .subtitle { text-align: center; font-size: 12px; font-weight: 700; text-decoration: underline; margin: 3px 0 4px; }
        .sheet-no { text-align: right; font-size: 8px; color: #555; }
        table { border-collapse: collapse; width: 100%; }
        .meta td { padding: 2px 3px; vertical-align: top; font-size: 9px; }
        .meta-label { font-weight: 700; width: 90px; white-space: nowrap; }
        .grid { table-layout: fixed; }
        .grid th, .grid td { border: 1px solid #222; padding: 2px 3px; word-wrap: break-word; }
        .grid th { background: #f3f3f3; font-weight: 700; text-align: left; font-size: {{ $headerFont }}px; text-transform: uppercase; }
        .grid .num { text-align: right; }
        .grid th.batch-no { text-align: center; padding: 2px 0; }
        .batch-cell { text-align: center; font-size: 6px; line-height: 1.1; padding: 1px 0; vertical-align: top; white-space: pre-line; font-weight: 700; text-transform: uppercase; }
        .steps td { border: 1px solid #222; padding: 1px 3px; }
        .step-no { width: 40px; font-weight: 700; background: #ededed; white-space: nowrap; }
        .sign-grid td { border: 1px solid #222; height: 20px; padding: 2px 3px; }
        .sign-grid .label { font-weight: 700; background: #e5e5e5; }
        .foot-note { color: #d70000; font-size: 9px; font-weight: 700; text-align: center; margin-top: 4px; }
        .page-break { page-break-after: always; }
    </style>
</head>
<body>
    @foreach ($sections as $section)
        @php
            $offset = (int) ($section['batch_offset'] ?? 0);
        @endphp
        <div class="sheet">
            <div class="top-rule"></div>
            <div class="header-line">WET MUSTARD BATCHCARD</div>
            <div class="ref-line">{{ $section['header_reference_line'] }}</div>
            @if (($setup['show_ccp_block'] ?? true) && $ccpLines->isNotEmpty())
                @foreach ($ccpLines as $line)
                    <div class="red-line">{{ $line }}</div>
                @endforeach
            @endif
            <div class="subtitle">BATCH CARD &amp; PROCESS SHEET</div>
            @if ((int) ($section['sheet_number'] ?? 1) > 1)
                <div class="sheet-no">Continuation sheet {{ $section['sheet_number'] }} (batches {{ $offset + 1 }}-{{ $offset + $batchColumns }})</div>
            @endif

            <table class="meta" style="margin-bottom:3px;">
                <tr>
                    <td style="width:28%;">
                        @if ($setup['show_issue_history'] ?? true)
                            <table>
                                <tr><td class="meta-label">REVISION No.</td><td>{{ $section['revision_no'] }}</td></tr>
                                <tr><td class="meta-label">ISSUE DATE:</td><td>{{ $section['issue_date'] }}</td></tr>
                                <tr><td class="meta-label">REASON FOR ISSUE:</td><td>{{ $section['reason_for_issue'] }}</td></tr>
                            </table>
                        @endif
                    </td>
                    <td style="width:40%;">
                        <table>
                            <tr><td class="meta-label">Recipe Code:</td><td>{{ $section['recipe_code'] }}</td></tr>
                            <tr><td class="meta-label">PLC Recipe Number:</td><td>{{ $section['plc_recipe_number'] }}</td></tr>
                            <tr><td class="meta-label">Product:</td><td>{{ $section['product_description'] }}</td></tr>
                        </table>
                    </td>
                    <td style="width:32%;">
                        <table>
                            <tr><td class="meta-label">MO</td><td>{{ $section['mo_number'] }}</td></tr>
                            @if (($section['batch_size_kg'] ?? null) !== null)
                                <tr><td class="meta-label">Batch size:</td><td>{{ rtrim(rtrim(number_format((float) $section['batch_size_kg'], 3, '.', ''), '0'), '.') }} kg</td></tr>
                            @endif
                        </table>
                    </td>
                </tr>
            </table>

            <table class="grid">
                <colgroup>
                    @foreach ($columns as $column)
                        <col style="width: {{ round((float) $column['width'] / $columnTotal * $ingredientArea, 3) }}%;" />
                    @endforeach
                    @for ($i = 0; $i < $batchColumns; $i++)
                        <col style="width: {{ round($batchColumnWidth, 3) }}%;" />
                    @endfor
                </colgroup>
                <thead>
                    <tr>
                        @foreach ($columns as $column)
                            <th class="{{ in_array($column['key'], $numericKeys, true) ? 'num' : '' }}">{{ $column['label'] }}</th>
                        @endforeach
                        <th colspan="{{ $batchColumns }}" style="text-align:center;">Batch Number</th>
                    </tr>
                    <tr>
                        <th colspan="{{ max(1, count($columns)) }}"></th>
                        @for ($i = 1; $i <= $batchColumns; $i++)
                            <th class="batch-no">{{ $offset + $i }}</th>
                        @endfor
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section['components'] as $component)
                        <tr>
                            @foreach ($columns as $column)
                                @php
                                    $resolver = $fields->get($column['key'])['value'] ?? null;
                                @endphp
                                <td class="{{ in_array($column['key'], $numericKeys, true) ? 'num' : '' }}">{{ $resolver ? $resolver($component) : '' }}</td>
                            @endforeach
                            @for ($i = 0; $i < $batchColumns; $i++)
                                @php
                                    $mark = (string) ($component['batch_marks'][$i] ?? '');
                                @endphp
                                <td class="batch-cell">{{ trim((str_contains($mark, 'W') ? "Weighted\n" : '').(str_contains($mark, 'T') ? 'Tipped' : '')) }}</td>
                            @endfor
                        </tr>
                    @endforeach
                    <tr>
                        @foreach ($columns as $index => $column)
                            <td class="num" style="font-weight:700;">
                                @if ($index === $totalLabelIndex)
                                    Total
                                @elseif ($column['key'] === 'percent')
                                    {{ $section['percent_total'] }}
                                @elseif ($column['key'] === 'quantity')
                                    {{ $section['quantity_total'] }}
                                @elseif ($column['key'] === 'uom')
                                    KG
                                @endif
                            </td>
                        @endforeach
                        @for ($i = 0; $i < $batchColumns; $i++)
                            <td></td>
                        @endfor
                    </tr>
                </tbody>
            </table>

            @if ($setup['show_steps'] ?? true)
                <table class="steps" style="margin-top:2px;">
                    @foreach ($section['steps'] as $step)
                        <tr>
                            <td class="step-no">STEP {{ $step['number'] }}</td>
                            <td>{{ $step['text'] }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif

            @php
                $processRows = ($setup['show_process_settings'] ?? true)
                    ? [['MILL GAP SIZE USED', $section['mill_gap_size_used'] ?? '—'], ['P1 SPEED USED', $section['p1_speed_used'] ?? '—'], ['P2 SPEED USED', $section['p2_speed_used'] ?? '—'], ['', '']]
                    : [['', ''], ['', ''], ['', ''], ['', '']];
                $signRows = [
                    ['BATCH NUMBER', $section['label_batch_numbers'] ?? []],
                ];
                if ($setup['show_qa_signoff'] ?? true) {
                    $signRows[] = ["SIGN & DATE\n(POWDERS WEIGHED):", $section['signoff_columns']['powders'] ?? []];
                    $signRows[] = ["SIGN & DATE\n(LIQUIDS WEIGHED):", $section['signoff_columns']['liquids'] ?? []];
                    $signRows[] = ["SIGN & DATE\n(TIPPING BATCH):", $section['signoff_columns']['tipping'] ?? []];
                }
                $rowCount = max(count($signRows), ($setup['show_process_settings'] ?? true) ? 3 : 1);
                $signRows = array_pad($signRows, $rowCount, ['', []]);
            @endphp
            <table class="grid sign-grid" style="margin-top:12px;">
                <colgroup>
                    <col style="width: {{ round($ingredientArea * 0.2, 3) }}%;" />
                    <col style="width: {{ round($ingredientArea * 0.55, 3) }}%;" />
                    <col style="width: {{ round($ingredientArea * 0.25, 3) }}%;" />
                    @for ($i = 0; $i < $batchColumns; $i++)
                        <col style="width: {{ round($batchColumnWidth, 3) }}%;" />
                    @endfor
                </colgroup>
                @foreach ($signRows as $rowIndex => [$signLabel, $cells])
                    <tr>
                        <td class="label">{{ $processRows[$rowIndex][0] ?? '' }}</td>
                        <td>{{ $processRows[$rowIndex][1] ?? '' }}</td>
                        <td class="label" style="text-align:center; white-space:pre-line;">{{ $signLabel }}</td>
                        @for ($i = 0; $i < $batchColumns; $i++)
                            <td class="batch-cell">{{ $cells[$i] ?? '' }}</td>
                        @endfor
                    </tr>
                @endforeach
            </table>

            @if (trim((string) ($setup['footnote'] ?? '')) !== '')
                <div class="foot-note">{{ $setup['footnote'] }}</div>
            @endif
        </div>

        @if (! $loop->last)
            <div class="page-break"></div>
        @endif
    @endforeach
</body>
</html>
