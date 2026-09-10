<?php

namespace App\Domains\Pallecon\Support;

use App\Features\Booking\BookFinishedGoodsFeature;
use App\Features\Pallecon\PrintPalleconLabelFeature;
use App\Models\BatchRecord;
use App\Models\ManufacturingOrder;
use App\Models\Pallecon;
use App\Models\PalleconFill;
use App\Models\PalleconRecord;
use App\Models\PaperworkRow;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Shared helpers for filling pallecons from an MO's batches: the fillable-batch
 * list (with per-batch remaining as a hard cap) and the per-fill WinMan booking
 * (unchanged wire protocol). Used by the MO Workspace and the Pallecon detail
 * page so both behave identically.
 */
class PalleconFilling
{
    /**
     * This MO's batches as fill sources, each with planned / filled / remaining kg.
     *
     * @return array<int, array{id:int, batch_number:string, status:string, signoff_complete:bool, planned_kg:float, filled_kg:float, remaining_kg:float}>
     */
    public function fillableBatches(ManufacturingOrder $order): array
    {
        $batches = BatchRecord::query()
            ->where('manufacturing_order_id', $order->id)
            ->whereNotIn('status', [BatchRecord::STATUS_CANCELLED])
            ->orderBy('id')
            ->get();

        // Sign-off is a batch-level confirmation (powders/liquids/tipping names
        // recorded once per batch), not per-lot signatures.
        $confirmationCounts = PaperworkRow::query()
            ->whereIn('batch_record_id', $batches->pluck('id'))
            ->whereIn('row_key', [
                'ingredients_signoff.powders_weighed_by',
                'ingredients_signoff.liquids_weighed_by',
                'ingredients_signoff.tipping_batch_by',
            ])
            ->whereNotNull('value_text')
            ->where('value_text', '<>', '')
            ->selectRaw('batch_record_id, count(*) as confirmations')
            ->groupBy('batch_record_id')
            ->pluck('confirmations', 'batch_record_id');

        $filledByBatch = PalleconFill::query()
            ->whereIn('batch_record_id', $batches->pluck('id'))
            ->selectRaw('batch_record_id, COALESCE(SUM(fill_weight), 0) as filled')
            ->groupBy('batch_record_id')
            ->pluck('filled', 'batch_record_id');

        return $batches
            ->map(function (BatchRecord $batch) use ($confirmationCounts, $filledByBatch): array {
                $planned = (float) ($batch->planned_quantity ?? 0);
                $filled = (float) ($filledByBatch[$batch->id] ?? 0);

                return [
                    'id' => $batch->id,
                    'batch_number' => (string) $batch->batch_number,
                    'status' => (string) $batch->status,
                    'signoff_complete' => (int) ($confirmationCounts[$batch->id] ?? 0) >= 3,
                    'planned_kg' => $planned,
                    'filled_kg' => $filled,
                    'remaining_kg' => max($planned - $filled, 0.0),
                ];
            })
            ->all();
    }

    /**
     * Guards a fill of $weight kg from $batchMeta (a fillableBatches() row).
     * Returns an error string, or null when the fill is allowed.
     *
     * @param  array<string, mixed>|null  $batchMeta
     */
    public function guardFill(?array $batchMeta, float $weight): ?string
    {
        if ($batchMeta === null) {
            return 'Select a batch from this manufacturing order first.';
        }

        if (! ($batchMeta['signoff_complete'] ?? false)) {
            return 'Batch '.$batchMeta['batch_number'].' has not completed Ingredients Sign Off yet.';
        }

        // The batch planned quantity is a hard cap - total fills from a batch can
        // never exceed it. Only enforced when a planned quantity is on record.
        $remaining = (float) ($batchMeta['remaining_kg'] ?? 0);
        if ((float) ($batchMeta['planned_kg'] ?? 0) > 0 && $weight > $remaining + 0.0001) {
            return sprintf(
                'Only %s kg remaining on batch %s - the fill weight cannot exceed it.',
                rtrim(rtrim(number_format($remaining, 3, '.', ''), '0'), '.') ?: '0',
                $batchMeta['batch_number'],
            );
        }

        return null;
    }

    /**
     * Books a single fill's weight to the source batch's MO in WinMan.
     *
     * @return array{preview: array<string, mixed>|null, messages: array<int, string>, error: bool}
     */
    public function bookFill(BatchRecord $batch, Pallecon $container, PalleconFill $fill, string $productionDate, ?User $user): array
    {
        if (! (bool) config('winman.booking.enabled', false)) {
            return ['preview' => null, 'messages' => [], 'error' => false];
        }

        try {
            $bookedQuantity = (float) ($fill->fill_weight ?? 0);

            if ($bookedQuantity <= 0) {
                return ['preview' => null, 'messages' => [], 'error' => false];
            }

            $palleconLike = new PalleconRecord([
                'mo_number' => $batch->manufacturingOrder?->mo_number,
                'ticket_number' => $container->serial_number,
                'serial_number' => $container->serial_number,
                'fill_weight' => $bookedQuantity,
            ]);
            $palleconLike->setRelation('batchRecord', $batch);
            $palleconLike->id = $container->id;

            $finished = now();
            $expiry = $this->resolveBookingExpiryDate($batch, $palleconLike, $finished, $productionDate);
            $lotNumber = $this->resolveWinManLotNumber($batch, $palleconLike, $productionDate);

            $preview = [
                'manufacturing_order_id' => (string) ($batch->manufacturingOrder?->winman_manufacturing_order_id ?? $palleconLike->mo_number ?? ''),
                'manufacturing_order_internal' => (int) ($batch->manufacturingOrder?->winman_manufacturing_order ?? 0),
                'product_id' => (string) ($batch->manufacturingOrder?->winman_product_id ?? ''),
                'quantity_kg' => $bookedQuantity,
                'lot_number' => $lotNumber,
                'finished_date' => $finished->format('Y-m-d H:i:s'),
                'expiry_date' => $expiry->format('Y-m-d H:i:s'),
                'pallecon_number' => (string) ($container->serial_number ?? ''),
            ];

            $log = app(BookFinishedGoodsFeature::class)(
                $batch,
                $bookedQuantity,
                $lotNumber,
                [$lotNumber],
                $finished,
                $expiry,
                $user,
                true,
            );

            $preview += [
                'booking_status' => (string) $log->booking_status,
                'winman_inventory_id' => $log->winman_inventory_id !== null ? (string) $log->winman_inventory_id : null,
                'error_message' => $log->error_message,
                'booked_at' => $log->booking_date?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s'),
                'booked_quantity_kg_logged' => $log->quantity_booked_kg !== null ? (string) $log->quantity_booked_kg : null,
                'booked_quantity_traded_units' => $log->quantity_booked_traded_units !== null ? (string) $log->quantity_booked_traded_units : null,
                'logged_lot_number' => $log->lot_number,
            ];

            if ($log->booking_status === 'success') {
                return [
                    'preview' => $preview,
                    'messages' => ['WinMan inventory created (Inventory '.($log->winman_inventory_id ?? '—').').'],
                    'error' => false,
                ];
            }

            return [
                'preview' => $preview,
                'messages' => ['WinMan booking '.$log->booking_status.': '.($log->error_message ?: 'unknown error').'.'],
                'error' => true,
            ];
        } catch (\Throwable $e) {
            return [
                'preview' => ['booking_status' => 'failed', 'error_message' => $e->getMessage()],
                'messages' => ['Fill saved, but WinMan booking failed: '.$e->getMessage()],
                'error' => true,
            ];
        }
    }

    /**
     * The pallecon Reference stamped on seal: "{MO WinMan id} {pallecon number}
     * {yjjj}00M96". Blank before the pallecon is sealed and submitted to WinMan.
     */
    public function sealReference(Pallecon $pallecon, ?ManufacturingOrder $order, string $productionDate): string
    {
        $moId = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) (
            $order?->winman_manufacturing_order_id ?? $order?->mo_number ?? $pallecon->mo_number ?? 'MO'
        ))) ?: 'MO';

        $palleconNumber = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($pallecon->serial_number ?? '')));
        if ($palleconNumber === '') {
            $palleconNumber = 'P'.$pallecon->id;
        }

        return substr(trim($moId.' '.$palleconNumber.' '.$this->resolveLabelStyleLotNumber($productionDate)), 0, 100);
    }

    private function resolveWinManLotNumber(BatchRecord $batch, PalleconRecord $pallecon, string $productionDate): string
    {
        $moId = trim((string) ($batch->manufacturingOrder?->winman_manufacturing_order_id
            ?? $pallecon->mo_number
            ?? 'MO'));
        $palleconNumber = trim((string) ($pallecon->ticket_number ?? ''));
        $labelStyleLot = $this->resolveLabelStyleLotNumber($productionDate);

        $moId = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $moId));
        $palleconNumber = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $palleconNumber));

        if ($moId === '') {
            $moId = 'MO';
        }

        if ($palleconNumber === '') {
            $palleconNumber = 'P'.$pallecon->id;
        }

        return substr(trim($moId.' '.$palleconNumber.' '.$labelStyleLot), 0, 100);
    }

    private function resolveLabelStyleLotNumber(string $productionDate): string
    {
        $date = $productionDate !== '' ? Carbon::parse($productionDate) : now();
        $yjjj = substr($date->format('y'), -1).str_pad((string) $date->dayOfYear, 3, '0', STR_PAD_LEFT);

        return $yjjj.'00M96';
    }

    private function resolveBookingExpiryDate(BatchRecord $batch, PalleconRecord $pallecon, Carbon $fallbackBase, string $productionDate): Carbon
    {
        $date = $productionDate !== '' ? Carbon::parse($productionDate) : now();

        $previewPallecon = new PalleconRecord([
            'mo_number' => $batch->manufacturingOrder?->mo_number,
            'fill_weight' => (float) ($pallecon->fill_weight ?? 0),
        ]);
        $previewPallecon->setRelation('batchRecord', $batch);

        try {
            $payload = app(PrintPalleconLabelFeature::class)->buildPrintPayload($previewPallecon, 1, [
                'production_date' => $date->toDateString(),
            ]);

            $sources = is_array($payload['options']['named_data_sources'] ?? null)
                ? $payload['options']['named_data_sources']
                : [];

            $bbeFormat = strtoupper(trim((string) ($sources['BBEformat'] ?? '')));
            $bbeValue = isset($sources['BBE']) && is_numeric((string) $sources['BBE'])
                ? max(1, (int) $sources['BBE'])
                : null;

            if ($bbeValue !== null && $bbeFormat === 'DDMMYYYY') {
                return $date->copy()->addDays($bbeValue)->endOfDay();
            }

            if ($bbeValue !== null && $bbeFormat === 'MMYYYY') {
                return $date->copy()->addMonthsNoOverflow($bbeValue)->endOfMonth();
            }

            $bestBeforeRaw = trim((string) ($sources['BestBeforeEnd'] ?? ''));

            if ($bestBeforeRaw !== '') {
                if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $bestBeforeRaw) === 1) {
                    return Carbon::createFromFormat('d/m/Y', $bestBeforeRaw)->endOfDay();
                }

                if (preg_match('/^\d{2}\/\d{4}$/', $bestBeforeRaw) === 1) {
                    return Carbon::createFromFormat('m/Y', $bestBeforeRaw)->endOfMonth();
                }

                return Carbon::parse($bestBeforeRaw)->endOfDay();
            }
        } catch (\Throwable) {
            // Fall through to configured shelf-life fallback.
        }

        $shelfDays = (int) ($batch->product?->shelf_life_days ?? 180);

        return $fallbackBase->copy()->addDays($shelfDays)->endOfMonth();
    }
}
