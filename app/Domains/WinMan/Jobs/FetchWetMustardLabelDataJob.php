<?php

namespace App\Domains\WinMan\Jobs;

use App\Domains\WinMan\Support\WinManConnection;

/**
 * Fetches Wet Mustard label fields from WinMan using the approved left-join
 * query shape (including nutrition and Kosher/Halal pivots).
 */
class FetchWetMustardLabelDataJob
{
    public function __construct(
        private readonly WinManConnection $winMan,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $productInternal = null, ?string $productId = null): array
    {
        if ($productInternal === null && ($productId === null || $productId === '')) {
            return [];
        }

        $identifierWhere = '';
        $bindings = [];

        if ($productInternal !== null) {
            $identifierWhere = ' AND P.Product = ?';
            $bindings[] = $productInternal;
        } else {
            $identifierWhere = ' AND P.ProductId = ?';
            $bindings[] = $productId;
        }

        $sql = "
            SELECT
                sub.Product,
                sub.ProductId,
                sub.ProductDescription,
                sub.Barcode,
                sub.CountryId,
                sub.Weight,
                sub.AltReferences,
                sub.HandInstruct,
                sub.AllergenInfo,
                sub.BBEformat,
                sub.BBE,
                sub.Ingredients,
                MAX(CASE WHEN sub.TechnicalInformationTabRow = 1 THEN sub.ValueDecimal END) AS EnergyKJ,
                MAX(CASE WHEN sub.TechnicalInformationTabRow = 2 THEN sub.ValueDecimal END) AS EnergyKcal,
                MAX(CASE WHEN sub.TechnicalInformationTabRow = 3 THEN sub.ValueDecimal END) AS Protein,
                MAX(CASE WHEN sub.TechnicalInformationTabRow = 4 THEN sub.ValueDecimal END) AS TotalCarbohydrates,
                MAX(CASE WHEN sub.TechnicalInformationTabRow = 5 THEN sub.ValueDecimal END) AS OfWhichSugar,
                MAX(CASE WHEN sub.TechnicalInformationTabRow = 7 THEN sub.ValueDecimal END) AS Fibre,
                MAX(CASE WHEN sub.TechnicalInformationTabRow = 9 THEN sub.ValueDecimal END) AS Fat,
                MAX(CASE WHEN sub.TechnicalInformationTabRow = 10 THEN sub.ValueDecimal END) AS Saturates,
                MAX(CASE WHEN sub.TechnicalInformationTabRow = 15 THEN sub.ValueDecimal END) AS Salt,
                MAX(CASE WHEN sub.TechnicalInformationTabRow = 46 THEN CAST(sub.ValueBit AS INT) ELSE NULL END) AS Kosher,
                MAX(CASE WHEN sub.TechnicalInformationTabRow = 47 THEN CAST(sub.ValueBit AS INT) ELSE NULL END) AS Halal
            FROM (
                SELECT
                    P.Product,
                    P.ProductId,
                    P.ProductDescription,
                    P.Barcode,
                    C.CountryId,
                    CAST(P.DC_008_DEC AS decimal(10,1)) AS Weight,
                    PR.AltReferences,
                    PR.HandInstruct,
                    PR.AllergenInfo,
                    PR.Ingredients,
                    TV.ValueNvarchar,
                    TV.ValueBit,
                    TV.ValueDecimal,
                    TR.RowId,
                    TR.TechnicalInformationTab,
                    TV.TechnicalInformationTabRow,
                    TV.TechnicalInformationTabColumn,
                    P.ShelfLife AS BBE,
                    CASE
                        WHEN P.ShelfLifeUnit = 'M' THEN 'MMYYYY'
                        WHEN P.ShelfLifeUnit = 'D' THEN 'DDMMYYYY'
                    END AS BBEformat
                FROM Products AS P
                LEFT JOIN Countries AS C ON P.CountryOfOrigin = C.Country
                LEFT JOIN TechnicalInformationValues AS TV ON TV.Product = P.Product
                LEFT JOIN TechnicalInformationTabRows AS TR ON TR.TechnicalInformationTabRow = TV.TechnicalInformationTabRow
                LEFT JOIN wv_ProductsUDFs AS PR ON P.Product = PR.Identifier
                WHERE (P.Classification = 29 OR P.Classification = 30)
                  AND (P.Barcode <> '' OR P.Classification = 30)
                  AND ISNULL(PR.AllergenInfo, '') <> ''
                  {$identifierWhere}
            ) AS sub
            GROUP BY
                sub.Product,
                sub.ProductId,
                sub.ProductDescription,
                sub.Barcode,
                sub.CountryId,
                sub.Weight,
                sub.AltReferences,
                sub.HandInstruct,
                sub.AllergenInfo,
                sub.Ingredients,
                sub.BBEformat,
                sub.BBE
            ORDER BY sub.Product ASC
        ";

        $row = $this->winMan->connection()->selectOne($sql, $bindings);

        if ($row === null) {
            return [];
        }

        return (array) $row;
    }
}
