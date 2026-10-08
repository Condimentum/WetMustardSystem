<?php

namespace App\Domains\Reporting\Support;

use App\Models\DocumentLayoutSetting;
use App\Models\DocumentReference;
use Illuminate\Support\Facades\Schema;

class DocumentSetup
{
    public function __construct(private readonly DocumentSources $sources)
    {
    }

    /**
     * @param  string|null  $sourceKey  DocumentSources key; inferred from the code prefix when omitted.
     * @return array<string, mixed>
     */
    public function defaultsForCode(?string $documentCode, ?string $sourceKey = null): array
    {
        $code = strtoupper(trim((string) $documentCode));
        $sourceKey ??= $this->sources->programKeyForCode($code);

        $defaults = [
            'orientation' => $this->defaultOrientation($code),
            'paper_size' => 'A4',
            'margin_top' => 20,
            'margin_right' => 20,
            'margin_bottom' => 20,
            'margin_left' => 20,
            'content_padding' => 10,
            'base_font_size' => 11,
            'table_header_font_size' => 10,
            'line_height' => 1.25,
            'show_logo' => true,
            'show_ccp_block' => true,
            'ccp_message' => $this->defaultCcpMessageForCode($code) ?: implode("\n", $this->sources->defaultInstructions($sourceKey)),
            'show_qa_signoff' => true,
            'show_issue_history' => true,
            'columns' => $this->sources->defaultColumns($sourceKey),
            // Batch card only: steps, process settings rows, footnote and the width taken by the batch columns.
            'show_steps' => true,
            'show_process_settings' => true,
            'footnote' => '',
            'batch_area_percent' => 54,
        ];

        if ($sourceKey === DocumentSources::RECIPE_BATCH_CARD) {
            $defaults = array_merge($defaults, [
                'orientation' => 'landscape',
                'margin_top' => 30,
                'margin_right' => 30,
                'margin_bottom' => 30,
                'margin_left' => 30,
                'base_font_size' => 8,
                'table_header_font_size' => 8,
                'show_logo' => false,
                'ccp_message' => "These records have been identified under HACCP as CCP's and must be completed correctly",
                'footnote' => '*In the event of any change to the lot number of an ingredient, a new sheet must be initiated to ensure accurate tracking and documentation.*',
            ]);
        }

        return $defaults;
    }

    /**
     * @param  string|null  $sourceKey  DocumentSources key; resolved from the document's link when omitted.
     * @return array<string, mixed>
     */
    public function resolveForDocument(?DocumentReference $document, ?string $fallbackCode = null, ?string $sourceKey = null): array
    {
        $documentCode = $document?->code ?? $fallbackCode;
        $sourceKey ??= $this->sources->sourceKeyFor($document, $fallbackCode);
        $defaults = $this->defaultsForCode($documentCode, $sourceKey);

        $stored = [];
        if ($document !== null) {
            $stored = $this->extractStoredSettings($document);
        }

        return $this->normalize($documentCode, array_merge($defaults, $stored), $sourceKey);
    }

    /** @param array<string, mixed> $settings */
    /** @return array<string, mixed> */
    public function normalize(?string $documentCode, array $settings, ?string $sourceKey = null): array
    {
        $defaults = $this->defaultsForCode($documentCode, $sourceKey);

        return [
            'orientation' => in_array(($settings['orientation'] ?? null), ['portrait', 'landscape'], true)
                ? $settings['orientation']
                : $defaults['orientation'],
            'paper_size' => strtoupper(trim((string) ($settings['paper_size'] ?? $defaults['paper_size']))) ?: 'A4',
            'margin_top' => $this->number($settings['margin_top'] ?? null, 0, 100, (float) $defaults['margin_top']),
            'margin_right' => $this->number($settings['margin_right'] ?? null, 0, 100, (float) $defaults['margin_right']),
            'margin_bottom' => $this->number($settings['margin_bottom'] ?? null, 0, 150, (float) $defaults['margin_bottom']),
            'margin_left' => $this->number($settings['margin_left'] ?? null, 0, 100, (float) $defaults['margin_left']),
            'content_padding' => $this->number($settings['content_padding'] ?? null, 0, 40, (float) $defaults['content_padding']),
            'base_font_size' => $this->number($settings['base_font_size'] ?? null, 7, 20, (float) $defaults['base_font_size']),
            'table_header_font_size' => $this->number($settings['table_header_font_size'] ?? null, 7, 20, (float) $defaults['table_header_font_size']),
            'line_height' => $this->number($settings['line_height'] ?? null, 1, 2, (float) $defaults['line_height']),
            'show_logo' => (bool) ($settings['show_logo'] ?? $defaults['show_logo']),
            'show_ccp_block' => (bool) ($settings['show_ccp_block'] ?? $defaults['show_ccp_block']),
            // Blank falls back to the default text; untick show_ccp_block to hide the block instead.
            'ccp_message' => trim((string) ($settings['ccp_message'] ?? '')) ?: trim((string) ($defaults['ccp_message'] ?? '')),
            'show_qa_signoff' => (bool) ($settings['show_qa_signoff'] ?? $defaults['show_qa_signoff']),
            'show_issue_history' => (bool) ($settings['show_issue_history'] ?? $defaults['show_issue_history']),
            'show_steps' => (bool) ($settings['show_steps'] ?? $defaults['show_steps']),
            'show_process_settings' => (bool) ($settings['show_process_settings'] ?? $defaults['show_process_settings']),
            'footnote' => trim((string) ($settings['footnote'] ?? $defaults['footnote'])),
            'batch_area_percent' => $this->number($settings['batch_area_percent'] ?? null, 20, 80, (float) $defaults['batch_area_percent']),
            'columns' => $this->normalizeColumns(
                is_array($settings['columns'] ?? null) ? $settings['columns'] : [],
                is_array($defaults['columns']) ? $defaults['columns'] : []
            ),
        ];
    }

    /** @param array<int, array<string, mixed>> $columns */
    /** @param array<int, array<string, mixed>> $defaults */
    /** @return array<int, array<string, mixed>> */
    private function normalizeColumns(array $columns, array $defaults): array
    {
        if ($columns === []) {
            $columns = $defaults;
        }

        $normalized = [];
        foreach ($columns as $index => $column) {
            if (! is_array($column)) {
                continue;
            }

            $default = is_array($defaults[$index] ?? null) ? $defaults[$index] : [];
            $key = trim((string) ($column['key'] ?? $default['key'] ?? 'col_'.$index));
            if ($key === '') {
                $key = 'col_'.$index;
            }

            $normalized[] = [
                'key' => $key,
                'label' => trim((string) ($column['label'] ?? $default['label'] ?? strtoupper(str_replace('_', ' ', $key)))) ?: strtoupper(str_replace('_', ' ', $key)),
                'width' => $this->number($column['width'] ?? null, 1, 100, (float) ($default['width'] ?? 8)),
                'visible' => array_key_exists('visible', $column)
                    ? (bool) $column['visible']
                    : (bool) ($default['visible'] ?? true),
            ];
        }

        return $normalized;
    }

    private function number(mixed $value, float $min, float $max, float $default): float
    {
        if (! is_numeric($value)) {
            return $default;
        }

        $numeric = (float) $value;
        if ($numeric < $min) {
            return $min;
        }

        if ($numeric > $max) {
            return $max;
        }

        return round($numeric, 2);
    }

    private function defaultOrientation(string $code): string
    {
        return in_array($code, ['WM003', 'WM005'], true) ? 'landscape' : 'portrait';
    }

    private function defaultCcpMessageForCode(string $code): string
    {
        if (str_starts_with($code, 'WM005')) {
            return "These records have been identified under HACCP as Critical Control Points ( CCPs ) and must be completed correctly\n"
                ."* Refer to QALAB35 for analytical specification for product being produced and record analytical specification in the relevant column below, put N/A if test is not required\n"
                ."Tested by: must be in full name and not initials. All out of specification tests must be repeated and the results recorded\n"
                ."Out of specification inspection results to be reported to QA\n"
                ."TEST EACH BATCH PRODUCED **";
        }

        if (str_starts_with($code, 'WM003')) {
            return 'REQUIRED TRACEABILITY RECORDS. START A NEW SHEET FOR EACH WEEK';
        }

        return '';
    }

    /** @return array<string, mixed> */
    private function extractStoredSettings(DocumentReference $document): array
    {
        if (! $this->hasDocumentLayoutSettingsTable()) {
            return [];
        }

        if ($document->relationLoaded('layoutSetting')) {
            $setting = $document->layoutSetting;

            return is_array($setting?->settings) ? $setting->settings : [];
        }

        $setting = DocumentLayoutSetting::query()
            ->where('document_reference_id', $document->id)
            ->first();

        return is_array($setting?->settings) ? $setting->settings : [];
    }

    private function hasDocumentLayoutSettingsTable(): bool
    {
        try {
            return Schema::hasTable('document_layout_settings');
        } catch (\Throwable) {
            return false;
        }
    }
}
