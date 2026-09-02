<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page {
            size: A4 landscape;
            margin: 8mm;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 9px;
            color: #111;
            margin: 0;
        }

        .sheet {
            page-break-inside: avoid;
            margin-bottom: 8mm;
        }

        .top-rule {
            border-top: 1px solid #222;
            margin-bottom: 1px;
        }

        .header-line {
            text-align: center;
            font-size: 11px;
            font-weight: 700;
            color: #d70000;
            padding: 1px 0;
        }

        .orange-ref-line {
            background: #ffcd00;
            border: 1px solid #222;
            border-top: none;
            text-align: center;
            font-size: 11px;
            font-weight: 700;
            color: #111;
            padding: 2px 0;
        }

        .red-line {
            color: #d70000;
            font-size: 10px;
            font-weight: 700;
            text-align: center;
            margin-top: 1px;
        }

        .sheet-subtitle {
            text-align: center;
            font-size: 12px;
            font-weight: 700;
            text-decoration: underline;
            margin: 3px 0 4px;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        .meta td {
            padding: 2px 3px;
            vertical-align: top;
            font-size: 9px;
        }

        .meta-label {
            font-weight: 700;
            width: 90px;
            white-space: nowrap;
        }

        .grid th,
        .grid td {
            border: 1px solid #222;
            padding: 2px 3px;
            font-size: 8px;
        }

        .grid {
            table-layout: fixed;
        }

        .grid th {
            background: #f3f3f3;
            font-weight: 700;
            text-align: left;
        }

        .grid .num,
        .grid th.num {
            text-align: right;
        }

        .batch-cols th {
            text-align: center;
            width: 22px;
            padding: 2px 0;
        }

        .batch-cell {
            width: 22px;
            height: auto;
            min-height: 20px;
            padding: 1px 0;
            vertical-align: top;
            text-align: center;
            font-size: 6px;
            line-height: 1.1;
        }

        .batch-mark {
            white-space: pre-line;
            font-weight: 700;
            text-transform: uppercase;
            padding-top: 1px;
        }

        .desc-col {
            width: 210px;
        }

        .desc-cell {
            white-space: normal;
            overflow: visible;
            word-break: break-word;
            line-height: 1.15;
        }

        .step-row td {
            border: 1px solid #222;
            padding: 1px 3px;
            font-size: 8px;
        }

        .step-no {
            width: 36px;
            font-weight: 700;
            background: #ededed;
            white-space: nowrap;
        }

        .sign-grid td,
        .sign-grid th {
            border: 1px solid #222;
            height: 20px;
            font-size: 8px;
            padding: 2px 3px;
        }

        .sign-grid {
            table-layout: fixed;
        }

        .sign-grid .label {
            width: 86px;
            font-weight: 700;
            background: #e5e5e5;
        }

        .lot-col {
            width: 78px;
        }

        .batch-col {
            width: 22px;
            text-align: center;
        }

        .foot-note {
            color: #d70000;
            font-size: 9px;
            font-weight: 700;
            text-align: center;
            margin-top: 4px;
        }

        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>
    @if(empty($sections))
        <div class="sheet">
            <div class="top-rule"></div>
            <div class="header-line">WET MUSTARD BATCHCARD</div>
            <div class="orange-ref-line">DAILY WET MUSTARD - MANUFACTURING</div>
            <div class="sheet-subtitle">BATCH CARD &amp; PROCESS SHEET</div>
            <div style="border:1px solid #222; padding:12px; font-size:10px; text-align:center; margin-top:4px;">
                No production batches found for the selected period.
            </div>
        </div>
    @endif

    @foreach($sections as $index => $section)
        <div class="sheet">
            <div class="top-rule"></div>
            <div class="header-line">WET MUSTARD BATCHCARD</div>
            <div class="orange-ref-line">{{ $section['header_reference_line'] }}</div>
            <div class="red-line">These records have been identified under HACCP as CCP's and must be completed correctly</div>
            <div class="sheet-subtitle">BATCH CARD &amp; PROCESS SHEET</div>

            <table class="meta" style="margin-bottom:3px;">
                <tr>
                    <td style="width:28%;">
                        <table>
                            <tr><td class="meta-label">REVISION No.</td><td>{{ $section['revision_no'] }}</td></tr>
                            <tr><td class="meta-label">ISSUE DATE:</td><td>{{ $section['issue_date'] }}</td></tr>
                            <tr><td class="meta-label">REASON FOR ISSUE:</td><td>{{ $section['reason_for_issue'] }}</td></tr>
                        </table>
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
                        </table>
                    </td>
                </tr>
            </table>

            <table class="grid">
                <colgroup>
                    <col style="width:72px;" />
                    @unless(!empty($section['merge_allergen_material']))
                        <col style="width:72px;" />
                    @endunless
                    <col class="desc-col" />
                    <col style="width:42px;" />
                    <col style="width:60px;" />
                    <col style="width:30px;" />
                    <col class="lot-col" />
                    <col class="batch-col" /><col class="batch-col" /><col class="batch-col" /><col class="batch-col" />
                    <col class="batch-col" /><col class="batch-col" /><col class="batch-col" /><col class="batch-col" />
                </colgroup>
                <thead>
                    <tr>
                        <th>{{ !empty($section['merge_allergen_material']) ? 'ALLERGEN / MATERIAL' : 'ALLERGEN MATERIAL' }}</th>
                        @unless(!empty($section['merge_allergen_material']))
                            <th>MATERIAL CODE</th>
                        @endunless
                        <th>DESCRIPTION</th>
                        <th class="num">%</th>
                        <th class="num">QUANTITY</th>
                        <th>UOM</th>
                        <th>LOT NUMBER</th>
                        <th colspan="8" style="text-align:center;">Batch Number</th>
                    </tr>
                    <tr class="batch-cols">
                        <th colspan="{{ !empty($section['merge_allergen_material']) ? 6 : 7 }}"></th>
                        <th>1</th><th>2</th><th>3</th><th>4</th><th>5</th><th>6</th><th>7</th><th>8</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($section['components'] as $component)
                        <tr>
                            <td>
                                @if(!empty($section['merge_allergen_material']))
                                    {{ trim(($component['allergen_material'] !== '' ? $component['allergen_material'].' ' : '').$component['material_code']) }}
                                @else
                                    {{ $component['allergen_material'] }}
                                @endif
                            </td>
                            @unless(!empty($section['merge_allergen_material']))
                                <td>{{ $component['material_code'] }}</td>
                            @endunless
                            <td class="desc-cell">{{ (string) ($component['description'] ?? '') }}</td>
                            <td class="num">{{ $component['percent'] }}</td>
                            <td class="num">{{ $component['quantity'] }}</td>
                            <td>{{ $component['uom'] }}</td>
                            <td>{{ $component['lot_number'] }}</td>
                            @for($i = 0; $i < 8; $i++)
                                @php
                                    $markToken = (string) ($component['batch_marks'][$i] ?? '');
                                    $hasWeighted = str_contains($markToken, 'W');
                                    $hasTipped = str_contains($markToken, 'T');
                                @endphp
                                <td class="batch-cell">
                                    @if ($hasWeighted || $hasTipped)
                                        <div class="batch-mark">
                                            @if($hasWeighted)
                                                Weighted
                                            @endif
                                            @if($hasWeighted && $hasTipped)
                                                <br>
                                            @endif
                                            @if($hasTipped)
                                                Tipped
                                            @endif
                                        </div>
                                    @endif
                                </td>
                            @endfor
                        </tr>
                    @endforeach
                    <tr>
                        <td colspan="{{ !empty($section['merge_allergen_material']) ? 2 : 3 }}" class="num"><strong>Total</strong></td>
                        <td class="num"><strong>{{ $section['percent_total'] }}</strong></td>
                        <td class="num"><strong>{{ $section['quantity_total'] }}</strong></td>
                        <td><strong>KG</strong></td>
                        <td></td>
                        <td class="batch-cell"></td><td class="batch-cell"></td><td class="batch-cell"></td><td class="batch-cell"></td>
                        <td class="batch-cell"></td><td class="batch-cell"></td><td class="batch-cell"></td><td class="batch-cell"></td>
                    </tr>
                </tbody>
            </table>

            <table style="width:100%; border-collapse:collapse; margin-top:2px;">
                @foreach($section['steps'] as $step)
                    <tr class="step-row">
                        <td class="step-no">STEP {{ $step['number'] }}</td>
                        <td>{{ $step['text'] }}</td>
                    </tr>
                @endforeach
            </table>

            <table class="sign-grid" style="margin-top:12px; width:100%; border-collapse:collapse;">
                <colgroup>
                    <col style="width:86px;" />
                    <col style="width:72px;" />
                    <col style="width:72px;" />
                    <col style="width:72px;" />
                    <col style="width:72px;" />
                    <col style="width:72px;" />
                    <col class="lot-col" />
                    <col class="batch-col" /><col class="batch-col" /><col class="batch-col" /><col class="batch-col" />
                    <col class="batch-col" /><col class="batch-col" /><col class="batch-col" /><col class="batch-col" />
                </colgroup>
                <tr>
                    <td class="label">MILL GAP SIZE USED</td>
                    <td colspan="5">{{ $section['mill_gap_size_used'] ?? '—' }}</td>
                    <td class="label" style="text-align:center;">BATCH NUMBER</td>
                    @for($i=0; $i<8; $i++)
                        <td>{{ $section['label_batch_numbers'][$i] ?? '' }}</td>
                    @endfor
                </tr>
                <tr>
                    <td class="label">P1 SPEED USED</td>
                    <td colspan="5">{{ $section['p1_speed_used'] ?? '—' }}</td>
                    <td class="label" style="text-align:center;">SIGN &amp; DATE<br>(POWDERS WEIGHED):</td>
                    @for($i=0; $i<8; $i++)
                        <td class="batch-cell">
                            @if($i === 0)
                                <div class="batch-mark">{{ $section['powders_weighed_by'] ?? '—' }}
{{ $section['powders_weighed_date'] ?? '—' }}</div>
                            @endif
                        </td>
                    @endfor
                </tr>
                <tr>
                    <td class="label">P2 SPEED USED</td>
                    <td colspan="5">{{ $section['p2_speed_used'] ?? '—' }}</td>
                    <td class="label" style="text-align:center;">SIGN &amp; DATE<br>(LIQUIDS WEIGHED):</td>
                    @for($i=0; $i<8; $i++)
                        <td class="batch-cell">
                            @if($i === 0)
                                <div class="batch-mark">{{ $section['liquids_weighed_by'] ?? '—' }}
{{ $section['liquids_weighed_date'] ?? '—' }}</div>
                            @endif
                        </td>
                    @endfor
                </tr>
                <tr>
                    <td class="label"></td>
                    <td colspan="5"></td>
                    <td class="label" style="text-align:center;">SIGN &amp; DATE<br>(TIPPING BATCH):</td>
                    @for($i=0; $i<8; $i++)
                        <td class="batch-cell">
                            @if($i === 0)
                                <div class="batch-mark">{{ $section['tipping_batch_by'] ?? '—' }}
{{ $section['tipping_batch_date'] ?? '—' }}</div>
                            @endif
                        </td>
                    @endfor
                </tr>
            </table>

            <div class="foot-note">*In the event of any change to the lot number of an ingredient, a new sheet must be initiated to ensure accurate tracking and documentation.*</div>
        </div>

        @if(!$loop->last)
            <div class="page-break"></div>
        @endif
    @endforeach
</body>
</html>
