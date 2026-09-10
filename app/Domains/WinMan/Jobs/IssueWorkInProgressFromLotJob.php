<?php

namespace App\Domains\WinMan\Jobs;

use App\Domains\WinMan\Exceptions\WinManException;
use App\Domains\WinMan\Support\WinManConnection;
use PDO;

/**
 * Issues a specific quantity from a selected lot against a specific WinMan
 * WorkInProgress line using approved WinMan procedures.
 *
 * @return array{issued_quantity: float, issued_inventory_ids: array<int, int>, supplier_lot_number: ?string}
 */
class IssueWorkInProgressFromLotJob
{
    public function __construct(
        private readonly WinManConnection $winman,
    ) {
    }

    /**
     * @param  array{work_in_progress: int, component_product: int, lot_number: string, quantity: float, user_name: string}  $params
        * @return array{issued_quantity: float, issued_inventory_ids: array<int, int>, supplier_lot_number: ?string}
     */
    public function __invoke(array $params): array
    {
        $workInProgress = (int) $params['work_in_progress'];
        $componentProduct = (int) $params['component_product'];
        $lotNumber = trim((string) $params['lot_number']);
        $quantity = (float) $params['quantity'];
        $userName = trim((string) $params['user_name']) !== ''
            ? trim((string) $params['user_name'])
            : 'DBMTS';

        if ($workInProgress <= 0) {
            throw new WinManException('A valid WorkInProgress line is required to issue to WinMan.');
        }

        if ($componentProduct <= 0) {
            throw new WinManException('A valid WinMan component product is required to issue to WinMan.');
        }

        if ($lotNumber === '') {
            throw new WinManException('A lot number is required to issue to WinMan.');
        }

        if ($quantity <= 0) {
            throw new WinManException('Issue quantity must be greater than zero.');
        }

        /** @var \Illuminate\Database\Connection $connection */
        $connection = $this->winman->connection();
        $inventoryIssueProcedure = (string) config('winman.issue.inventory_issue_procedure', 'wsp_InventoryIssue');
        $nonWmGoProcedure = (string) config('winman.issue.non_wmgo_procedure', 'bsp_ManufacturingOrdersIssueNonWMGO');
        $runNonWmGoAfterIssue = (bool) config('winman.issue.run_non_wmgo_after_issue', false);
        $statusOnlyProcedure = (string) config('winman.issue.status_only_procedure', 'bsp_ManufacturingOrdersSetIssuedStatus');
        $runStatusOnlyAfterIssue = (bool) config('winman.issue.run_status_only_after_issue', false);

        $context = $connection->selectOne(
            'SELECT TOP (1)
                w.WorkInProgress,
                w.QuantityOutstanding,
                mo.ManufacturingOrder,
                mo.Site,
                mo.LastModifiedDate
             FROM WorkInProgress w
             INNER JOIN ManufacturingOrders mo ON mo.ManufacturingOrder = w.ManufacturingOrder
             WHERE w.WorkInProgress = ?',
            [$workInProgress],
        );

        if ($context === null) {
            throw new WinManException('WinMan WorkInProgress line could not be found.');
        }

        $quantityOutstanding = (float) ($context->QuantityOutstanding ?? 0);
        if ($quantityOutstanding <= 0.0001) {
            throw new WinManException('No outstanding quantity remains for this BOM line in WinMan.');
        }

        // Cap to the live WinMan outstanding quantity so operators can keep
        // allocating incrementally without hard failures after partial issues.
        $quantity = min($quantity, $quantityOutstanding);

        $site = (int) ($context->Site ?? 0);
        $manufacturingOrder = (int) ($context->ManufacturingOrder ?? 0);
        $lastModifiedDate = (string) ($context->LastModifiedDate ?? '');

        $inventoryRows = $connection->select(
            'SELECT i.Inventory, i.QuantityOutstanding, i.SupplierLotNumber
             FROM Inventory i
             WHERE i.Product = ?
               AND i.LotNumber = ?
               AND i.QuantityOutstanding > 0
             ORDER BY i.ExpiryDate ASC, i.Inventory ASC',
            [$componentProduct, $lotNumber],
        );

        if ($inventoryRows === []) {
            throw new WinManException('No available WinMan inventory rows were found for the selected lot.');
        }

        $issuedInventoryIds = [];
        $issuedSupplierLots = [];
        $issuedQuantity = 0.0;

        $connection->transaction(function () use (
            $connection,
            $inventoryRows,
            $quantity,
            $workInProgress,
            $site,
            $manufacturingOrder,
            $lastModifiedDate,
            $userName,
            $inventoryIssueProcedure,
            $nonWmGoProcedure,
            $runNonWmGoAfterIssue,
            $statusOnlyProcedure,
            $runStatusOnlyAfterIssue,
            &$issuedInventoryIds,
            &$issuedSupplierLots,
            &$issuedQuantity,
        ): void {
            $remaining = $quantity;

            foreach ($inventoryRows as $row) {
                if ($remaining <= 0) {
                    break;
                }

                $inventoryId = (int) ($row->Inventory ?? 0);
                $available = (float) ($row->QuantityOutstanding ?? 0);

                if ($inventoryId <= 0 || $available <= 0) {
                    continue;
                }

                $toIssue = min($remaining, $available);

                $pdo = $connection->getPdo();
                $errorMessage = '';

                $statement = $pdo->prepare("{CALL {$inventoryIssueProcedure}(?,?,?,?,?,?,?,?,?,?,?,?)}");
                $statement->bindValue(1, $inventoryId, PDO::PARAM_INT);
                $statement->bindValue(2, $toIssue);
                $statement->bindValue(3, $workInProgress, PDO::PARAM_INT);
                $statement->bindValue(4, $userName);
                $statement->bindParam(5, $errorMessage, PDO::PARAM_STR | PDO::PARAM_INPUT_OUTPUT, 4000);
                // Pass ManualInventory so WinMan can transition the MO from R -> I on first issue.
                $statement->bindValue(6, $inventoryId, PDO::PARAM_INT);
                $statement->bindValue(7, $site, PDO::PARAM_INT);
                $statement->bindValue(8, null, PDO::PARAM_NULL);
                $statement->bindValue(9, 0, PDO::PARAM_INT);
                $statement->bindValue(10, null, PDO::PARAM_NULL);
                $statement->bindValue(11, 1, PDO::PARAM_INT);
                $statement->bindValue(12, 1, PDO::PARAM_INT);
                $statement->execute();

                // Drain any result sets before reading output parameters.
                do {
                    // no-op
                } while ($this->advanceRowset($statement));

                $errorMessage = trim((string) $errorMessage);
                if ($errorMessage !== '') {
                    throw new WinManException("Error when issuing inventory {$inventoryId}: {$errorMessage}");
                }

                $issuedInventoryIds[] = $inventoryId;
                $supplierLot = trim((string) ($row->SupplierLotNumber ?? ''));
                if ($supplierLot !== '') {
                    $issuedSupplierLots[] = $supplierLot;
                }
                $issuedQuantity += $toIssue;
                $remaining -= $toIssue;
            }

            if ($runNonWmGoAfterIssue) {
                $pdo = $connection->getPdo();
                $statement = $pdo->prepare(
                    "DECLARE @RC int;
                     EXEC @RC = dbo.{$nonWmGoProcedure} ?, ?, ?;
                     SELECT @RC AS ReturnCode;"
                );
                $statement->bindValue(1, $manufacturingOrder, PDO::PARAM_INT);
                $statement->bindValue(2, $userName);
                $statement->bindValue(3, $lastModifiedDate);
                $statement->execute();

                $returnCode = 0;
                do {
                    if ($statement->columnCount() <= 0) {
                        continue;
                    }

                    $row = $statement->fetch(PDO::FETCH_ASSOC);
                    if ($row !== false && array_key_exists('ReturnCode', $row)) {
                        $returnCode = (int) $row['ReturnCode'];
                        break;
                    }
                } while ($this->advanceRowset($statement));

                if ($returnCode === -1) {
                    throw new WinManException('Error when issuing Non WM Go components.');
                }
            }

            if ($runStatusOnlyAfterIssue && $statusOnlyProcedure !== '') {
                $pdo = $connection->getPdo();
                $statement = $pdo->prepare(
                    "DECLARE @RC int;
                     EXEC @RC = dbo.{$statusOnlyProcedure} ?, ?, ?;
                     SELECT @RC AS ReturnCode;"
                );
                $statement->bindValue(1, $manufacturingOrder, PDO::PARAM_INT);
                $statement->bindValue(2, $userName);
                $statement->bindValue(3, null, PDO::PARAM_NULL);
                $statement->execute();

                $returnCode = 0;
                do {
                    if ($statement->columnCount() <= 0) {
                        continue;
                    }

                    $row = $statement->fetch(PDO::FETCH_ASSOC);
                    if ($row !== false && array_key_exists('ReturnCode', $row)) {
                        $returnCode = (int) $row['ReturnCode'];
                        break;
                    }
                } while ($this->advanceRowset($statement));

                if ($returnCode === -1) {
                    throw new WinManException('Error when setting MO status to Issued in WinMan.');
                }
            }
        });

        $issuedQuantity = round($issuedQuantity, 5);
        if ($issuedQuantity <= 0) {
            throw new WinManException('No available quantity could be issued from the selected lot.');
        }

        return [
            'issued_quantity' => $issuedQuantity,
            'issued_inventory_ids' => array_values(array_unique($issuedInventoryIds)),
            'supplier_lot_number' => ($supplierLots = array_values(array_unique($issuedSupplierLots))) !== []
                ? implode(', ', $supplierLots)
                : null,
        ];
    }

    private function advanceRowset(\PDOStatement $statement): bool
    {
        try {
            return $statement->nextRowset();
        } catch (\PDOException) {
            return false;
        }
    }
}
