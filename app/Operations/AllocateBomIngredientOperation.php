<?php

namespace App\Operations;

use App\Domains\Booking\Jobs\RecordWinManIssueLogJob;
use App\Domains\ManufacturingOrder\Jobs\StoreComponentSnapshotJob;
use App\Domains\WinMan\Exceptions\WinManException;
use App\Domains\WinMan\Jobs\FetchManufacturingOrderComponentsJob;
use App\Domains\WinMan\Jobs\IssueWorkInProgressFromLotJob;
use App\Features\Batches\AddIngredientLotFeature;
use App\Models\BatchIngredientLot;
use App\Models\BatchRecord;
use App\Models\User;
use App\Models\WinManIssueLog;
use App\Models\WinManMoComponentSnapshot;

class AllocateBomIngredientOperation
{
    public function __construct(
        private readonly IssueWorkInProgressFromLotJob $issueWorkInProgress,
        private readonly AddIngredientLotFeature $addIngredientLot,
        private readonly FetchManufacturingOrderComponentsJob $fetchComponents,
        private readonly StoreComponentSnapshotJob $storeComponentSnapshot,
        private readonly RecordWinManIssueLogJob $recordIssueLog,
    ) {
    }

    public function __invoke(
        BatchRecord $batch,
        WinManMoComponentSnapshot $component,
        string $lotNumber,
        float $quantity,
        ?User $user = null,
    ): BatchIngredientLot {
        if ((int) $component->manufacturing_order_id !== (int) $batch->manufacturing_order_id) {
            throw new WinManException('The selected BOM line does not belong to this batch.');
        }

        if ((int) ($component->winman_work_in_progress ?? 0) <= 0) {
            ($this->recordIssueLog)($this->baseLog($batch, $component, $lotNumber, $quantity, $user) + [
                'issue_status' => WinManIssueLog::STATUS_REJECTED,
                'error_message' => 'BOM line does not contain WorkInProgress linkage. Refresh the MO snapshot and try again.',
            ]);

            throw new WinManException('This BOM line is missing WorkInProgress linkage. Refresh the MO snapshot and try again.');
        }

        $userName = trim((string) ($user?->name ?? config('winman.issue.user_name', config('winman.booking.user_name', 'DBMTS'))));

        try {
            $issueResult = ($this->issueWorkInProgress)([
                'work_in_progress' => (int) $component->winman_work_in_progress,
                'component_product' => (int) $component->winman_component_product,
                'lot_number' => $lotNumber,
                'quantity' => $quantity,
                'user_name' => $userName,
            ]);
        } catch (WinManException $e) {
            ($this->recordIssueLog)($this->baseLog($batch, $component, $lotNumber, $quantity, $user) + [
                'issue_status' => WinManIssueLog::STATUS_REJECTED,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        } catch (\Throwable $e) {
            ($this->recordIssueLog)($this->baseLog($batch, $component, $lotNumber, $quantity, $user) + [
                'issue_status' => WinManIssueLog::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            throw new WinManException('WinMan issue failed: '.$e->getMessage());
        }

        $lot = ($this->addIngredientLot)($batch, [
            'material_code' => (string) $component->winman_component_product_id,
            'material_description' => (string) $component->component_description,
            'lot_number' => $lotNumber,
            'supplier_lot_number' => $issueResult['supplier_lot_number'] ?? null,
            'actual_quantity' => (float) ($issueResult['issued_quantity'] ?? 0),
            'uom' => 'kg',
        ], $user);

        ($this->recordIssueLog)(array_merge($this->baseLog($batch, $component, $lotNumber, $quantity, $user), [
            'batch_ingredient_lot_id' => $lot->id,
            'winman_inventory_ids' => $issueResult['issued_inventory_ids'],
            'quantity_issued' => (float) ($issueResult['issued_quantity'] ?? $quantity),
            'issue_status' => WinManIssueLog::STATUS_SUCCESS,
        ]));

        $order = $batch->manufacturingOrder;
        if ($order !== null && ! empty($order->winman_manufacturing_order)) {
            $components = ($this->fetchComponents)((int) $order->winman_manufacturing_order);
            ($this->storeComponentSnapshot)($order, $components);
        }

        return $lot;
    }

    /**
     * @return array<string, mixed>
     */
    private function baseLog(
        BatchRecord $batch,
        WinManMoComponentSnapshot $component,
        string $lotNumber,
        float $quantity,
        ?User $user,
    ): array {
        return [
            'batch_record_id' => $batch->id,
            'component_snapshot_id' => $component->id,
            'winman_work_in_progress' => $component->winman_work_in_progress,
            'winman_manufacturing_order' => $component->winman_manufacturing_order,
            'material_code' => $component->winman_component_product_id,
            'lot_number' => $lotNumber,
            'quantity_issued' => $quantity,
            'issue_user' => $user?->name ?? config('winman.issue.user_name', config('winman.booking.user_name', 'DBMTS')),
        ];
    }
}
