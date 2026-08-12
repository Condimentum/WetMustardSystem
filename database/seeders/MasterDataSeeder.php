<?php

namespace Database\Seeders;

use App\Models\DocumentReference;
use App\Models\DocumentReferenceChange;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeVariant;
use Illuminate\Database\Seeder;

/**
 * Seeds DBMTS master data that is stated as fact in the functional spec (§3).
 *
 * Sourced only from the scope document. WinMan identifiers (finished_goods_code,
 * winman_product_id, pack size) and batch sizes not documented in scope are left
 * null pending WM024 (Wet Mustard Product Codes) and the batchcard PDFs.
 */
class MasterDataSeeder extends Seeder
{
    /**
     * Controlled source documents (scope §3). [code => [title, module]].
     */
    private const DOCUMENTS = [
        'WM001' => ['Lab Scales Daily Calibration', 'Calibration'],
        'WM002' => ['Daily Salt Meter Calibration', 'Calibration'],
        'WM003' => ['Vinegar IBC Traceability', 'Traceability'],
        'WM005' => ['Wet Mustard Lab Testing', 'Lab Testing'],
        'WM006' => ['Viscosity Meter Autozero Check Complete', 'Calibration'],
        'WM010' => ['Rinse Water Test Sheet - Chemical & Sulphite', 'Cleaning Verification'],
        'WM012' => ['Wet Mustard Bucket Processing - 5kg', 'Bucket Packing'],
        'WM013' => ['Production Scales Daily Calibration', 'Calibration'],
        'WM004' => ['Wet Mustard Pallecon Filling / Processing', 'Pallecon Filling'],
        'WM011' => ['Metal Detector Verification Sheet', 'Metal Detector'],
        'WM014' => ['Primary Packaging Records - Drums', 'Packaging Traceability'],
        'WM015' => ['Primary Packaging Records - Buckets & Lids', 'Packaging Traceability'],
        'WM016' => ['Wet Mustard Bucket Processing 10kg', 'Bucket Packing'],
        'WM018' => ['30010004 Kerry Dusseldorf Mustard Batchcard', 'Batchcard'],
        'WM019' => ['30010002 Condi American Mustard CPM001A Batchcard', 'Batchcard'],
        'WM020' => ['30010001 Condi Wholegrain Mustard CPM001WG Batchcard', 'Batchcard'],
        'WM021' => ['30010009 Condi English Mustard CPM001E Batchcard', 'Batchcard'],
        'WM022' => ['30010010 Condi Dijon Mustard CPM001 Batchcard', 'Batchcard'],
        'WM023' => ['30010003 Condi Dijon Style Mustard CPM001DS Batchcard', 'Batchcard'],
        'WM024' => ['Wet Mustard Product Codes', 'Product Master'],
        'WM025' => ['30010018 Dijon Mustard CPM002 Batchcard', 'Batchcard'],
        'WM034' => ['30010016 BPS Poshdog Mustard Batchcard', 'Batchcard'],
        'WM036' => ['30010019 Condi French Mustard Batchcard', 'Batchcard'],
        'WM039' => ['30010023 American Style Mustard CSS CPM004A Batchcard', 'Batchcard'],
        'WM042' => ['30010025 BPS M&S English Mustard Batchcard', 'Batchcard'],
        'WM044' => ['30010022 American Style Mustard NAS CPM003A Batchcard', 'Batchcard'],
        'WM045' => ['30010023 American Style Mustard CSS CPM004A Batchcard 500kg', 'Batchcard'],
        'WM046' => ['Wet Mustard Drum Processing', 'Drum Processing'],
        'WM047' => ['Primary Packaging Records - Buckets & Lids (NVE)', 'Packaging Traceability'],
        'WM048' => ['30010026 Table Mustard CPM001M Batchcard 800kg', 'Batchcard'],
        'WM049' => ['30010026 Table Mustard CPM001M Batchcard 400kg', 'Batchcard'],
    ];

    /**
     * Real revision metadata transcribed from the physical WM document masters.
     * [code => [version, issue_date Y-m-d, reason_for_issue, issued_by]].
     */
    private const DOCUMENT_REVISIONS = [
        'WM001' => ['1', '2023-01-31', '1st Issue', 'T Boyce'],
        'WM002' => ['2', '2024-01-31', 'Amended Title', 'T Boyce'],
        'WM003' => ['1', '2023-01-31', '1st Issue', 'T Boyce'],
        'WM005' => ['4', '2026-07-13', 'Added acidity as citric column', 'T Boyce'],
        'WM006' => ['1', '2023-03-21', '1st Issue', 'T Boyce'],
        'WM010' => ['3', '2025-07-11', 'Added mg/ltr for sulphite testing and chemical titration check box', 'T Boyce'],
        'WM012' => ['1', '2023-12-19', 'First issue', 'T Boyce'],
        'WM013' => ['2', '2024-03-27', 'Updated the tolerance for the Pallecon scale', 'T Boyce'],
    ];

    /**
     * Products / recipes (scope §3). [recipe_code => [product_name, source_doc]].
     */
    private const PRODUCTS = [
        '30010004' => ['Kerry Dusseldorf Mustard', 'WM018'],
        '30010002' => ['Condi American Mustard CPM001A', 'WM019'],
        '30010001' => ['Condi Wholegrain Mustard CPM001WG', 'WM020'],
        '30010009' => ['Condi English Mustard CPM001E', 'WM021'],
        '30010010' => ['Condi Dijon Mustard CPM001', 'WM022'],
        '30010003' => ['Condi Dijon Style Mustard CPM001DS', 'WM023'],
        '30010018' => ['Dijon Mustard CPM002', 'WM025'],
        '30010016' => ['BPS Poshdog Mustard', 'WM034'],
        '30010019' => ['Condi French Mustard', 'WM036'],
        '30010023' => ['American Style Mustard CSS CPM004A', 'WM039'],
        '30010025' => ['BPS M&S English Mustard', 'WM042'],
        '30010022' => ['American Style Mustard NAS CPM003A', 'WM044'],
        '30010026' => ['Table Mustard CPM001M', 'WM048'],
    ];

    /**
     * Batch-size variants explicitly documented in scope.
     * [recipe_code => [ [variant_name, batch_size, source_doc], ... ]].
     */
    private const VARIANTS = [
        '30010023' => [['CPM004A 500kg', 500, 'WM045']],
        '30010026' => [['CPM001M 800kg', 800, 'WM048'], ['CPM001M 400kg', 400, 'WM049']],
    ];

    public function run(): void
    {
        foreach (self::DOCUMENTS as $code => [$title, $module]) {
            $attributes = ['title' => $title, 'module' => $module, 'status' => 'Active'];

            [$version, $issueDate, $reason, $issuedBy] = self::DOCUMENT_REVISIONS[$code] ?? [null, null, null, null];
            if ($version !== null) {
                $attributes['version'] = $version;
                $attributes['issue_date'] = $issueDate;
            }

            $document = DocumentReference::updateOrCreate(['code' => $code], $attributes);

            if ($version === null) {
                continue;
            }

            DocumentReferenceChange::updateOrCreate(
                ['document_reference_id' => $document->id, 'issue_version' => $version],
                ['date_issued' => $issueDate, 'issued_by' => $issuedBy, 'reason_for_change' => $reason],
            );
        }

        foreach (self::PRODUCTS as $recipeCode => [$productName, $sourceDoc]) {
            Product::updateOrCreate(
                ['recipe_code' => $recipeCode],
                ['product_name' => $productName, 'active_flag' => true],
            );

            $recipe = Recipe::updateOrCreate(
                ['recipe_code' => $recipeCode, 'revision' => null],
                ['source_document_code' => $sourceDoc, 'active_flag' => true],
            );

            foreach (self::VARIANTS[$recipeCode] ?? [] as [$variantName, $batchSize, $variantDoc]) {
                RecipeVariant::updateOrCreate(
                    ['recipe_code' => $recipeCode, 'variant_name' => $variantName],
                    [
                        'recipe_id' => $recipe->id,
                        'batch_size' => $batchSize,
                        'batch_size_uom' => 'kg',
                        'source_document_code' => $variantDoc,
                        'active_flag' => true,
                    ],
                );
            }
        }
    }
}
