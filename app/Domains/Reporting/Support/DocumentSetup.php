<?php

namespace App\Domains\Reporting\Support;

use App\Models\DocumentLayoutSetting;
use App\Models\DocumentReference;
use Illuminate\Support\Facades\Schema;

class DocumentSetup
{
    /** @return array<string, mixed> */
    public function defaultsForCode(?string $documentCode): array
    {
        $code = strtoupper(trim((string) $documentCode));

        return [
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
            'ccp_message' => $this->defaultCcpMessageForCode($code),
            'show_qa_signoff' => true,
            'show_issue_history' => true,
            'columns' => $this->defaultColumnsForCode($code),
        ];
    }

    /** @return array<string, mixed> */
    public function resolveForDocument(?DocumentReference $document, ?string $fallbackCode = null): array
    {
        $documentCode = $document?->code ?? $fallbackCode;
        $defaults = $this->defaultsForCode($documentCode);

        $stored = [];
        if ($document !== null) {
            $stored = $this->extractStoredSettings($document);
        }

        return $this->normalize($documentCode, array_merge($defaults, $stored));
    }

    /** @param array<string, mixed> $settings */
    /** @return array<string, mixed> */
    public function normalize(?string $documentCode, array $settings): array
    {
        $defaults = $this->defaultsForCode($documentCode);

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
            'ccp_message' => trim((string) ($settings['ccp_message'] ?? $defaults['ccp_message'] ?? '')),
            'show_qa_signoff' => (bool) ($settings['show_qa_signoff'] ?? $defaults['show_qa_signoff']),
            'show_issue_history' => (bool) ($settings['show_issue_history'] ?? $defaults['show_issue_history']),
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

    /** @return array<int, array<string, mixed>> */
    private function defaultColumnsForCode(string $code): array
    {
        if (str_starts_with($code, 'WM004')) {
            return [
                ['key' => 'mo_number', 'label' => 'MO Number', 'visible' => true, 'width' => 8],
                ['key' => 'ticket_number', 'label' => 'Ticket Number', 'visible' => true, 'width' => 9],
                ['key' => 'serial_number', 'label' => 'Serial Number', 'visible' => true, 'width' => 10],
                ['key' => 'top_seal_number', 'label' => 'Top Seal Number', 'visible' => true, 'width' => 8],
                ['key' => 'bottom_seal_number', 'label' => 'Bottom Seal Number', 'visible' => true, 'width' => 8],
                ['key' => 'liner_number', 'label' => 'Liner Number', 'visible' => true, 'width' => 7],
                ['key' => 'liner_batch_code', 'label' => 'Liner Batch Code', 'visible' => true, 'width' => 8],
                ['key' => 'fill_weight', 'label' => 'Fill Weight', 'visible' => true, 'width' => 7],
                ['key' => 'start_time', 'label' => 'Start Time', 'visible' => true, 'width' => 8],
                ['key' => 'finish_time', 'label' => 'Finish Time', 'visible' => true, 'width' => 8],
                ['key' => 'checked_by', 'label' => 'Checked By', 'visible' => true, 'width' => 9],
                ['key' => 'checked_at', 'label' => 'Checked At', 'visible' => true, 'width' => 8],
            ];
        }

        if (str_starts_with($code, 'WM003')) {
            return [
                ['key' => 'date_used', 'label' => 'Date Used', 'visible' => true, 'width' => 16],
                ['key' => 'supplier_production_date', 'label' => 'Supplier Production Date', 'visible' => true, 'width' => 18],
                ['key' => 'best_before_date', 'label' => 'Best Before Date', 'visible' => true, 'width' => 16],
                ['key' => 'batch_no', 'label' => 'Batch No.', 'visible' => true, 'width' => 16],
                ['key' => 'time_on', 'label' => 'Time On', 'visible' => true, 'width' => 12],
                ['key' => 'operator_name', 'label' => 'Operator Name', 'visible' => true, 'width' => 22],
            ];
        }

        if (str_starts_with($code, 'WM005')) {
            return [
                ['key' => 'tested_date', 'label' => 'Date', 'visible' => true, 'width' => 6],
                ['key' => 'tested_time', 'label' => 'Time', 'visible' => true, 'width' => 4],
                ['key' => 'mo_number', 'label' => 'MO Number', 'visible' => true, 'width' => 8],
                ['key' => 'batch_number', 'label' => 'Batch Number', 'visible' => true, 'width' => 8],
                ['key' => 'ph', 'label' => 'pH', 'visible' => true, 'width' => 10],
                ['key' => 'acidity_acetic', 'label' => 'Acidity (as acetic)', 'visible' => true, 'width' => 10],
                ['key' => 'acidity_citric', 'label' => 'Acidity (as citric)', 'visible' => true, 'width' => 5],
                ['key' => 'salt', 'label' => 'Salt', 'visible' => true, 'width' => 12],
                ['key' => 'viscosity_brookfield', 'label' => 'Viscosity (Brookfield)', 'visible' => true, 'width' => 11],
                ['key' => 'viscosity_bostwick', 'label' => 'Viscosity (Bostwick)', 'visible' => true, 'width' => 5],
                ['key' => 'aw', 'label' => 'aW', 'visible' => true, 'width' => 5],
                ['key' => 'solids', 'label' => 'Solids', 'visible' => true, 'width' => 10],
                ['key' => 'appearance', 'label' => 'Appearance', 'visible' => true, 'width' => 10],
                ['key' => 'tested_by', 'label' => 'Test by (Print name)', 'visible' => true, 'width' => 11],
            ];
        }

        if (str_starts_with($code, 'WM010')) {
            return [
                ['key' => 'section', 'label' => 'Section', 'visible' => true, 'width' => 18],
                ['key' => 'equipment', 'label' => 'Equipment', 'visible' => true, 'width' => 18],
                ['key' => 'reading', 'label' => 'Reading', 'visible' => true, 'width' => 12],
                ['key' => 'target', 'label' => 'Target', 'visible' => true, 'width' => 10],
                ['key' => 'result', 'label' => 'Result', 'visible' => true, 'width' => 10],
                ['key' => 'pass_or_fail', 'label' => 'Pass / Fail', 'visible' => true, 'width' => 10],
                ['key' => 'action_taken_if_failed', 'label' => 'Action if Failed', 'visible' => true, 'width' => 12],
                ['key' => 'operator_name', 'label' => 'Operator Name', 'visible' => true, 'width' => 10],
            ];
        }

        return [];
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
