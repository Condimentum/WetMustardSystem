<?php

namespace App\Domains\Batch\Jobs;

use Smalot\PdfParser\Parser;

class ExtractBatchCardMetadataJob
{
    /** @return array{extracted_text:string,metadata:array<string,mixed>} */
    public function __invoke(string $absolutePdfPath): array
    {
        $text = '';
        $metadata = [];

        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($absolutePdfPath);
            $text = trim((string) $pdf->getText());
        } catch (\Throwable $e) {
            return [
                'extracted_text' => '',
                'metadata' => [
                    'extract_error' => $e->getMessage(),
                ],
            ];
        }

        $normalizedText = str_replace(["\r\n", "\r", "\f"], "\n", $text);
        $lines = $this->normaliseLines($normalizedText);

        $metadata['document_code'] = $this->matchText($text, [
            '/\b(?:document\s*code|doc\s*code|wm\s*doc(?:ument)?)\s*[:#]?\s*([A-Z]{2,6}\s*\d{2,8})\b/i',
            '/\b([A-Z]{2,6}\d{2,8})\b/',
        ]);

        $metadata['revision'] = $this->matchText($text, [
            '/\bREVISION\s*(?:NO\.?|NUMBER)?\s*:?\s*([A-Z0-9.\-]+)/i',
            '/\b(?:revision|rev|version)\s*[:#]?\s*([A-Z0-9.\-]+)/i',
        ]);

        $metadata['product_code'] = $this->matchText($text, [
            '/\b(?:product\s*(?:id|code|number))\s*[:#]?\s*([A-Z0-9\-]+)/i',
        ]);

        $metadata['title'] = $this->matchText($text, [
            '/\b(?:title|document\s*title|batch\s*card)\s*[:#]?\s*([^\r\n]+)/i',
        ]);

        $issueDateRaw = $this->matchText($text, [
            '/\bISSUE\s*DATE\s*:?\s*([0-9]{1,2}[.\/-][0-9]{1,2}[.\/-][0-9]{2,4})/i',
            '/\b(?:issue\s*date|issued\s*date)\s*[:#]?\s*([0-9]{1,2}[\/-][0-9]{1,2}[\/-][0-9]{2,4})/i',
        ]);
        $effectiveFromRaw = $this->matchText($text, [
            '/\b(?:effective\s*(?:from|date)|valid\s*from)\s*[:#]?\s*([0-9]{1,2}[\/-][0-9]{1,2}[\/-][0-9]{2,4})/i',
        ]);

        $metadata['issue_date'] = $this->normaliseDate($issueDateRaw);
        $metadata['effective_from'] = $this->normaliseDate($effectiveFromRaw);

        $cleanedDocumentCode = strtoupper(str_replace(' ', '', (string) ($metadata['document_code'] ?? '')));
        if ($cleanedDocumentCode !== '') {
            $metadata['document_code'] = $cleanedDocumentCode;
        }

        $metadata['recipe_code'] = $this->matchText($text, [
            '/\bRECIPE\s*CODE\s*:?\s*((?=[A-Z0-9\-]*\d)[A-Z0-9\-]+)/i',
        ]);

        $metadata['plc_recipe_number'] = $this->matchText($text, [
            '/\bPLC\s*RECIPE\s*NUMBER\s*:?\s*((?=[A-Z0-9\-]*\d)[A-Z0-9\-]+)/i',
        ]);

        $lineRecipeCode = $this->extractLabelValueFromLines($lines, ['RECIPE CODE'], true);
        if ($lineRecipeCode !== null) {
            $metadata['recipe_code'] = $lineRecipeCode;
        }

        $linePlcRecipe = $this->extractLabelValueFromLines($lines, ['PLC Recipe Number', 'PLC Recipe No'], true);
        if ($linePlcRecipe !== null) {
            $metadata['plc_recipe_number'] = $linePlcRecipe;
        }

        $metadata['reason_for_issue'] = $this->extractReasonForIssue($lines);

        $tableExtraction = $this->extractProcessTable($lines);
        $metadata['process_steps'] = $tableExtraction['process_steps'];
        $metadata['ingredients'] = $tableExtraction['ingredients'];
        $metadata['totals'] = $tableExtraction['totals'];
        $metadata['process_steps_count'] = count($tableExtraction['process_steps']);
        $metadata['ingredients_count'] = count($tableExtraction['ingredients']);

        return [
            'extracted_text' => $text,
            'metadata' => $metadata,
        ];
    }

    /** @return array<int, string> */
    private function normaliseLines(string $text): array
    {
        $rawLines = preg_split('/\n+/', $text) ?: [];

        return array_values(array_filter(array_map(function (string $line): string {
            return trim((string) preg_replace('/\s+/', ' ', $line));
        }, $rawLines), static fn (string $line): bool => $line !== ''));
    }

    private function extractReasonForIssue(array $lines): ?string
    {
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if (preg_match('/^REASON\s*FOR\s*ISSUE\s*:?\s*(.*)$/i', $line, $matches) !== 1) {
                continue;
            }

            $reason = trim((string) ($matches[1] ?? ''));
            if ($reason === '' && isset($lines[$i + 1])) {
                $reason = trim($lines[$i + 1]);
            }

            return $reason !== '' ? $reason : null;
        }

        return null;
    }

    /**
     * @param array<int, string> $lines
     * @return array{process_steps:array<int,array<string,mixed>>,ingredients:array<int,array<string,mixed>>,totals:array<string,mixed>}
     */
    private function extractProcessTable(array $lines): array
    {
        $inTable = false;
        $currentStep = null;
        $processSteps = [];
        $orphanProcessTitles = [];
        $ingredients = [];
        $pendingIngredientLine = null;
        $totals = [
            'percentage' => null,
            'quantity' => null,
            'uom' => null,
        ];

        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            if (! $inTable && preg_match('/ALLERGEN\s*MATERIAL|MATERIAL\s*CODE|UNIT\s*OF\s*MEASURE/i', $line) === 1) {
                $inTable = true;
                continue;
            }

            if (! $inTable && preg_match('/^STEP\s*1\b/i', $line) === 1) {
                // Some scanned/extracted PDFs lose the column headings but keep step rows.
                $inTable = true;
            }

            if (! $inTable) {
                continue;
            }

            if (preg_match('/^(?:MO\s+)?(MIX\s+FOR|MILL\s+THROUGH|PASS\s+THROUGH|DEAERATE|TAKE\s+QC\s+SAMPLE|METAL\s+DETECT|PACK\s*&\s*APPLY\s+LABEL)/i', $line) === 1) {
                $orphanProcessTitles[] = preg_replace('/^MO\s+/i', '', $line) ?? $line;
                continue;
            }

            if (preg_match('/^STEP\s*(\d+)\s*(.*)$/i', $line, $matches) === 1) {
                $pendingIngredientLine = null;
                $currentStep = (int) $matches[1];
                $title = trim((string) ($matches[2] ?? ''));

                if ($title === '' && isset($lines[$i + 1])) {
                    $nextLine = trim((string) $lines[$i + 1]);
                    if ($nextLine !== '' && preg_match('/^(STEP\s*\d+|TOTAL\b|LOT\b|M\/?L\s+GAP\s+SIZE\b)/i', $nextLine) !== 1) {
                        $title = $nextLine;
                    }
                }

                $processSteps[] = [
                    'step_no' => $currentStep,
                    'title' => $title,
                ];

                continue;
            }

            if (preg_match('/^TOTAL\s+([0-9]+(?:\.[0-9]+)?)\s+([0-9]+(?:\.[0-9]+)?)(?:\s*([A-Z]{1,6}))?$/i', $line, $matches) === 1) {
                $pendingIngredientLine = null;
                $totals = [
                    'percentage' => (float) $matches[1],
                    'quantity' => (float) $matches[2],
                    'uom' => trim((string) ($matches[3] ?? '')) ?: null,
                ];

                continue;
            }

            $parsedIngredient = $this->parseIngredientLine($line, $currentStep);
            if ($parsedIngredient !== null) {
                $pendingIngredientLine = null;
                $ingredients[] = $parsedIngredient;
                continue;
            }

            if ($pendingIngredientLine !== null && preg_match('/^(?<percentage>[0-9]+(?:\.[0-9]+)?)\s+(?<quantity>[0-9]+(?:\.[0-9]+)?)(?:\s*(?<uom>[A-Z]{1,6}))?$/', $line, $tail) === 1) {
                $parsedFromTail = $this->parseIngredientLine($pendingIngredientLine.' '.$line, $currentStep);
                if ($parsedFromTail !== null) {
                    $ingredients[] = $parsedFromTail;
                    $pendingIngredientLine = null;
                    continue;
                }
            }

            if (preg_match('/^(?:[A-Z][A-Z0-9\s&\/-]{1,30}\s+)?\d{6,10}\s*.+$/', $line) === 1) {
                $pendingIngredientLine = $line;
                continue;
            }

            if (preg_match('/^(?:[A-Z][A-Z0-9\s&\/-]{1,30}\s+)?\d{6,10}\s*$/', $line) === 1) {
                $window = [$line];
                for ($lookAhead = 1; $lookAhead <= 5; $lookAhead++) {
                    $candidate = trim((string) ($lines[$i + $lookAhead] ?? ''));
                    if ($candidate === '') {
                        continue;
                    }

                    if (preg_match('/^STEP\s*\d+\b/i', $candidate) === 1) {
                        break;
                    }

                    $window[] = $candidate;
                    $joined = implode(' ', $window);
                    $parsedWindow = $this->parseIngredientLine($joined, $currentStep);
                    if ($parsedWindow !== null) {
                        $ingredients[] = $parsedWindow;
                        $pendingIngredientLine = null;
                        $i += $lookAhead;
                        continue 2;
                    }
                }
            }
        }

        $processSteps = $this->normaliseProcessSteps($processSteps, $orphanProcessTitles);

        return [
            'process_steps' => $processSteps,
            'ingredients' => $ingredients,
            'totals' => $totals,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $steps
     * @param array<int,string> $orphanProcessTitles
     * @return array<int,array<string,mixed>>
     */
    private function normaliseProcessSteps(array $steps, array $orphanProcessTitles): array
    {
        $byStepNumber = [];

        foreach ($steps as $step) {
            $stepNo = (int) ($step['step_no'] ?? 0);
            if ($stepNo <= 0) {
                continue;
            }

            $title = trim((string) ($step['title'] ?? ''));
            if (! array_key_exists($stepNo, $byStepNumber)) {
                $byStepNumber[$stepNo] = [
                    'step_no' => $stepNo,
                    'title' => $title,
                ];

                continue;
            }

            // Keep first title for duplicate step numbers, unless first one was blank.
            if ($byStepNumber[$stepNo]['title'] === '' && $title !== '') {
                $byStepNumber[$stepNo]['title'] = $title;
            }
        }

        $usedTitles = [];
        foreach ($byStepNumber as $step) {
            $normalized = strtolower(trim((string) ($step['title'] ?? '')));
            if ($normalized !== '') {
                $usedTitles[$normalized] = true;
            }
        }

        foreach ($byStepNumber as $stepNo => $step) {
            if (trim((string) ($step['title'] ?? '')) !== '') {
                continue;
            }

            foreach ($orphanProcessTitles as $candidate) {
                $normalized = strtolower(trim($candidate));
                if ($normalized === '' || isset($usedTitles[$normalized])) {
                    continue;
                }

                $byStepNumber[$stepNo]['title'] = trim($candidate);
                $usedTitles[$normalized] = true;
                break;
            }
        }

        ksort($byStepNumber);

        return array_values($byStepNumber);
    }

    /** @return array<string,mixed>|null */
    private function parseIngredientLine(string $line, ?int $currentStep): ?array
    {
        if (preg_match('/^(?:(?<allergen>[A-Z][A-Z0-9\s&\/-]{1,30})\s+)?(?<material>\d{6,10})\s*(?<description>.+?)\s+(?<percentage>[0-9]+(?:\.[0-9]+)?)\s+(?<quantity>[0-9]+(?:\.[0-9]+)?)(?:\s*(?<uom>[A-Z]{1,6}))?$/', $line, $matches) !== 1) {
            return null;
        }

        return [
            'step_no' => $currentStep,
            'allergen_material' => trim((string) ($matches['allergen'] ?? '')) ?: null,
            'material_code' => trim((string) ($matches['material'] ?? '')),
            'description' => trim((string) ($matches['description'] ?? '')),
            'percentage' => (float) ($matches['percentage'] ?? 0),
            'quantity' => (float) ($matches['quantity'] ?? 0),
            'uom' => trim((string) ($matches['uom'] ?? '')) ?: null,
        ];
    }

    /** @param array<int,string> $patterns */
    private function matchText(string $text, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches) === 1) {
                $value = trim((string) ($matches[1] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function normaliseDate(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $value = str_replace('.', '/', trim($raw));
        $formats = ['d/m/Y', 'd-m-Y', 'd/m/y', 'd-m-y', 'Y-m-d'];

        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date instanceof \DateTimeImmutable) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    /** @param array<int,string> $labels */
    private function extractLabelValueFromLines(array $lines, array $labels, bool $requireDigit = false): ?string
    {
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = trim((string) $lines[$i]);
            if ($line === '') {
                continue;
            }

            foreach ($labels as $label) {
                $quoted = preg_quote($label, '/');
                if (preg_match('/^'.$quoted.'\s*:?\s*(.*)$/i', $line, $matches) !== 1) {
                    continue;
                }

                $value = trim((string) ($matches[1] ?? ''));
                if ($value === '' && isset($lines[$i + 1])) {
                    $value = trim((string) $lines[$i + 1]);
                }

                if ($value === '') {
                    continue;
                }

                // Prevent swallowing guidance text such as "recipe code and date fields".
                $value = trim((string) preg_replace('/\s+and\s+date\s+fields.*$/i', '', $value));

                if ($requireDigit && preg_match('/\d/', $value) !== 1) {
                    continue;
                }

                return $value;
            }
        }

        return null;
    }
}
