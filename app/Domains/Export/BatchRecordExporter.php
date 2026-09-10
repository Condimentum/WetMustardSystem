<?php

namespace App\Domains\Export;

use App\Models\BatchRecord;
use App\Models\ElectronicSignature;
use Illuminate\Support\Collection;

/**
 * Builds a self-contained, audit-ready HTML export of a single batch record
 * (scope §14.2 Batch Record Export): header, ingredients with sign-offs, process
 * steps, parameters, metal detector checks, pallecons, packing, drum, packaging,
 * electronic signatures and the batch audit trail.
 */
class BatchRecordExporter
{
    public function export(BatchRecord $batch): string
    {
        $batch->loadMissing([
            'manufacturingOrder', 'product', 'variant',
            'ingredientLots.weighedBy', 'ingredientLots.tippedBy',
            'processSteps.completedBy', 'processParameters',
            'metalDetectorChecks.signedBy', 'pallecons.checkedBy',
            'palleconContainers.fills.batchRecord',
            'packingRuns.weightChecks', 'packingRuns.pallets',
            'drumProcessingRuns.pallets.drumRecords',
            'packagingLots',
        ]);

        $sections = implode('', [
            $this->headerSection($batch),
            $this->ingredientsSection($batch),
            $this->processSection($batch),
            $this->parametersSection($batch),
            $this->metalDetectorSection($batch),
            $this->palleconsSection($batch),
            $this->packingSection($batch),
            $this->drumSection($batch),
            $this->packagingSection($batch),
            $this->signaturesSection($batch),
        ]);

        return $this->document("Batch Record {$batch->batch_number}", $sections);
    }

    private function document(string $title, string $body): string
    {
        $generated = now()->toDayDateTimeString();

        return <<<HTML
            <!DOCTYPE html>
            <html><head><meta charset="utf-8"><title>{$title}</title></head>
            <body style="font-family:Arial,Helvetica,sans-serif;color:#111;max-width:900px;margin:20px auto;">
                <h1 style="font-size:20px;border-bottom:2px solid #333;padding-bottom:8px;">{$title}</h1>
                <p style="color:#666;font-size:12px;">DBMTS audit-ready export · generated {$generated}</p>
                {$body}
            </body></html>
            HTML;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|int|float|null>>  $rows
     */
    private function table(string $heading, array $headers, array $rows): string
    {
        $th = collect($headers)->map(fn ($h) => '<th style="text-align:left;padding:6px;border-bottom:2px solid #ccc;font-size:12px;">'.e($h).'</th>')->implode('');
        $body = $rows === []
            ? '<tr><td colspan="'.count($headers).'" style="padding:10px;color:#999;">None</td></tr>'
            : collect($rows)->map(fn (array $r) => '<tr>'.collect($r)->map(fn ($c) => '<td style="padding:6px;border-bottom:1px solid #eee;font-size:12px;">'.e((string) $c).'</td>')->implode('').'</tr>')->implode('');

        return "<h2 style=\"font-size:15px;margin-top:24px;\">{$heading}</h2><table style=\"border-collapse:collapse;width:100%;\"><thead><tr>{$th}</tr></thead><tbody>{$body}</tbody></table>";
    }

    private function headerSection(BatchRecord $batch): string
    {
        return $this->table('Header', ['Field', 'Value'], [
            ['Batch number', $batch->batch_number],
            ['Status', ucfirst(str_replace('_', ' ', $batch->status))],
            ['Product', $batch->product?->product_name ?? '—'],
            ['Recipe / variant', ($batch->manufacturingOrder?->recipe_code ?? '—').' / '.($batch->variant?->variant_name ?? '—')],
            ['MO reference', $batch->manufacturingOrder?->mo_number ?? '—'],
            ['Planned quantity', $batch->planned_quantity ?? '—'],
            ['Production date', $batch->production_date?->toDateString() ?? '—'],
            ['Completed at', $batch->completed_at?->toDateTimeString() ?? '—'],
        ]);
    }

    private function ingredientsSection(BatchRecord $batch): string
    {
        $rows = $batch->ingredientLots->map(fn ($lot) => [
            $lot->material_description ?? $lot->material_code,
            $lot->lot_number,
            $lot->actual_quantity.' '.$lot->uom,
            $lot->weighedBy?->name ? $lot->weighedBy->name.' @ '.$lot->weighed_at?->toDateTimeString() : '—',
            $lot->tippedBy?->name ? $lot->tippedBy->name.' @ '.$lot->tipped_at?->toDateTimeString() : '—',
        ])->all();

        return $this->table('Ingredient Lots', ['Material', 'Lot', 'Actual', 'Weighed', 'Tipped'], $rows);
    }

    private function processSection(BatchRecord $batch): string
    {
        $rows = $batch->processSteps->map(fn ($s) => [
            $s->step_name,
            $s->required_flag ? 'Yes' : 'No',
            $s->completedBy?->name ? $s->completedBy->name.' @ '.$s->completed_at?->toDateTimeString() : 'Pending',
        ])->all();

        return $this->table('Process Steps', ['Step', 'Required', 'Completed'], $rows);
    }

    private function parametersSection(BatchRecord $batch): string
    {
        $rows = $batch->processParameters->map(fn ($p) => [$p->parameter_name, $p->value.' '.$p->uom, $p->entered_at?->toDateTimeString()])->all();

        return $this->table('Process Parameters', ['Parameter', 'Value', 'Recorded'], $rows);
    }

    private function metalDetectorSection(BatchRecord $batch): string
    {
        $rows = $batch->metalDetectorChecks->map(fn ($c) => [
            $c->check_time?->toDateTimeString(), str_replace('_', ' ', $c->check_type),
            ($c->fe10_pass ? 'P' : 'F').' / '.($c->non_fe15_pass ? 'P' : 'F').' / '.($c->ss20_pass ? 'P' : 'F'),
            strtoupper($c->overall_result), $c->signedBy?->name,
        ])->all();

        return $this->table('Metal Detector Checks', ['Time', 'Type', 'Fe/NonFe/SS', 'Result', 'Signed by'], $rows);
    }

    private function palleconsSection(BatchRecord $batch): string
    {
        $rows = $batch->palleconContainers->map(function ($container) use ($batch) {
            $thisBatchFill = $container->fills->firstWhere('batch_record_id', $batch->id)?->fill_weight;
            $otherBatches = $container->fills
                ->map(fn ($fill) => $fill->batchRecord?->batch_number)
                ->filter()
                ->reject(fn ($number) => $number === $batch->batch_number)
                ->unique()
                ->implode(', ');

            return [
                $container->serial_number,
                $container->status,
                $thisBatchFill,
                $container->final_weight,
                $otherBatches !== '' ? $otherBatches : '—',
                trim(($container->top_seal_number ?? '').' / '.($container->bottom_seal_number ?? ''), ' /'),
            ];
        })->all();

        return $this->table(
            'Pallecons',
            ['Serial', 'Status', 'This batch fill', 'Container final weight', 'Also contains batches', 'Seals (top / bottom)'],
            $rows,
        );
    }

    private function packingSection(BatchRecord $batch): string
    {
        $rows = [];
        foreach ($batch->packingRuns as $run) {
            foreach ($run->weightChecks as $wc) {
                $rows[] = ['Weight check', $wc->check_time?->toDateTimeString(), 'avg '.$wc->average_weight, strtoupper($wc->result)];
            }
            foreach ($run->pallets as $pallet) {
                $rows[] = ['Pallet', $pallet->pallet_number, 'ticket '.$pallet->ticket_number, 'qty '.$pallet->pallet_amount];
            }
        }

        return $this->table('Bucket Packing', ['Type', 'Ref', 'Detail', 'Value'], $rows);
    }

    private function drumSection(BatchRecord $batch): string
    {
        $rows = [];
        foreach ($batch->drumProcessingRuns as $run) {
            foreach ($run->pallets as $pallet) {
                foreach ($pallet->drumRecords as $drum) {
                    $rows[] = [$pallet->pallet_ticket_number, $drum->drum_number, $drum->filler_weight, $drum->bag_seal_number, $drum->drum_seal_number];
                }
            }
        }

        return $this->table('Drum Processing', ['Pallet ticket', 'Drum', 'Fill weight', 'Bag seal', 'Drum seal'], $rows);
    }

    private function packagingSection(BatchRecord $batch): string
    {
        $rows = $batch->packagingLots->map(fn ($l) => [
            $l->packaging_type, $l->supplier, $l->supplier_reference_type,
            $l->supplier_reference_number ?? $l->lot_or_job_number, $l->operator_name,
        ])->all();

        return $this->table('Packaging Lots', ['Type', 'Supplier', 'Ref type', 'Reference', 'Operator'], $rows);
    }

    private function signaturesSection(BatchRecord $batch): string
    {
        $rows = $this->signaturesFor($batch)->map(fn (ElectronicSignature $s) => [
            $s->signature_purpose, $s->entity_name, $s->user?->name, $s->meaning, $s->signed_at?->toDateTimeString(),
        ])->all();

        return $this->table('Electronic Signatures', ['Purpose', 'Entity', 'User', 'Meaning', 'Signed at'], $rows);
    }

    /**
     * @return Collection<int, ElectronicSignature>
     */
    private function signaturesFor(BatchRecord $batch): Collection
    {
        $map = [
            'batch_records' => [$batch->id],
            'batch_ingredient_lots' => $batch->ingredientLots->pluck('id')->all(),
            'batch_process_steps' => $batch->processSteps->pluck('id')->all(),
            'metal_detector_checks' => $batch->metalDetectorChecks->pluck('id')->all(),
            'pallecon_records' => $batch->pallecons->pluck('id')->all(),
            'pallecons' => $batch->palleconContainers->pluck('id')->all(),
            'pallecon_fills' => $batch->palleconContainers->flatMap->fills->pluck('id')->all(),
        ];

        return ElectronicSignature::query()
            ->with('user')
            ->where(function ($query) use ($map): void {
                foreach ($map as $entity => $ids) {
                    if ($ids !== []) {
                        $query->orWhere(fn ($q) => $q->where('entity_name', $entity)->whereIn('entity_id', $ids));
                    }
                }
            })
            ->orderBy('signed_at')
            ->get();
    }
}
