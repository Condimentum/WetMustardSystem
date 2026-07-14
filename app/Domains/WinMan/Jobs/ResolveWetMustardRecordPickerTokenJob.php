<?php

namespace App\Domains\WinMan\Jobs;

use App\Domains\WinMan\Support\WinManConnection;

/**
 * Resolves BarTender record-picker token (for example: "15...") for a
 * ProductId using the same ordering as the Wet Mustard label dataset.
 */
class ResolveWetMustardRecordPickerTokenJob
{
    public function __construct(
        private readonly WinManConnection $winMan,
    ) {
    }

    public function __invoke(?string $productId): ?string
    {
        $productId = trim((string) $productId);

        if ($productId === '') {
            return null;
        }

        $sql = "
            WITH product_rows AS (
                SELECT DISTINCT
                    P.Product,
                    P.ProductId
                FROM Products AS P
                LEFT JOIN wv_ProductsUDFs AS PR ON P.Product = PR.Identifier
                WHERE (P.Classification = 29 OR P.Classification = 30)
                  AND (P.Barcode <> '' OR P.Classification = 30)
                  AND ISNULL(PR.AllergenInfo, '') <> ''
            )
            SELECT RowNo
            FROM (
                SELECT
                    Product,
                    ProductId,
                    ROW_NUMBER() OVER (ORDER BY Product ASC) AS RowNo
                FROM product_rows
            ) AS ranked
            WHERE ranked.ProductId = ?
        ";

        $row = $this->winMan->connection()->selectOne($sql, [$productId]);

        if ($row === null || ! isset($row->RowNo)) {
            return null;
        }

        return (string) $row->RowNo.'...';
    }
}
